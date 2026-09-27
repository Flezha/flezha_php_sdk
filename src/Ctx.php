<?php

declare(strict_types=1);

namespace Flezha;

use Flezha\Codec\Json;
use Flezha\Exception\EmitException;
use Flezha\Exception\NonRetryableException;
use Flezha\Graph\ChannelSpec;
use Flezha\Graph\ConsumerEdge;
use Flezha\Graph\ClassSpec;
use Flezha\Graph\TaskNode;

/**
 * The invocation context: every deterministic effect a handler is allowed.
 *
 * The context accumulates one item's effects — recorded changes, outputs,
 * timer operations, the checkpoint flag — and the dispatcher turns them into
 * the item effect the core folds. Nothing here reaches the wire on its own; an
 * emit is a value in a list until the completion carries it.
 */
final class Ctx
{
    /** Metadata keys the runtime owns. A handler that sets one is refused. */
    public const RESERVED_PREFIX = 'flz.';

    /** @var list<string> */
    public array $changes = [];
    /** @var list<array<string, mixed>> */
    public array $outputs = [];
    /** @var list<array<string, mixed>> */
    public array $timers = [];

    private bool $checkpointed = false;
    private ?string $completion = null;
    private int $ordinal = 0;
    private int $emitOrdinal = 0;
    private ?string $identity = null;

    /** @param list<ChannelSpec> $outgoing */
    public function __construct(
        private readonly ClassSpec $spec,
        private readonly TaskNode $node,
        private readonly array $outgoing,
        private readonly string $operator,
        public readonly ?string $cellKey,
        private readonly int $changeSeq,
        private readonly int $now,
        public readonly bool $replay,
        public mixed $state,
        private readonly SpawnBridge $bridge,
        private readonly int $token,
        // The input-log offset of the item this invocation runs: a spawn
        // carries it so a crash re-run of the same input resolves to the
        // worker the first run spawned.
        private readonly ?int $offset = null,
        private readonly bool $compensating = false,
    ) {
    }

    /** The core-evaluated declared clock for this item. Never ambient time. */
    public function now(): int
    {
        return $this->now;
    }

    /** A replay-stable identifier, distinct per invocation within the cell. */
    public function stableId(): string
    {
        // Lazily, because most invocations never ask and the identity is the
        // one part of the derivation that costs an allocation.
        $this->identity ??= Identity::cell(
            $this->operator,
            $this->node->name,
            (string) $this->cellKey,
        );

        return Identity::stableId($this->identity, $this->changeSeq, $this->ordinal++);
    }

    /**
     * Record one declared change: journaled, then folded into the state.
     *
     * The fold runs through the same method the restore fold uses, so the live
     * path and the replay path cannot drift.
     */
    public function change(mixed $change): void
    {
        $definition = $this->node->definition;
        if (!$definition->declares('applyChange')) {
            throw new EmitException(sprintf(
                '%s: recorded a change but declares no applyChange; a change that cannot be '
                . 'folded cannot rebuild the state it describes',
                $this->node->name,
            ));
        }

        $this->changes[] = $definition->encode('change', $change);
        $definition->foldChange($this->state, $change);
    }

    /**
     * Emit one output value on the tasklet's declared output.
     *
     * @param array<string, string>|null $metadata
     */
    public function emit(mixed $value, ?string $key = null, ?string $channel = null, ?array $metadata = null): void
    {
        $targets = $channel === null
            ? $this->outgoing
            : array_values(array_filter(
                $this->outgoing,
                static fn (ChannelSpec $c): bool => $c->name === $channel,
            ));

        if ($targets === []) {
            throw new EmitException($channel === null
                ? sprintf(
                    '%s: emits but declares no output channel; wire its edge with into(...) or '
                    . 'sink(...)',
                    $this->node->name,
                )
                : sprintf(
                    '%s: emits on channel `%s`, which it does not produce on',
                    $this->node->name,
                    $channel,
                ));
        }

        foreach ($targets as $target) {
            $this->release($target, $value, $key, $metadata);
        }
    }

    /**
     * Emit what a handler returned, if anything.
     *
     * `null` emits nothing, which is how a handler writes a filter — so a
     * handler that emits through the context and one that returns a value
     * compose rather than conflict. A value returned by a tasklet with no
     * outgoing channel is left alone, as the reference leaves an unconsumed
     * output. One method, because the dispatcher and the test harness must not
     * disagree about it.
     */
    public function emitReturned(mixed $returned): void
    {
        if ($returned !== null && $this->outgoing !== []) {
            $this->emit($returned);
        }
    }

    /**
     * Emit with an explicit routing key — the spelling for a keyed channel.
     *
     * @param array<string, string>|null $metadata
     */
    public function emitKeyed(string $key, mixed $value, ?string $channel = null, ?array $metadata = null): void
    {
        $this->emit($value, $key, $channel, $metadata);
    }

    /**
     * Register a processing-time timer `$milliseconds` from `now`. Answers its time.
     *
     * Inside a `compensate` hook each of the three timer calls throws naming
     * `TimersUnavailable`.
     */
    public function timerAfter(int $milliseconds): int
    {
        $this->refuseTimersInCompensate();
        // Anchored on `now`, never an ambient read: that is what makes a
        // replayed invocation register the timestamp the live one did.
        $at = $this->now + $milliseconds;
        $this->timers[] = ['kind' => 'processing_time', 'scheduled_at' => $at, 'register' => true];

        return $at;
    }

    /** Register an event-time timer at an absolute time. Answers it. */
    public function timerAtEventTime(int $at): int
    {
        $this->refuseTimersInCompensate();
        $this->timers[] = ['kind' => 'event_time', 'scheduled_at' => $at, 'register' => true];

        return $at;
    }

    /** Cancel a registered timer by the value its registration answered. */
    public function cancelTimer(string $kind, int $at): void
    {
        $this->refuseTimersInCompensate();
        $this->timers[] = ['kind' => $kind, 'scheduled_at' => $at, 'register' => false];
    }

    /** Close the open checkpoint interval (checkpoint ack-granularity classes). */
    public function checkpoint(): void
    {
        $this->checkpointed = true;
    }

    /**
     * End this tasklet's bounded instance with `$result` once the current
     * iteration commits, instead of waiting for its next input. The rest of
     * the iteration still runs, and a later call replaces an earlier one. The
     * result travels as a sink naming no codec sends a value: a string as it
     * stands, anything else as JSON, and `null` as no result. In an unbounded
     * operator, which never completes, the item fails.
     */
    public function complete(mixed $result = null): void
    {
        $this->completion = $result === null ? '' : ChannelCodec::plain($result, $this->node->name);
    }

    /**
     * Ask the controller for a bounded instance of `$factoryId`, with
     * `$payload` as its first input. Returns the handle at once: the call never
     * waits, and a refusal reaches only a read of the handle.
     *
     * Without an `$id` the controller generates one and always starts a new
     * instance, so reissuing the call after a lost or timed-out answer starts a
     * second one, and a replay of it answers an empty id. A caller who needs at
     * most one instance, or the id on replay, passes its own `$id`. With one,
     * `$ifRunning` says what a running instance gets: `deliver` (the default)
     * feeds it the payload, `error` leaves it alone and the read throws.
     *
     * Spawning is a side effect of live processing: a replayed invocation sends
     * nothing and its handle answers `skipped_on_replay`. A timer on this same
     * context is registered on replay, because a timer is internal state.
     *
     * `$payload` is an ordinary message on the instance's first entry channel,
     * sent as a sink naming no codec sends a value (a string as it stands,
     * anything else as JSON), so a `Text` or `Json` entry reads it.
     *
     * @throws \InvalidArgumentException for an `$ifRunning` with no `$id`; nothing is sent
     */
    public function spawnBounded(
        string $factoryId,
        mixed $payload,
        ?string $id = null,
        ?string $ifRunning = null,
    ): SpawnHandle {
        if ($ifRunning !== null && ($id ?? '') === '') {
            throw new \InvalidArgumentException(sprintf(
                '%s: spawnBounded was given ifRunning without an id; ifRunning applies to a running '
                . 'instance an id names, and a spawn without an id always starts a new one',
                $this->node->name,
            ));
        }

        return new SpawnHandle($this->bridge, $this->bridge->spawnRequest(
            $this->token,
            $factoryId,
            $id ?? '',
            ChannelCodec::plain($payload, $this->node->name),
            $ifRunning ?? 'deliver',
            $this->replay,
            $this->offset,
        ));
    }

    /**
     * The effect record for this item, as the core folds it.
     *
     * @return array<string, mixed>
     */
    public function effect(?int $consumedOffset): array
    {
        return [
            'verdict' => 'ok',
            'changes' => $this->changes,
            'outputs' => $this->outputs,
            'consumed_offset' => $consumedOffset,
            'event_time' => $this->now,
            'timers' => $this->timers,
            'checkpoint' => $this->checkpointed,
            'complete' => $this->completion,
        ];
    }

    /** Non-retryable: a rollback is no place to arm a deadline, and a retry would fail the same way. */
    private function refuseTimersInCompensate(): void
    {
        if ($this->compensating) {
            throw new NonRetryableException(sprintf(
                'TimersUnavailable: %s runs compensate, where a tasklet cannot register or cancel '
                . 'a timer',
                $this->node->name,
            ));
        }
    }

    /** @param array<string, string>|null $metadata */
    private function release(ChannelSpec $channel, mixed $value, ?string $key, ?array $metadata): void
    {
        // A channel with no consumer releases nothing on the edge path: on a
        // bounded operator that is the plugin-bound terminal, and seeding the next
        // stage from it would hand the driver a terminal output.
        if ($this->spec->structuredBounded()) {
            $first = $channel->consumers[0] ?? null;
            if ($first !== null) {
                $this->outputs[] = [
                    'target' => 'edge',
                    'channel' => $channel->index,
                    'consumer' => $first->tasklet,
                    'plugin_channel' => null,
                    'key' => null,
                    'event_time' => null,
                    'metadata' => [],
                    'payload' => $this->edgePayload($channel, $value),
                ];
            }
        } else {
            foreach ($channel->consumers as $edge) {
                foreach ($this->edgeOutputs($channel, $edge, $value, $key, $metadata) as $output) {
                    $this->outputs[] = $output;
                }
            }
        }

        if ($channel->sink !== null) {
            $this->outputs[] = $this->pluginOutput($channel, $value, $key, $metadata);
        }
    }

    /**
     * One consumer's frames — several when a `flatMap` fanned the value, none
     * when a `filter` dropped it — with that edge's transforms, key and clock.
     *
     * @param array<string, string>|null $metadata
     * @return list<array<string, mixed>>
     */
    private function edgeOutputs(
        ChannelSpec $channel,
        ConsumerEdge $edge,
        mixed $value,
        ?string $key,
        ?array $metadata,
    ): array {
        $definition = $this->spec->tasklets[$edge->tasklet]->definition;
        $outputs = [];

        // Producer-side, in declaration order: the core routes on `(edge, key)`
        // and never evaluates a host closure.
        foreach (Transforms::apply($edge->transforms, $value) as $transformed) {
            $outputs[] = [
                'target' => 'edge',
                'channel' => $channel->index,
                'consumer' => $edge->tasklet,
                'plugin_channel' => null,
                'key' => $key
                    ?? ($edge->keyBy !== null ? (string) ($edge->keyBy)($transformed) : null)
                    ?? $this->cellKey,
                'event_time' => $definition->declares('eventTime')
                    ? $definition->eventTimeOf($transformed)
                    : null,
                'metadata' => $this->metadata($metadata),
                'payload' => $this->edgePayload($channel, $transformed),
            ];
        }

        return $outputs;
    }

    /**
     * The plugin frame, serialized once from the value as emitted: an edge's
     * transforms belong to that edge, so they never reach the sink.
     *
     * @param array<string, string>|null $metadata
     * @return array<string, mixed>
     */
    private function pluginOutput(ChannelSpec $channel, mixed $value, ?string $key, ?array $metadata): array
    {
        $resolved = $key ?? $this->cellKey;
        if ($channel->keyed && $resolved === null) {
            throw new EmitException(sprintf(
                '%s: emits on keyed channel `%s` with no key, and has no cell key of its '
                . 'own to fall back on; pass a key on the emit',
                $this->node->name,
                $channel->name,
            ));
        }

        $pairs = $this->metadata($metadata);
        $this->stampIdentity($pairs, $channel, $resolved);

        return [
            'target' => 'plugin',
            'channel' => null,
            'consumer' => null,
            'plugin_channel' => $channel->name,
            'key' => $resolved,
            'event_time' => null,
            'metadata' => $pairs,
            'payload' => $this->pluginPayload($channel, $value),
        ];
    }

    /**
     * The inter-tasklet frame's bytes: the class codec, always. The
     * consuming tasklet decodes with it, so a sink's serializer here would
     * hand it bytes it cannot read.
     */
    private function edgePayload(ChannelSpec $channel, mixed $value): string
    {
        return Json::guard($channel->name, static fn (): string => Json::encode($value));
    }

    /** The plugin frame's bytes: the sink's encoder, which `sink` always sets. */
    private function pluginPayload(ChannelSpec $channel, mixed $value): string
    {
        return ($channel->sinkEncode)($value);
    }

    /**
     * Output identity for one plugin frame, appended after the user's metadata.
     *
     * Written only when the class records no effects: under the tier, and for
     * a durable bounded operator, the controller derives the identity from the durable
     * effect record, and a second answer here would contradict it. The
     * ordinal advances on every plugin output, stamped or not, so hosts of one
     * topology agree on the sequence, and a `flatMap` fan-out produces
     * several outputs from one emit, which is why this runs per output.
     *
     * @param array<string, string> $pairs
     */
    private function stampIdentity(array &$pairs, ChannelSpec $channel, ?string $key): void
    {
        if ($channel->keyed && !$this->spec->recordsEffects()) {
            $pairs['flz.cell'] = sprintf(
                '%s/%s/%s',
                $this->operator,
                $this->node->name,
                (string) $this->cellKey,
            );
            $pairs['flz.seq'] = $this->changeSeq . '.' . $this->emitOrdinal;
            $pairs['flz.key'] = (string) $key;
        }
        $this->emitOrdinal++;
    }

    /**
     * @param array<string, string>|null $metadata
     * @return array<string, string>
     */
    private function metadata(?array $metadata): array
    {
        if ($metadata === null) {
            return [];
        }

        $pairs = [];
        foreach ($metadata as $key => $value) {
            if (str_starts_with((string) $key, self::RESERVED_PREFIX)) {
                throw new EmitException(sprintf(
                    '%s: metadata key `%s` is inside the reserved `%s` namespace, which the '
                    . 'runtime stamps itself (cell identity, sequence, key and offset); choose '
                    . 'a key outside it',
                    $this->node->name,
                    $key,
                    self::RESERVED_PREFIX,
                ));
            }
            $pairs[(string) $key] = (string) $value;
        }

        return $pairs;
    }
}

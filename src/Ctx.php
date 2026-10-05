<?php

declare(strict_types=1);

namespace Flezha;

use Flezha\Codec\Json;
use Flezha\Exception\FlezhaException;
use Flezha\Graph\CallEnd;
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
        private readonly string $relay,
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
        // The index of the work item this invocation runs within its
        // activation, which the core numbers and stamps a part by. A
        // compensation has none.
        private readonly ?int $item = null,
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
            $this->relay,
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
            throw new FlezhaException('runtime.change_without_apply_change', ['tasklet' => $this->node->name]);
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
            throw $channel === null
                ? new FlezhaException('runtime.no_output_channel', ['tasklet' => $this->node->name])
                : new FlezhaException('runtime.channel_not_produced', ['tasklet' => $this->node->name, 'channel' => $channel]);
        }

        foreach ($targets as $target) {
            $this->release($target, $value, $key, $metadata);
        }
    }

    /**
     * Send `$value` as a part: a preview of the output this invocation is
     * producing, which leaves the runner now rather than when the handler
     * returns. It previews the tasklet's own output channel and is encoded as
     * an emit of the same value there would be. The relay's listeners
     * receive it before the output; an output never does, and it is never
     * durable or replayed. Parts are numbered from 0 within each attempt, so
     * a retry starts its preview again.
     *
     * It never waits: a part that cannot leave at once (no connection, a
     * local run, a stream that would block, a frame over the size limit) is
     * dropped, and a replayed input sends none.
     *
     * @throws FlezhaException `part.no_output` when the output channel does not
     *   end in an output (it only feeds another tasklet, or ends in `call`),
     *   `part.outside_invocation` through a context whose invocation returned
     */
    public function part(mixed $value): void
    {
        $channel = $this->outgoing[0] ?? null;
        // A channel with no plugin end has no encoding; the core refuses its
        // part before reading a byte.
        $payload = $channel?->sink !== null ? $this->pluginPayload($channel, $value) : '';
        // A compensation holds no work item, which the core answers as outside
        // any invocation, whatever the index.
        $this->bridge->part($this->token, $this->item ?? 0, $payload);
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
     * Inside a `compensate` hook each of the three timer calls throws
     * `runtime.timers_unavailable`.
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
     * End the instance this tasklet runs in with `$result` once the current
     * iteration commits, instead of waiting for its next input; a flow
     * instance ends once every Cell is idle. The rest of the iteration still
     * runs, and a later call replaces an earlier one. The result travels as an
     * output naming no codec sends a value: a string as it stands, anything else
     * as JSON, and `null` as no result.
     */
    public function complete(mixed $result = null): void
    {
        $this->completion = $result === null ? '' : ChannelCodec::plain($result, "tasklet '{$this->node->name}'");
    }

    /**
     * Ask the controller for a steps instance of `$factoryId`, with
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
     * sent as an output naming no codec sends a value (a string as it stands,
     * anything else as JSON), so a `Text` or `Json` entry reads it.
     *
     * @throws \InvalidArgumentException for an `$ifRunning` with no `$id`; nothing is sent
     */
    public function spawnSteps(
        string $factoryId,
        mixed $payload,
        ?string $id = null,
        ?string $ifRunning = null,
    ): SpawnHandle {
        if ($ifRunning !== null && ($id ?? '') === '') {
            throw new \InvalidArgumentException(sprintf(
                '%s: spawnSteps was given ifRunning without an id; ifRunning applies to a running '
                . 'instance an id names, and a spawn without an id always starts a new one',
                $this->node->name,
            ));
        }

        return new SpawnHandle($this->bridge, $this->bridge->spawnRequest(
            $this->token,
            $factoryId,
            $id ?? '',
            ChannelCodec::plain($payload, "tasklet '{$this->node->name}'"),
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

    /** A rollback is no place to arm a deadline. */
    private function refuseTimersInCompensate(): void
    {
        if ($this->compensating) {
            throw new FlezhaException('runtime.timers_unavailable');
        }
    }

    /** @param array<string, string>|null $metadata */
    private function release(ChannelSpec $channel, mixed $value, ?string $key, ?array $metadata): void
    {
        // In steps a value rides the edge path to its first consumer, and an
        // inter-tasklet terminal's value to the core as the pass's handoff (a
        // chained loop's carry, whose consumer the core does not read); a
        // plugin-bound terminal releases nothing there.
        if ($this->spec->runsInSteps()) {
            $consumer = ($channel->consumers[0] ?? null)?->tasklet
                ?? ($channel->kind === 'inter_task' && $channel->sink === null && $channel->call === null
                    ? $channel->producer
                    : null);
            if ($consumer !== null) {
                $this->outputs[] = [
                    'target' => 'edge',
                    'channel' => $channel->index,
                    'consumer' => $consumer,
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
        if ($channel->call !== null) {
            foreach ($this->callOutputs($channel, $channel->call, $value, $metadata) as $output) {
                $this->outputs[] = $output;
            }
        }
    }

    /**
     * The requests a call end sends, with the call chain's transforms and key
     * applied here as an edge's are; the core stamps the call onto each.
     *
     * @param array<string, string>|null $metadata
     * @return list<array<string, mixed>>
     */
    private function callOutputs(ChannelSpec $channel, CallEnd $call, mixed $value, ?array $metadata): array
    {
        $outputs = [];
        foreach (Transforms::apply($call->transforms, $value) as $request) {
            $outputs[] = [
                'target' => 'plugin',
                'channel' => null,
                'consumer' => null,
                'plugin_channel' => $channel->name,
                'key' => $call->keyBy !== null ? (string) ($call->keyBy)($request) : null,
                'event_time' => null,
                'metadata' => $this->metadata($metadata),
                'payload' => ($call->encode)($request),
            ];
        }

        return $outputs;
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
     * transforms belong to that edge, so they never reach the output.
     *
     * @param array<string, string>|null $metadata
     * @return array<string, mixed>
     */
    private function pluginOutput(ChannelSpec $channel, mixed $value, ?string $key, ?array $metadata): array
    {
        $resolved = $key ?? $this->cellKey;
        if ($channel->keyed && $resolved === null) {
            throw new FlezhaException('runtime.key_required_on_keyed_channel', ['channel' => $channel->name]);
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
     * consuming tasklet decodes with it, so an output's serializer here would
     * hand it bytes it cannot read.
     */
    private function edgePayload(ChannelSpec $channel, mixed $value): string
    {
        return Json::guard($channel->name, static fn (): string => Json::encode($value));
    }

    /** The plugin frame's bytes: the output's encoder, which `output` always sets. */
    private function pluginPayload(ChannelSpec $channel, mixed $value): string
    {
        return ($channel->sinkEncode)($value);
    }

    /**
     * Output identity for one plugin frame, appended after the user's metadata.
     *
     * Written only when the class records no effects: under the tier, and for
     * a durable steps relay, the controller derives the identity from the durable
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
                $this->relay,
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
                throw new FlezhaException('runtime.reserved_metadata_key', ['key' => (string) $key]);
            }
            $pairs[(string) $key] = (string) $value;
        }

        return $pairs;
    }
}

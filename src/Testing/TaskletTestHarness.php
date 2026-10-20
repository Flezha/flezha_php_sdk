<?php

declare(strict_types=1);

namespace Flezha\Testing;

use Flezha\ChannelBuilder;
use Flezha\ChannelCodec;
use Flezha\Codec\Json;
use Flezha\Ctx;
use Flezha\Definition;
use Flezha\Dispatch\Dispatcher;
use Flezha\Dispatch\RegisteredClass;
use Flezha\Dispatch\Restore;
use Flezha\Graph\ClassSpec;
use Flezha\Graph\TaskNode;
use Flezha\Relay;
use Flezha\Tasklet;
use Flezha\TimerFiring;

/**
 * Driving one tasklet without a controller, for the author's own tests.
 *
 * Not a second implementation of the dispatcher: it drives
 * {@see Dispatcher::invoke()} — the function every activation kind that
 * reaches a handler goes through — over a one-tasklet `RegisteredClass`, and
 * {@see replayEquals()} folds through {@see Restore::fold()}, the
 * function a real restore calls. So what it proves is what the runner does,
 * rather than something that agrees with it.
 *
 * A `Ctx::effect` keeps its result on a live {@see feed()}; the n-th
 * `feed(..., replay: true)` replays the n-th live input and is served what it
 * kept, and throws {@see EffectReplayMismatch} when a function runs there or
 * a kept result is never asked for.
 */
final class TaskletTestHarness
{
    private readonly Definition $definition;
    private readonly TaskNode $node;
    private readonly RegisteredClass $registered;
    private readonly FakeCore $core;

    /** @var list<string> the encoded change log, as the WAL would hold it */
    public array $log = [];

    private int $changeSeq = 0;

    /** @param class-string<Tasklet>|class-string<Relay> $taskletClass */
    public function __construct(
        string $taskletClass,
        private readonly ?string $cellKey = null,
        private readonly string $relay = 'harness',
    ) {
        $this->definition = Definition::of($taskletClass);
        $this->core = new FakeCore($this->definition->name);

        // The real channel builder, not a hand-written spec: one entry channel
        // into the tasklet, and one plugin output so `ctx->emit` has somewhere
        // to go. A tasklet under test emits to be observed, not to be routed,
        // and the output names JSON so `outputs` reports exactly the value a
        // handler emitted.
        $builder = new ChannelBuilder('<harness>');
        $builder->input('in')->into($taskletClass)->output('out', codec: ChannelCodec::Json);
        $this->node = $builder->tasklets[0];

        $spec = new ClassSpec(
            factoryId: '<harness>',
            description: null,
            maxDurationMs: null,
            run: null,
            durable: null,
            tasklets: $builder->tasklets,
            channels: $builder->channels,
        );

        // The dispatcher's own cell store, so the state this harness reports
        // is the state a runner would hold.
        $this->registered = new RegisteredClass($spec);
        // No core runs here, so the harness names the cell's identity itself.
        $this->registered->keepIdentity($this->node, $relay, $cellKey, "$relay/{$this->node->name}/$cellKey");
    }

    /** The cell's live state, as the dispatcher left it. */
    public function state(): mixed
    {
        return $this->registered->stateOf($this->node, $this->relay, $this->cellKey);
    }

    /** Run the tasklet against one input; `$replay` replays the next live input, in order. */
    public function feed(mixed $input, int $now = 0, bool $replay = false): Outcome
    {
        $this->core->beginInput($replay);
        $outcome = $this->drive(
            $now,
            $replay,
            fn (Ctx $ctx) => $this->definition->instance->run($input, $ctx),
        );
        $this->core->endInput();

        return $outcome;
    }

    /** Fire a per-cell timer. `now` IS the scheduled time, as it is live. */
    public function fireTimer(string $kind, int $scheduledAt): Outcome
    {
        return $this->drive($scheduledAt, false, function (Ctx $ctx) use ($kind, $scheduledAt): void {
            if ($this->definition->declares('onTimer')) {
                $this->definition->instance->onTimer(new TimerFiring($kind, $scheduledAt), $ctx);
            }
        });
    }

    /**
     * Run the declared compensation over recorded forward outputs.
     *
     * @param list<mixed> $outputs
     */
    public function compensate(array $outputs): Outcome
    {
        return $this->drive(0, false, function (Ctx $ctx) use ($outputs): void {
            if ($this->definition->declares('compensate')) {
                $this->definition->instance->compensate($outputs, $ctx);
            }
        });
    }

    /**
     * Rebuild the state the way a restore does, from a snapshot and a change
     * log — the same call the dispatcher answers `restore_fold` with.
     *
     * @param list<string> $changes
     */
    public function restore(?string $snapshot = null, array $changes = []): void
    {
        $this->registered->setState($this->node, $this->relay, $this->cellKey, Restore::fold(
            $this->definition,
            [
                'snapshot' => $snapshot === null ? null : ['bytes' => $snapshot, 'from_version' => null],
                'changes' => array_map(
                    static fn (string $bytes): array => ['bytes' => $bytes, 'from_version' => null],
                    $changes,
                ),
            ],
            $this->cellKey,
            $this->definition->name,
        ));
    }

    /**
     * Fold every recorded change into a fresh initial state and require the
     * answer to equal the live one.
     *
     * PHP has no cheap deep read-only view of a state value — the Node SDK's
     * guard is a `Proxy` and there is no counterpart here that does not cost
     * an allocation per field read — so this is what makes the
     * mutate-only-through-changes rule safe. It catches both failures at once:
     * a handler that mutated the state without recording a change, and an
     * `applyChange` that is not a pure fold.
     */
    public function replayEquals(): void
    {
        $replayed = Restore::fold(
            $this->definition,
            ['changes' => array_map(
                static fn (string $bytes): array => ['bytes' => $bytes, 'from_version' => null],
                $this->log,
            )],
            $this->cellKey,
            $this->definition->name,
        );

        if ($replayed != $this->state()) {
            throw new ReplayMismatch($this->definition->name, $this->state(), $replayed);
        }
    }

    private function drive(int $now, bool $replay, callable $body): Outcome
    {
        try {
            $ctx = Dispatcher::invoke(
                [
                    'token' => 0,
                    'relay' => $this->relay,
                    'cell_key' => $this->cellKey,
                    'change_seq' => $this->changeSeq,
                ],
                $this->registered,
                $this->node,
                $this->core,
                $now,
                $replay,
                $body,
            );
        } finally {
            $this->core->endInvocation();
        }

        array_push($this->log, ...$ctx->changes);
        $this->changeSeq += count($ctx->changes);

        return new Outcome(
            outputs: array_map(
                static fn (array $output): mixed => Json::decode((string) $output['payload']),
                $ctx->outputs,
            ),
            changes: array_map($this->decodeChange(...), $ctx->changes),
            timers: $ctx->timers,
        );
    }

    private function decodeChange(string $bytes): mixed
    {
        return $this->definition->decode('change', $bytes);
    }
}

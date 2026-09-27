<?php

declare(strict_types=1);

namespace Flezha\Testing;

use Flezha\BeyondWindow;
use Flezha\ChannelCodec;
use Flezha\CheckpointOutputs;
use Flezha\Codec\Json;
use Flezha\Ctx;
use Flezha\Definition;
use Flezha\Dispatch\Dispatcher;
use Flezha\Dispatch\RegisteredClass;
use Flezha\Dispatch\Restore;
use Flezha\Exception\FlezhaException;
use Flezha\Graph\Builder;
use Flezha\Graph\ClassSpec;
use Flezha\Graph\TaskNode;
use Flezha\Operator;
use Flezha\Tasklet;
use Flezha\SpawnBridge;
use Flezha\Timer;

/**
 * Driving one tasklet without a controller, for the author's own tests.
 *
 * Not a second implementation of the dispatcher: it drives
 * {@see Dispatcher::invoke()} — the function every activation kind that
 * reaches a handler goes through — over a one-tasklet `RegisteredClass`, and
 * {@see assertReplayEquals()} folds through {@see Restore::fold()}, the
 * function a real restore calls. So what it proves is what the runner does,
 * rather than something that agrees with it.
 */
final class Harness implements SpawnBridge
{
    /** The lowercase base32 a generated id's suffix is spelled in. */
    private const BASE32 = 'abcdefghijklmnopqrstuvwxyz234567';

    private readonly Definition $definition;
    private readonly TaskNode $node;
    private readonly RegisteredClass $registered;

    /** @var list<string> the encoded change log, as the WAL would hold it */
    public array $log = [];

    private int $changeSeq = 0;

    /** @var array<int, string> each spawn's answer by handle number, released when its invocation ends */
    private array $spawns = [];

    private int $spawnCount = 0;

    /** @param class-string<Tasklet>|class-string<Operator> $taskletClass */
    public function __construct(
        string $taskletClass,
        private readonly ?string $cellKey = null,
        private readonly string $operator = 'harness',
    ) {
        $this->definition = Definition::of($taskletClass);

        // The real channel builder, not a hand-written spec: one entry channel
        // into the tasklet, and one plugin sink so `ctx->emit` has somewhere
        // to go. A tasklet under test emits to be observed, not to be routed,
        // and the sink names JSON so `outputs` reports exactly the value a
        // handler emitted.
        $builder = new Builder('<harness>');
        $builder->input('in')->into($taskletClass)->sink('out', codec: ChannelCodec::Json);
        $this->node = $builder->tasklets[0];

        $spec = new ClassSpec(
            factoryId: '<harness>',
            kind: 'unbounded',
            persistent: true,
            effectivelyOnce: false,
            checkpointAck: false,
            checkpointEvery: null,
            checkpointOutputs: CheckpointOutputs::AsProduced,
            checkpointMaxDelayMs: null,
            correctionWindowMs: null,
            beyondWindowPolicy: BeyondWindow::ProcessAsArrival,
            compactAfterBytes: null,
            tasklets: $builder->tasklets,
            channels: $builder->channels,
        );

        // The dispatcher's own cell store, so the state this harness reports
        // is the state a runner would hold.
        $this->registered = new RegisteredClass($spec);
    }

    /** The cell's live state, as the dispatcher left it. */
    public function state(): mixed
    {
        return $this->registered->stateOf($this->node, $this->cellKey);
    }

    /** Run the tasklet against one input. */
    public function feed(mixed $input, int $now = 0, bool $replay = false): Outcome
    {
        return $this->drive(
            $now,
            $replay,
            fn (Ctx $ctx): mixed => $this->definition->instance->run($input, $ctx),
        );
    }

    /** Fire a per-cell timer. `now` IS the scheduled time, as it is live. */
    public function fireTimer(string $kind, int $scheduledAt): Outcome
    {
        return $this->drive($scheduledAt, false, function (Ctx $ctx) use ($kind, $scheduledAt): mixed {
            if ($this->definition->declares('onTimer')) {
                $this->definition->instance->onTimer(new Timer($kind, $scheduledAt), $ctx);
            }

            return null;
        });
    }

    /**
     * Run the declared compensation over recorded forward outputs.
     *
     * @param list<mixed> $outputs
     */
    public function compensate(array $outputs): Outcome
    {
        return $this->drive(0, false, function (Ctx $ctx) use ($outputs): mixed {
            if ($this->definition->declares('compensate')) {
                $this->definition->instance->compensate($outputs, $ctx);
            }

            return null;
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
        $this->registered->setState($this->node, $this->cellKey, Restore::fold(
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
    public function assertReplayEquals(): bool
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

        return true;
    }

    /**
     * The spawn bridge a `Ctx` asks. A harness has no controller, so a spawn
     * answers at once what one would have: `spawned` under the given id, or
     * under an id shaped like a generated one, and `skipped_on_replay` on a
     * replay.
     */
    public function spawnRequest(
        int $token,
        string $factoryId,
        string $id,
        string $payload,
        string $ifRunning,
        bool $replay,
        ?int $offset,
    ): int {
        $handle = $this->spawnCount++;
        if ($id === '' && !$replay) {
            // The spawn's number as the suffix: deterministic, and distinct per spawn.
            $suffix = '';
            for ($n = $handle, $i = 0; $i < 16; $i++, $n >>= 5) {
                $suffix = self::BASE32[$n & 31] . $suffix;
            }
            $id = "$factoryId-$suffix";
        }
        $this->spawns[$handle] = Json::encode(['id' => $id, 'outcome' => $replay ? 'skipped_on_replay' : 'spawned']);

        return $handle;
    }

    public function spawnResult(int $handle, int $timeoutMs): ?string
    {
        return $this->spawns[$handle] ?? throw new FlezhaException("spawn handle $handle was released");
    }

    /**
     * Drive `tasklet_class` over `$inputs` and assert the replay agrees.
     *
     * @param class-string<Tasklet>|class-string<Operator> $taskletClass
     * @param list<mixed>                                     $inputs
     */
    public static function replayEquals(string $taskletClass, array $inputs, ?string $cellKey = null): bool
    {
        $harness = new self($taskletClass, $cellKey);
        foreach ($inputs as $input) {
            $harness->feed($input);
        }

        return $harness->assertReplayEquals();
    }

    private function drive(int $now, bool $replay, callable $body): Outcome
    {
        $returned = null;
        try {
            $ctx = Dispatcher::invoke(
                [
                    'token' => 0,
                    'operator' => $this->operator,
                    'cell_key' => $this->cellKey,
                    'change_seq' => $this->changeSeq,
                ],
                $this->registered,
                $this->node,
                $this,
                $now,
                $replay,
                function (Ctx $ctx) use ($body, &$returned): void {
                    $returned = $body($ctx);
                    $ctx->emitReturned($returned);
                },
            );
        } finally {
            $this->spawns = [];
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
            returned: $returned,
        );
    }

    private function decodeChange(string $bytes): mixed
    {
        return $this->definition->decode('change', $bytes);
    }
}

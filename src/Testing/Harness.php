<?php

declare(strict_types=1);

namespace Flezha\Testing;

use Flezha\ChannelCodec;
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
use Flezha\Relay;
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
 *
 * A `Ctx::effect` keeps its result on a live {@see feed()}; the n-th
 * `feed(..., replay: true)` replays the n-th live input and is served what it
 * kept, and throws {@see EffectReplayMismatch} when a function runs there or
 * a kept result is never asked for.
 */
final class Harness implements SpawnBridge
{
    /** The lowercase base32 a generated id's suffix is spelled in. */
    private const BASE32 = 'abcdefghijklmnopqrstuvwxyz234567';

    /** The longest effect name, in bytes, as the core refuses it. */
    private const MAX_EFFECT_NAME = 255;

    private readonly Definition $definition;
    private readonly TaskNode $node;
    private readonly RegisteredClass $registered;

    /** @var list<string> the encoded change log, as the WAL would hold it */
    public array $log = [];

    private int $changeSeq = 0;

    /** @var array<int, string> each spawn's answer by handle number, released when its invocation ends */
    private array $spawns = [];

    private int $spawnCount = 0;

    /** @var list<array<string, list<string>>> each live input's effect results, by name in call order */
    private array $keptEffects = [];

    private int $replays = 0;

    /** @var array<string, list<string>> this input's effect results */
    private array $effects = [];

    private bool $replaying = false;

    /** @var array<string, int> this invocation's count of each effect name */
    private array $ordinals = [];

    /** @var array{string, int}|null the effect whose function is running */
    private ?array $running = null;

    /** @param class-string<Tasklet>|class-string<Relay> $taskletClass */
    public function __construct(
        string $taskletClass,
        private readonly ?string $cellKey = null,
        private readonly string $relay = 'harness',
    ) {
        $this->definition = Definition::of($taskletClass);

        // The real channel builder, not a hand-written spec: one entry channel
        // into the tasklet, and one plugin output so `ctx->emit` has somewhere
        // to go. A tasklet under test emits to be observed, not to be routed,
        // and the output names JSON so `outputs` reports exactly the value a
        // handler emitted.
        $builder = new Builder('<harness>');
        $builder->input('in')->into($taskletClass)->output('out', codec: ChannelCodec::Json);
        $this->node = $builder->tasklets[0];

        $spec = new ClassSpec(
            factoryId: '<harness>',
            description: null,
            maxDurationMs: null,
            run: null,
            durable: null,
            compactAfterBytes: null,
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
        if ($replay) {
            $this->effects = $this->keptEffects[$this->replays++] ?? [];
        } else {
            $this->effects = [];
            $this->keptEffects[] = [];
        }
        $this->replaying = $replay;
        $this->ordinals = [];
        $this->running = null;

        $outcome = $this->drive(
            $now,
            $replay,
            fn (Ctx $ctx) => $this->definition->instance->run($input, $ctx),
        );
        foreach ($replay ? $this->effects : [] as $name => $results) {
            if (count($results) > ($this->ordinals[$name] ?? 0)) {
                throw new EffectReplayMismatch($this->definition->name, (string) $name, 'was never asked for');
            }
        }

        return $outcome;
    }

    /** Fire a per-cell timer. `now` IS the scheduled time, as it is live. */
    public function fireTimer(string $kind, int $scheduledAt): Outcome
    {
        return $this->drive($scheduledAt, false, function (Ctx $ctx) use ($kind, $scheduledAt): void {
            if ($this->definition->declares('onTimer')) {
                $this->definition->instance->onTimer(new Timer($kind, $scheduledAt), $ctx);
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
        return $this->spawns[$handle]
            ?? throw new FlezhaException('runtime.spawn_handle_released', ['handle' => $handle]);
    }

    /** A harness has no controller and no listener, so the part, already encoded, goes nowhere. */
    public function part(int $token, int $item, string $payload): void
    {
    }

    /**
     * The core's answer to a begun `Ctx::effect`, over this input's results:
     * the kept one, or run the function (on a live input only).
     */
    public function effectBegin(int $token, int $item, string $name): array
    {
        if ($name === '' || strlen($name) > self::MAX_EFFECT_NAME) {
            throw new FlezhaException('effect.bad_name', ['name' => Json::encode($name)]);
        }
        if ($this->running !== null) {
            throw new FlezhaException('effect.nested', ['name' => $name]);
        }

        $ordinal = $this->ordinals[$name] ?? 0;
        $this->ordinals[$name] = $ordinal + 1;
        if (isset($this->effects[$name][$ordinal])) {
            return ['kept', $this->effects[$name][$ordinal]];
        }
        if ($this->replaying) {
            throw new EffectReplayMismatch($this->definition->name, $name, 'ran its function');
        }
        $this->running = [$name, $ordinal];

        return ['run', null];
    }

    /** Keep a live input's result, as the controller does. */
    public function effectEnd(int $token, int $item, string $payload): ?string
    {
        [$name, $ordinal] = $this->running ?? throw new \LogicException('an end follows a begin that runs');
        $this->effects[$name][$ordinal] = $payload;
        $this->keptEffects[array_key_last($this->keptEffects)] = $this->effects;
        $this->running = null;

        return null;
    }

    public function effectRelease(int $token, int $item): void
    {
        $this->running = null;
    }

    /**
     * Drive `tasklet_class` over `$inputs` and assert the replay agrees.
     *
     * @param class-string<Tasklet>|class-string<Relay> $taskletClass
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
                $this,
                $now,
                $replay,
                $body,
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
        );
    }

    private function decodeChange(string $bytes): mixed
    {
        return $this->definition->decode('change', $bytes);
    }
}

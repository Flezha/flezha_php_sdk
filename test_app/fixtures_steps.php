<?php

declare(strict_types=1);

/**
 * Twins of the reference's crash-resume steps fixtures — the commit chain,
 * the saga, the output identity, the branch, the diamond and the retry
 * ladder, plus the run-once echo — under the same factory ids, tasklet
 * names, channel names and output bytes as
 * test/apps/rust_runner/src/steps.rs. Loaded after fixtures.php, whose
 * `Wire` codecs these share; fixtures_steps_loop.php carries the loop and
 * bench families and is loaded after this file, as the reference splits them.
 *
 * Entry payloads and plugin frames are UTF-8 text on the wire, as in the
 * reference: an entry names the text codec, and a bare `sink(name)` sends a
 * string as it stands, so only a sink that prefixes its value declares an
 * encoder. An inter-tasklet hop between two PHP tasklets carries JSON, and
 * the core derives that channel's journal codec from the channel's kind, so no
 * fixture declares one.
 *
 * A fixture is a class here rather than a factory call, so the reference's
 * macros are abstract bases with overridable hooks.
 */

namespace Flezha\TestApp;

use Flezha\Attribute\Relay;
use Flezha\Attribute\Task;
use Flezha\ChannelCodec;
use Flezha\Ctx;
use Flezha\Definition;
use Flezha\Graph\Builder;
use Flezha\Graph\Edge;
use Flezha\Tasklet;
use Flezha\Timer;

// -- the release gate --------------------------------------------------------

/**
 * Hold this activation until a runner boots with the release variable set.
 *
 * The harness kills the first runner and launches its replacement with the
 * variable present, so the gate is a boot-time decision: never returning on the
 * first runner, returning at once on the second.
 *
 * This app's manifest declares a thread-pool dispatch model, so the obligation
 * is to park a POOL WORKER and never the driver thread: the core owns the
 * driver, the heartbeat and the control channel on threads of its own, and
 * `SwooleExecutor` grows the pool on the core's starvation signal. The park is
 * a read on a socket nothing ever writes — no timer, no spin, off the CPU
 * until the process ends.
 */
final class StepsGate
{
    public const RELEASE_ENV = 'FLEZHA_STEPS_E2E_RELEASE';

    public static function released(): bool
    {
        return getenv(self::RELEASE_ENV) !== false;
    }

    /** Park at `$point` when this runner was booted to hold there, until the release. */
    public static function holdAt(string $point): void
    {
        if (getenv('FLEZHA_STEPS_E2E_HOLD') === $point) {
            self::blockUntilReleased();
        }
    }

    public static function blockUntilReleased(): void
    {
        if (self::released()) {
            return;
        }

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new \RuntimeException('the release gate could not open the socket it parks on');
        }

        $write = null;
        $except = null;
        while (!self::released()) {
            $read = [$pair[0]];
            // Nothing holds the far end open to write it, so this answers only
            // when a signal interrupts the wait — in which case park again. PHP
            // warns on that interruption and the warning is the noise, not the
            // event, so it is suppressed here and nowhere else.
            @stream_select($read, $write, $except, null);
        }
    }

    /** Read at handler time: each scenario arms its own runner. */
    public static function armed(string $name): bool
    {
        return getenv($name) !== false;
    }
}

// -- the three bases ---------------------------------------------------------

/**
 * The reference's `durable_step!` (steps.rs:66-108): a step committing one
 * idempotent Change keyed by its own name, so a re-run after a resume folds to
 * the state it already had.
 *
 * `release()` and {@see UndoingStep}'s compensation are the two axes the macro
 * itself parameterises; `changeValue()` and `gated()` are the two the reference
 * varies by writing the tasklet out by hand instead.
 */
abstract class DurableStep extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['items' => []];
    }

    /**
     * A sorted, de-duplicated list. The reference's `SeenState` is a
     * `BTreeSet<String>`, chosen because a set keeps snapshot bytes
     * deterministic (steps.rs:50); a PHP array keeps insertion order, so the
     * fold sorts for the same determinism and skips a repeat for the same
     * idempotent re-run.
     */
    public function applyChange(mixed &$state, mixed $change): void
    {
        if (in_array($change['insert'], $state['items'], true)) {
            return;
        }
        $state['items'][] = $change['insert'];
        sort($state['items']);
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        // A gated step waits BEFORE its change and its emit, so the kill window
        // always finds it as the uncommitted producer while its sibling arm has
        // committed.
        if ($this->gated($input)) {
            StepsGate::blockUntilReleased();
        }
        $ctx->change(['insert' => $this->changeValue($input)]);
        $this->release($input, $ctx);
    }

    protected function gated(mixed $input): bool
    {
        return false;
    }

    /** What this step records: its own node name, unless a step says otherwise. */
    protected function changeValue(mixed $input): string
    {
        return Definition::of(static::class)->name;
    }

    protected function release(mixed $input, Ctx $ctx): void
    {
        $ctx->emit($input);
    }
}

/**
 * A durable step that compensates.
 *
 * Declared here rather than on {@see DurableStep} because `Definition` reads
 * which class declared a hook: a `compensate` on the durable base would make
 * every durable step compensable and contradict
 * test/fixtures/declarations/commit_block_steps.json.
 */
abstract class UndoingStep extends DurableStep
{
    /**
     * A crash-resumed compensation reconstructs completed stages with EMPTY
     * outputs, so a hook that records an undo does so unconditionally.
     */
    public function compensate(array $outputs, Ctx $ctx): void
    {
        if ($this->compensationGated()) {
            StepsGate::blockUntilReleased();
        }
        $record = $this->undoRecord();
        if ($record !== null) {
            $ctx->emit($record);
        }
    }

    protected function compensationGated(): bool
    {
        return false;
    }

    protected function undoRecord(): ?string
    {
        return null;
    }
}

/** A terminal step that always fails, which is how a saga is made to unwind. */
abstract class FailingStep extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        throw new \RuntimeException(Definition::of(static::class)->name . ' always fails');
    }
}

// -- echo_steps -----------------------------------------------------------
//
// The run-once shape (steps.rs:14): one tasklet emits its first message
// back once and the pass ends, so the controller deregisters the instance.

#[Task(name: 'echo_args')]
final class EchoArgs extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit("echo:$input");
    }
}

#[Relay(factoryId: 'echo_steps', run: 'steps', durable: FIXTURE_DURABLE)]
final class EchoSteps extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(EchoArgs::class)
            ->sink('out');
    }
}

// -- typed_once_steps -----------------------------------------------------
//
// The KB-381 shape: a `once` steps relay whose entry channel decodes the
// payload's type. Its one tasklet prints what the entry decoded and completes
// with it, because the runner's stdout and the completion record are the two
// places the scenario can read.

#[Task(name: 'once_placed')]
final class OncePlaced extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $text = (string) $input['text'];
        echo "once_ran $text\n";
        $ctx->complete($text);

        $ctx->emit("placed:$text");
    }
}

#[Relay(factoryId: 'typed_once_steps', run: 'steps', durable: FIXTURE_DURABLE)]
final class TypedOnceSteps extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('order', codec: ChannelCodec::Json)
            ->into(OncePlaced::class)
            ->sink('placed');
    }
}

// -- commit_block_steps / sync_durable_steps ---------------------------
//
// Task A commits durable state, task B parks until the resumed runner sets the
// release variable — so a SIGKILL lands with A's state already on the WAL and B
// never finished. `sync_durable_steps` is the same chain declaring
// SYNCHRONOUS remote durability, which is what lets the kill-the-disk drill
// destroy the state directory and still resume with A committed.

#[Task(name: 'commit_a')]
final class CommitA extends DurableStep
{
}

#[Task(name: 'block_b')]
final class BlockB extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        StepsGate::blockUntilReleased();
    }
}

abstract class CommitBlockChain extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(CommitA::class)->named('mid')
            ->into(BlockB::class);
    }
}

#[Relay(factoryId: 'commit_block_steps', run: 'steps', durable: FIXTURE_DURABLE)]
final class CommitBlockSteps extends CommitBlockChain
{
}

#[Relay(factoryId: 'sync_durable_steps', run: 'steps', durable: 'remote')]
final class SyncDurableSteps extends CommitBlockChain
{
}

// -- optout_commit_block -----------------------------------------------------
//
// The opt-out twin with a live mid-chain signal: task A's output also reaches a
// plugin sink, so the crash-resume drill can observe the mid-run point without
// durable state. A crash re-runs the chain from the top, so the sink sees A's
// marker once per run and no journal file exists.

#[Relay(factoryId: 'optout_commit_block', run: 'steps', durable: false)]
final class OptoutCommitBlock extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(CommitA::class)
            ->sink('mid', static fn (mixed $v): string => "a-ran:$v")
            ->into(BlockB::class);
    }
}

// -- tier_commit_block_steps ----------------------------------------------
//
// Task A commits durable state AND emits `committed:<input>` under a stable
// key on a keyed hybrid channel; task B parks, so a SIGKILL lands with A's
// stage committed and B unfinished. A steps relay commits stage by stage, so the
// class declares no tier of its own.

#[Task(name: 'tier_commit_a')]
final class TierCommitA extends DurableStep
{
    protected function release(mixed $input, Ctx $ctx): void
    {
        $ctx->emit("committed:$input", 'job');
    }
}

#[Relay(factoryId: 'tier_commit_block_steps', run: 'steps', durable: FIXTURE_DURABLE)]
final class TierCommitBlockSteps extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(TierCommitA::class)->keyed()
            ->sink('mid')
            ->into(BlockB::class);
    }
}

// -- saga_block_steps -----------------------------------------------------
//
// SA and SB commit durable state and compensate; SC fails, triggering
// reverse-order rollback (SB then SA). SA's compensation PARKS, so a SIGKILL
// lands mid-rollback with SB's done-marker already durable — the respawn
// finishes SA and SKIPS the already-done SB.

#[Task(name: 'saga_a')]
final class SagaA extends UndoingStep
{
    protected function compensationGated(): bool
    {
        return true;
    }
}

#[Task(name: 'saga_b')]
final class SagaB extends UndoingStep
{
}

#[Task(name: 'saga_c')]
final class SagaC extends FailingStep
{
}

#[Relay(factoryId: 'saga_block_steps', run: 'steps', durable: FIXTURE_DURABLE)]
final class SagaBlockSteps extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(SagaA::class)->named('s1')
            ->into(SagaB::class)->named('s2')
            ->into(SagaC::class);
    }
}

// -- keyed_block_steps / keyed_saga_steps -----------------------------
//
// The entry's comma-separated key list is split into one value per key, and a
// `keyBy` on that value mints one Cell per key of a durable compensable tasklet
// that declares no keys; each Cell commits one idempotent change. The third
// tasklet parks until the release variable, or fails, so the keyed stage is
// rolled back once per key.

#[Task(name: 'key_split')]
final class KeySplit extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        foreach (explode(',', (string) $input) as $key) {
            $ctx->emit($key);
        }
    }
}

#[Task(name: 'keyed_commit')]
final class KeyedCommit extends UndoingStep
{
}

#[Task(name: 'keyed_fail')]
final class KeyedFail extends FailingStep
{
}

abstract class KeyedCommits extends \Flezha\Relay
{
    protected static function keyedCommits(Builder $c): Edge
    {
        return $c->input('in', codec: ChannelCodec::Text)
            ->into(KeySplit::class)->named('keys')
            ->keyBy(static fn (mixed $key): string => (string) $key)
            ->into(KeyedCommit::class)->named('committed');
    }
}

#[Relay(factoryId: 'keyed_block_steps', run: 'steps', durable: FIXTURE_DURABLE)]
final class KeyedBlockSteps extends KeyedCommits
{
    public static function channels(Builder $c): void
    {
        self::keyedCommits($c)->into(BlockB::class);
    }
}

#[Relay(factoryId: 'keyed_saga_steps', run: 'steps', durable: FIXTURE_DURABLE)]
final class KeyedSagaSteps extends KeyedCommits
{
    public static function channels(Builder $c): void
    {
        self::keyedCommits($c)->into(KeyedFail::class);
    }
}

// -- outbox_block_steps ---------------------------------------------------
//
// A steps output's identity. Task A commits durable state AND emits once,
// keyed `k` with one metadata pair, on a keyed hybrid channel; the output rides
// A's Cell record and reaches the sink once A's stage commits. Task B parks, so
// a SIGKILL lands with A committed and B unfinished.

#[Task(name: 'commit_a')]
final class OutboxA extends DurableStep
{
    protected function release(mixed $input, Ctx $ctx): void
    {
        $ctx->emitKeyed('k', $input, metadata: ['trace' => 't1']);
    }
}

#[Relay(factoryId: 'outbox_block_steps', run: 'steps', durable: FIXTURE_DURABLE)]
final class OutboxBlockSteps extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(OutboxA::class)->keyed()
            ->sink('mid', static fn (mixed $v): string => "a-out:$v")
            ->into(BlockB::class);
    }
}

// -- undo_outbox_steps ----------------------------------------------------
//
// A compensation's undo output. Task UA commits, emits its forward output on a
// hybrid plugin channel, and compensates by EMITTING an undo record, which
// rides UA's Cell's own record and is released once the `compensated` entry
// naming it is durable. Task C fails immediately, so the chain always rolls
// back; the hook PARKS, so on the first runner the SIGKILL lands before the
// undo was written. The respawn re-runs the outstanding hook, whose done
// marker never landed, and the undo is delivered exactly once.

#[Task(name: 'undo_outbox_a')]
final class UndoOutboxA extends UndoingStep
{
    protected function compensationGated(): bool
    {
        return true;
    }

    protected function undoRecord(): ?string
    {
        return 'undone';
    }
}

#[Task(name: 'undo_outbox_c')]
final class UndoOutboxC extends FailingStep
{
}

#[Relay(factoryId: 'undo_outbox_steps', run: 'steps', durable: FIXTURE_DURABLE)]
final class UndoOutboxSteps extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(UndoOutboxA::class)
            ->sink('mid', static fn (mixed $v): string => "out:$v")
            ->into(UndoOutboxC::class);
    }
}

// -- failed_stage_steps ---------------------------------------------------
//
// A failed stage delivers nothing. The entry's two values reach one
// `failed_stage` Cell in order: the first emits on a plugin channel, so the
// Cell's record carries an output; the second fails, so the stage never
// commits and no marker ever names that record.

#[Task(name: 'failed_stage')]
final class FailedStage extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        if ($input === 'b') {
            throw new \RuntimeException('failed_stage fails on b');
        }
        $ctx->emit($input);
    }
}

#[Relay(factoryId: 'failed_stage_steps', run: 'steps', durable: FIXTURE_DURABLE)]
final class FailedStageSteps extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(KeySplit::class)
            ->into(FailedStage::class)
            ->sink('mid', static fn (mixed $v): string => "lost:$v");
    }
}

// -- branch_steps ---------------------------------------------------------
//
//     BranchA ─┬─▶ BranchB ─▶ BranchD
//              └─▶ BranchC
//
// The tasklet TREE (steps.rs:352). Every tasklet commits durable state
// and compensates by emitting an undo record, so both tree behaviours are
// observable at one sink:
//
//   * crash-resume — BranchD PARKS, so the SIGKILL lands with A, B and C
//     committed and only D's subtree outstanding. The respawn re-runs D alone,
//     seeded from B's journaled edge, so the sibling branch's `c:` marker
//     appears exactly ONCE across both runs.
//   * compensation — with FLEZHA_BRANCH_FAIL armed, BranchD fails instead of
//     parking. A, B and C have all completed, so the rollback unwinds the two
//     branches before the root they share: `undo-c`, `undo-b`, `undo-a`.
//
// The inter-task channels are hybrid, which is what makes a non-leaf
// tasklet's forward and undo output visible at the sink.

#[Task(name: 'branch_a')]
final class BranchA extends UndoingStep
{
    protected function undoRecord(): ?string
    {
        return 'undo-a';
    }
}

#[Task(name: 'branch_b')]
final class BranchB extends UndoingStep
{
    protected function undoRecord(): ?string
    {
        return 'undo-b';
    }
}

/** The sibling leaf: emits straight to the sink, and its undo does too. */
#[Task(name: 'branch_c')]
final class BranchC extends UndoingStep
{
    protected function release(mixed $input, Ctx $ctx): void
    {
        $ctx->emit("c:$input");
    }

    protected function undoRecord(): ?string
    {
        return 'undo-c';
    }
}

/** The deep leaf under BranchB: parks (crash-resume), or fails (compensation). */
#[Task(name: 'branch_d')]
final class BranchD extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        if (StepsGate::armed('FLEZHA_BRANCH_FAIL')) {
            throw new \RuntimeException('branch_d failed');
        }
        StepsGate::blockUntilReleased();
        $ctx->emit("d:$input");
    }
}

#[Relay(factoryId: 'branch_steps', run: 'steps', durable: FIXTURE_DURABLE)]
final class BranchSteps extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        // One channel, two consumers: THE fan-out. Both branches read exactly
        // what BranchA released on it (held edge, wired twice).
        $mid = $c->input('in', codec: ChannelCodec::Text)
            ->into(BranchA::class)
            ->sink('mid', static fn (mixed $v): string => "a:$v");
        $mid->into(BranchB::class)
            ->sink('bmid', static fn (mixed $v): string => "b:$v")
            // Fail fast into the one tasklet that fails: the compensation
            // scenario is about the rollback order, not the ladder before it.
            ->retryMax(1)
            ->into(BranchD::class)
            ->sink('d_out');
        $mid->into(BranchC::class)->sink('c_out');
    }
}

// -- diamond_steps --------------------------------------------------------
//
//     DiamA ─┬─▶ DiamB1 ─▶ DiamB2(parks) ─┬─▶ DiamJoin
//            └─▶ DiamC ───────────────────┘
//
// The fan-in DAG (steps.rs:475). The join accumulates BOTH arms' outputs and
// is dispatched exactly once, after both commit. DiamB2 parks one level BELOW
// its sibling arm — siblings commit at one level barrier — so a SIGKILL lands
// with A, B1 and C committed and B2 mid-run: the respawn re-runs B2 alone, and
// the join runs once with C's JOURNALED handoff banked and B2's fresh output
// appended, so `c:` appears exactly once across both runs.

#[Task(name: 'diam_a')]
final class DiamA extends DurableStep
{
}

#[Task(name: 'diam_b1')]
final class DiamB1 extends DurableStep
{
}

#[Task(name: 'diam_b2')]
final class DiamB2 extends DurableStep
{
    protected function gated(mixed $input): bool
    {
        return true;
    }

    protected function changeValue(mixed $input): string
    {
        return 'diam_b';
    }

    protected function release(mixed $input, Ctx $ctx): void
    {
        $ctx->emit("b:$input");
    }
}

#[Task(name: 'diam_c')]
final class DiamC extends DurableStep
{
    protected function release(mixed $input, Ctx $ctx): void
    {
        $ctx->emit("c:$input");
    }
}

/** The join: one `j:`-tagged record per accumulated contribution. */
#[Task(name: 'diam_join')]
final class DiamJoin extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit("j:$input");
    }
}

#[Relay(factoryId: 'diamond_steps', run: 'steps', durable: FIXTURE_DURABLE)]
final class DiamondSteps extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        // Held edge wired twice = the fan-out; two arms naming DiamJoin with no
        // node name = the fan-in, since two unnamed mentions are one node.
        $fan = $c->input('in', codec: ChannelCodec::Text)
            ->into(DiamA::class)
            ->sink('dfan', static fn (mixed $v): string => "a:$v");
        $fan->into(DiamB1::class)->named('db1')
            ->into(DiamB2::class)
            ->sink('db2')
            ->into(DiamJoin::class);
        $fan->into(DiamC::class)
            ->sink('dc')
            ->into(DiamJoin::class)
            ->sink('j_out');
    }
}

// -- retry_steps ----------------------------------------------------------
//
// The per-cell retry ladder (steps.rs:607). Task RA commits durable state,
// forwards on a hybrid channel, and compensates by emitting an undo record;
// `retry_flaky` fails its first FLEZHA_STEPS_RETRY_FAILS attempts (none by
// default — each scenario arms it) and then emits `attempt:<n>:<input>`. One
// armed failure is absorbed by the ladder, so the run completes and the sink
// sees attempt 2; arming more failures than the ladder has attempts exhausts
// it, RA rolls back and the run reports an error.

#[Task(name: 'retry_a')]
final class RetryA extends UndoingStep
{
    protected function undoRecord(): ?string
    {
        return 'undone';
    }
}

#[Task(name: 'retry_flaky')]
final class RetryFlaky extends Tasklet
{
    private const FAILS_ENV = 'FLEZHA_STEPS_RETRY_FAILS';

    /**
     * Attempts made in this interpreter. A retry re-runs the tasklet of the
     * same instance in place, so one counter is the whole ladder's view under
     * the single-loop executor this fixture runs on; a thread pool would give
     * each worker its own.
     */
    private static int $attempts = 0;

    public function run(mixed $input, Ctx $ctx): void
    {
        self::$attempts++;
        $armed = (int) (getenv(self::FAILS_ENV) ?: '0');
        if (self::$attempts <= $armed) {
            throw new \RuntimeException('flaky attempt ' . self::$attempts);
        }
        $ctx->emit('attempt:' . self::$attempts . ":$input");
    }
}

#[Relay(factoryId: 'retry_steps', run: 'steps', durable: FIXTURE_DURABLE)]
final class RetrySteps extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(RetryA::class)
            ->sink('mid', static fn (mixed $v): string => "res:$v")
            ->into(RetryFlaky::class)
            ->sink('out');
    }
}

// -- timer_loop_steps -----------------------------------------------------
//
// `in -> tick -> tock -> out`, a per-input loop whose `tick` arms a
// processing-time timer of the delay `after:<ms>` names and emits `armed:<at>`;
// its firing emits `fired:<at>` and opens a pass of its own. `slow:<ms>` holds
// `tick` that long, for a firing due mid-pass (steps.rs). On a runner booted
// with `FLEZHA_STEPS_E2E_HOLD` the firing parks at the gate: `timer` inside
// `on_timer`, before `tick` commits; `tock` in `tock`, after.

#[Task(name: 'tick')]
final class TimerTick extends DurableStep
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $input = (string) $input;
        if (str_starts_with($input, 'after:')) {
            $at = $ctx->timerAfter(Wire::int(substr($input, strlen('after:'))));
            $ctx->change(['insert' => "armed:$at"]);
            $ctx->emit("armed:$at");

            return;
        }
        if (str_starts_with($input, 'slow:')) {
            usleep(Wire::int(substr($input, strlen('slow:'))) * 1000);
        }
        $ctx->emit($input);
    }

    public function onTimer(Timer $timer, Ctx $ctx): void
    {
        StepsGate::holdAt('timer');
        $ctx->emit("fired:{$timer->scheduledAt}");
    }
}

#[Task(name: 'tock')]
final class TimerTock extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        if (str_starts_with((string) $input, 'fired:')) {
            StepsGate::holdAt('tock');
        }
        $ctx->emit("tock:$input");
    }
}

#[Relay(
    factoryId: 'timer_loop_steps',
    run: ['steps' => ['each' => 'per_input']],
    durable: FIXTURE_DURABLE,
    maxDurationMs: 600_000,
)]
final class TimerLoopSteps extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(TimerTick::class)->named('ticked')
            ->into(TimerTock::class)
            ->sink('out');
    }
}

// -- event_timer_steps ----------------------------------------------------
//
// An entry-fed tasklet whose clock is the message's leading event time; each
// message arms an event-time timer 100 later and emits `seen:<t>`, and the
// firing emits `fired:<at>` (steps.rs).

#[Task(name: 'event_tick')]
final class EventTick extends DurableStep
{
    public function eventTime(mixed $input): int
    {
        return Wire::int(explode(':', (string) $input, 2)[0]);
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $at = $ctx->timerAtEventTime($ctx->now() + 100);
        $ctx->change(['insert' => "armed:$at"]);
        $ctx->emit("seen:{$ctx->now()}");
    }

    public function onTimer(Timer $timer, Ctx $ctx): void
    {
        $ctx->emit("fired:{$timer->scheduledAt}");
    }
}

#[Relay(
    factoryId: 'event_timer_steps',
    run: ['steps' => ['each' => 'per_input']],
    durable: FIXTURE_DURABLE,
    maxDurationMs: 600_000,
)]
final class EventTimerSteps extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)->into(EventTick::class)->sink('out');
    }
}

// call_turn: a durable per-input loop whose turn answers the outside call
// through `response()`.
#[Task(name: 'call_turn')]
final class CallTurnStep extends PassThrough
{
}

#[Relay(
    factoryId: 'call_turn',
    run: ['steps' => ['each' => 'per_input']],
    durable: FIXTURE_DURABLE,
    maxDurationMs: 600_000,
)]
final class CallTurn extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)->into(CallTurnStep::class)->response();
    }
}

// -- registration ------------------------------------------------------------

final class StepsFixtures
{
    /** @return list<class-string<\Flezha\Relay>> */
    public static function all(): array
    {
        return [
            EchoSteps::class,
            TypedOnceSteps::class,
            CommitBlockSteps::class,
            SyncDurableSteps::class,
            OptoutCommitBlock::class,
            TierCommitBlockSteps::class,
            SagaBlockSteps::class,
            KeyedBlockSteps::class,
            KeyedSagaSteps::class,
            OutboxBlockSteps::class,
            UndoOutboxSteps::class,
            FailedStageSteps::class,
            ChatLoopSteps::class,
            StatelessLoopSteps::class,
            KeyedLoopSteps::class,
            ChatRewindSteps::class,
            FailingTurnLoopSteps::class,
            FailingTurnUndoAllSteps::class,
            ReplyBlockLoopSteps::class,
            RetrySteps::class,
            BranchSteps::class,
            DiamondSteps::class,
            BenchChain1::class,
            BenchChain4::class,
            BenchChain16::class,
            BenchChainOptout4::class,
            BenchLoopSteps::class,
            StaticCellSteps::class,
            TimerLoopSteps::class,
            EventTimerSteps::class,
            CallTurn::class,
            CompleteFlowRelay::class,
            ChainedSteps::class,
        ];
    }
}

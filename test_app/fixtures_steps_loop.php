<?php

declare(strict_types=1);

/**
 * The durable-loop steps twins — the chat loop with suspension, its
 * stateless twin and the rewindable variant — and the chains and loop
 * `mix bench.steps` drives. The reference's own partition
 * (test/apps/rust_runner/src/steps_loop.rs); loaded after
 * fixtures_steps.php, whose gate and bases these share.
 */

namespace Flezha\TestApp;

use Flezha\Attribute\Operator;
use Flezha\Attribute\Task;
use Flezha\ChannelCodec;
use Flezha\Ctx;
use Flezha\Exception\FlezhaException;
use Flezha\Graph\Builder;
use Flezha\Overflow;
use Flezha\SpawnHandle;
use Flezha\Tasklet;
use Flezha\Timer;

// -- the durable loop family -------------------------------------------------
//
// chat_loop_steps is the durable-loop + suspension shape
// (steps_loop.rs:8). One iteration per inbox item: TURN commits the turn to
// Cell state, so a resumed iteration is visible in the WAL, and REPLY emits
// the answer to the sink. Between turns the loop finds an empty inbox at a
// committed boundary and PARKS — the instance is evicted and rehydrated by the
// next message. The turn whose payload is the crash turn parks while the
// release variable is unset, so a SIGKILL lands with the earlier iterations
// durable and that one in flight. Keying the park on the payload rather than on
// an invocation count keeps it deterministic across the park/rehydrate cycles,
// which re-run the driver but not the turns. The turn `bye` records nothing,
// passes on like any turn, and completes the instance with the number of turns
// recorded, as decimal text.
//
// stateless_loop_steps is the same loop MINUS its stateful first tasklet:
// ChatReply alone holds no per-Cell state, so nothing writes a Cell WAL
// and the instance's task-journal reaches the driver only if it rides the bind
// itself.
//
// chat_rewind_steps is the loop whose replying tasklet can be undone. The
// undo handle lives in Cell state, because a rewind's compensation is rebuilt
// from the journal and is handed NO retained outputs, so the hook reads the
// last thing it said, retracts it and pops it.

#[Task(name: 'chat_turn')]
final class ChatTurn extends DurableStep
{
    public const CRASH_TURN = 'mid-crash';
    public const CLOSING_TURN = 'bye';

    public function run(mixed $input, Ctx $ctx): void
    {
        if ($input !== self::CLOSING_TURN) {
            parent::run($input, $ctx);

            return;
        }
        $this->release($input, $ctx);
        $ctx->complete(count($ctx->state['items']));
    }

    protected function gated(mixed $input): bool
    {
        return $input === self::CRASH_TURN;
    }

    /**
     * Keyed by the turn, so the record count is the number of DISTINCT turns
     * that ran — a re-run of a committed iteration would show up.
     */
    protected function changeValue(mixed $input): string
    {
        return (string) $input;
    }
}

#[Task(name: 'chat_reply')]
final class ChatReply extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit("reply:$input");
    }
}

#[Task(name: 'chat_reply_undoable')]
final class ChatReplyUndoable extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['said' => []];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        if (array_key_exists('said', $change)) {
            $state['said'][] = $change['said'];

            return;
        }
        array_pop($state['said']);
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->change(['said' => $input]);
        $ctx->emit("reply:$input");
    }

    public function compensate(array $outputs, Ctx $ctx): void
    {
        $said = $ctx->state['said'];
        if ($said === []) {
            return;
        }
        $turn = $said[array_key_last($said)];
        $ctx->change(['retracted' => true]);
        $ctx->emit("retract:$turn");
    }
}

#[Operator(
    factoryId: 'chat_loop_steps',
    run: ['steps' => ['each' => 'per_input']],
    durable: FIXTURE_DURABLE,
    maxDurationMs: 600_000,
)]
// A chat session ends on the turn `bye`; the duration backstop keeps a stray
// instance from outliving the test run.
final class ChatLoopSteps extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(ChatTurn::class)->named('mid')
            ->into(ChatReply::class)
            ->sink('out');
    }
}

#[Operator(
    factoryId: 'stateless_loop_steps',
    run: ['steps' => ['each' => 'per_input']],
    durable: FIXTURE_DURABLE,
    maxDurationMs: 600_000,
)]
final class StatelessLoopSteps extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(ChatReply::class)
            ->sink('out');
    }
}

// keyed_loop_steps is a durable per-input loop keyed on its entry edge by
// the text before the first `:`, so one Cell counts one symbol's messages and
// emits `<symbol>:<count>`: the shape a partition group of steps operator members
// takes.

#[Task(name: 'keyed_loop_count')]
final class KeyedLoopCount extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['value' => 0];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['value'] += $change['delta'];
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $symbol = Wire::prefix($input);
        $ctx->change(['delta' => 1]);
        $ctx->emit($symbol . ':' . $ctx->state['value']);
    }
}

#[Operator(
    factoryId: 'keyed_loop_steps',
    run: ['steps' => ['each' => 'per_input']],
    durable: FIXTURE_DURABLE,
    maxDurationMs: 600_000,
)]
final class KeyedLoopSteps extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('trades', codec: ChannelCodec::Text)->keyBy(Wire::prefix(...))
            ->into(KeyedLoopCount::class)
            ->sink('keyed_loop_steps_out');
    }
}

#[Operator(
    factoryId: 'chat_rewind_steps',
    run: ['steps' => ['each' => 'per_input']],
    durable: FIXTURE_DURABLE,
    maxDurationMs: 600_000,
)]
final class ChatRewindSteps extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(ChatTurn::class)->named('mid')
            ->into(ChatReplyUndoable::class)
            ->sink('out');
    }
}

// failing_turn_loop_steps: a session whose turn can fail after `note`
// committed. `note` records each message and its undo moves the latest one to
// `undone`; `answer` replies `re:<message>` on the sink `answer`, and fails on
// `boom` always, on `boom-once` while the release env is unset (the first
// runner of a crash drill), and on `boom-until-healed` until a `heal` turn set
// its state. failing_turn_undo_all_steps is the same under
// all-or-nothing.

#[Task(name: 'note')]
final class Note extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['notes' => [], 'undone' => []];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        if (array_key_exists('noted', $change)) {
            $state['notes'][] = $change['noted'];

            return;
        }
        if ($state['notes'] !== []) {
            $state['undone'][] = array_pop($state['notes']);
        }
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->change(['noted' => $input]);
        $ctx->emit($input);
    }

    public function compensate(array $outputs, Ctx $ctx): void
    {
        $ctx->change(['undone' => true]);
    }
}

#[Task(name: 'answer')]
final class Answer extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['healed' => false];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['healed'] = true;
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $fails = $input === 'boom'
            || ($input === 'boom-once' && !StepsGate::released())
            || ($input === 'boom-until-healed' && !$ctx->state['healed']);
        if ($fails) {
            throw new \RuntimeException("answer refused $input");
        }
        if ($input === 'heal') {
            $ctx->change(['healed' => true]);
        }
        $ctx->emit("re:$input");
    }
}

abstract class FailingTurnLoop extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(Note::class)->named('noted')
            ->into(Answer::class)
            ->sink('answer');
    }
}

#[Operator(
    factoryId: 'failing_turn_loop_steps',
    run: ['steps' => ['each' => 'per_input', 'on_failure' => 'keep_progress']],
    durable: FIXTURE_DURABLE,
    maxDurationMs: 600_000,
)]
final class FailingTurnLoopSteps extends FailingTurnLoop
{
}

#[Operator(
    factoryId: 'failing_turn_undo_all_steps',
    run: ['steps' => ['each' => 'per_input']],
    durable: FIXTURE_DURABLE,
    maxDurationMs: 600_000,
)]
final class FailingTurnUndoAllSteps extends FailingTurnLoop
{
}

// The reply that blocks on the turn `reply-crash` while the release env is
// unset, after the root has committed.
#[Task(name: 'blocking_reply')]
final class BlockingReply extends Tasklet
{
    public const REPLY_CRASH_TURN = 'reply-crash';

    public function run(mixed $input, Ctx $ctx): void
    {
        if ($input === self::REPLY_CRASH_TURN) {
            StepsGate::blockUntilReleased();
        }
        $ctx->emit("reply:$input");
    }
}

// The chat loop with the block moved AFTER the root's commit, so a SIGKILL
// lands between a root's `task_committed` and the pass's `iter_committed`.
#[Operator(
    factoryId: 'reply_block_loop_steps',
    run: ['steps' => ['each' => 'per_input']],
    durable: FIXTURE_DURABLE,
    maxDurationMs: 600_000,
)]
final class ReplyBlockLoopSteps extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(ChatTurn::class)->named('mid')
            ->into(BlockingReply::class)
            ->sink('out');
    }
}

// -- spawn_requester ---------------------------------------------------------
//
// On each input it spawns stateless_loop_steps once with no id and once under
// the input as its id, and prints both results, because a handle's answer is
// observable nowhere but in the tasklet that read it.

#[Task(name: 'spawn_requester')]
final class SpawnRequester extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $handles = [
            'unnamed' => $ctx->spawnSteps('stateless_loop_steps', (string) $input),
            'named' => $ctx->spawnSteps('stateless_loop_steps', (string) $input, id: (string) $input),
        ];
        foreach ($handles as $label => $handle) {
            self::report($label, $handle);
        }
    }

    /** A spawn handle's answer on stdout, as `spawn_result <label> <id> <outcome>`. */
    public static function report(string $label, SpawnHandle $handle): void
    {
        try {
            $result = $handle->wait();
            echo "spawn_result $label {$result->id} {$result->outcome->value}\n";
        } catch (FlezhaException $e) {
            echo "spawn_result $label error {$e->getCode()}\n";
        }
    }
}

#[Operator(factoryId: 'spawn_requester')]
final class SpawnRequesterOperator extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(SpawnRequester::class)
            ->sink('out');
    }
}

// -- spawn_hold_requester ----------------------------------------------------
//
// An effectively-once stream whose tasklet spawns stateless_loop_steps with
// no id, prints the answer as `spawn_result hold <id> <outcome>`, then blocks
// until the release env is set, so a runner killed there dies with the spawn
// answered and its input not durably consumed.

#[Task(name: 'spawn_hold_requester')]
final class SpawnHoldRequester extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        SpawnRequester::report('hold', $ctx->spawnSteps('stateless_loop_steps', (string) $input));
        StepsGate::blockUntilReleased();
    }
}

#[Operator(factoryId: 'spawn_hold_requester', run: ['flow' => ['exactly' => 'effectively_once']])]
final class SpawnHoldRequesterOperator extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)->onFull(Overflow::DropNothing)
            ->into(SpawnHoldRequester::class)->keyed()
            ->sink('out');
    }
}

// -- spawn_once_requester ----------------------------------------------------
//
// On each input it spawns `typed_once_steps` with no id, the input inside a
// JSON object its entry channel decodes, and prints the result as
// spawn_requester does.

#[Task(name: 'spawn_once_requester')]
final class SpawnOnceRequester extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        SpawnRequester::report('once', $ctx->spawnSteps('typed_once_steps', ['text' => (string) $input]));
    }
}

#[Operator(factoryId: 'spawn_once_requester')]
final class SpawnOnceRequesterOperator extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(SpawnOnceRequester::class)
            ->sink('out');
    }
}

// -- the bench chains --------------------------------------------------------
//
// What `mix bench.steps --scenario chain` drives (steps_loop.rs:232): K
// durable pass-through steps in a line, the payload unchanged, so the sink
// receives the seed input and the bench reads its own seq and send timestamp
// back. Each chain position needs its own tasklet and a PHP tasklet is a
// class, so the sixteen are written out; the edge names follow the reference,
// which spells them from the step TYPE rather than from the tasklet name.

#[Task(name: 'bench_s0')]
final class BenchS0 extends DurableStep
{
}

#[Task(name: 'bench_s1')]
final class BenchS1 extends DurableStep
{
}

#[Task(name: 'bench_s2')]
final class BenchS2 extends DurableStep
{
}

#[Task(name: 'bench_s3')]
final class BenchS3 extends DurableStep
{
}

#[Task(name: 'bench_s4')]
final class BenchS4 extends DurableStep
{
}

#[Task(name: 'bench_s5')]
final class BenchS5 extends DurableStep
{
}

#[Task(name: 'bench_s6')]
final class BenchS6 extends DurableStep
{
}

#[Task(name: 'bench_s7')]
final class BenchS7 extends DurableStep
{
}

#[Task(name: 'bench_s8')]
final class BenchS8 extends DurableStep
{
}

#[Task(name: 'bench_s9')]
final class BenchS9 extends DurableStep
{
}

#[Task(name: 'bench_s10')]
final class BenchS10 extends DurableStep
{
}

#[Task(name: 'bench_s11')]
final class BenchS11 extends DurableStep
{
}

#[Task(name: 'bench_s12')]
final class BenchS12 extends DurableStep
{
}

#[Task(name: 'bench_s13')]
final class BenchS13 extends DurableStep
{
}

#[Task(name: 'bench_s14')]
final class BenchS14 extends DurableStep
{
}

#[Task(name: 'bench_s15')]
final class BenchS15 extends DurableStep
{
}

/** A linear chain `in → BenchS0 → … → bench_sink`, STEPS positions long. */
abstract class BenchChain extends \Flezha\Operator
{
    /** @var list<class-string<DurableStep>> */
    public const STEP_CLASSES = [
        BenchS0::class, BenchS1::class, BenchS2::class, BenchS3::class,
        BenchS4::class, BenchS5::class, BenchS6::class, BenchS7::class,
        BenchS8::class, BenchS9::class, BenchS10::class, BenchS11::class,
        BenchS12::class, BenchS13::class, BenchS14::class, BenchS15::class,
    ];

    protected const STEPS = 1;

    public static function channels(Builder $c): void
    {
        $edge = $c->input('in', codec: ChannelCodec::Text)->into(self::STEP_CLASSES[0]);
        for ($at = 1; $at < static::STEPS; $at++) {
            $edge = $edge->named("edge_BenchS$at")->into(self::STEP_CLASSES[$at]);
        }
        $edge->sink('out');
    }
}

#[Operator(factoryId: 'bench_chain_1', run: 'steps', durable: FIXTURE_DURABLE)]
final class BenchChain1 extends BenchChain
{
    protected const STEPS = 1;
}

#[Operator(factoryId: 'bench_chain_4', run: 'steps', durable: FIXTURE_DURABLE)]
final class BenchChain4 extends BenchChain
{
    protected const STEPS = 4;
}

#[Operator(factoryId: 'bench_chain_16', run: 'steps', durable: FIXTURE_DURABLE)]
final class BenchChain16 extends BenchChain
{
    protected const STEPS = 16;
}

#[Operator(factoryId: 'bench_chain_optout_4', run: 'steps', durable: false)]
final class BenchChainOptout4 extends BenchChain
{
    protected const STEPS = 4;
}

// -- bench_loop_steps -----------------------------------------------------
//
// The long-lived loop the `turns` scenario drives (steps_loop.rs:359): the
// chat loop's shape with a BOUNDED per-turn state, so a million-turn soak
// snapshots the same few bytes at every iteration. With
// `FLEZHA_BENCH_WAKE_AFTER_MS` set each turn arms a timer that far out, whose
// firing sends the bench's wake marker, `wake:<fired_at>`, for the timer-wake measurements.

#[Task(name: 'bench_turn')]
final class BenchTurn extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['turns' => 0, 'last' => ''];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['turns']++;
        $state['last'] = $change['turn'];
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->change(['turn' => $input]);
        $after = getenv('FLEZHA_BENCH_WAKE_AFTER_MS');
        if (is_string($after) && ctype_digit($after)) {
            $ctx->timerAfter((int) $after);
        }
        $ctx->emit($input);
    }

    public function onTimer(Timer $timer, Ctx $ctx): void
    {
        $ctx->emit("wake:{$timer->scheduledAt}");
    }
}

// A day, not the chat loop's ten minutes: a soak outlives 600 s.
#[Operator(
    factoryId: 'bench_loop_steps',
    run: ['steps' => ['each' => 'per_input']],
    durable: FIXTURE_DURABLE,
    maxDurationMs: 24 * 3_600_000,
)]
final class BenchLoopSteps extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(BenchTurn::class)->named('mid')
            ->into(ChatReply::class)
            ->sink('out');
    }
}

// -- static_cell_steps ----------------------------------------------------
//
// A per-input loop whose one tasklet declares three static cells and routes
// every message to two of them; each cell that runs emits `<cell key>:<message>`,
// so one message yields exactly two outputs (steps.rs).

#[Task(name: 'static_cell_step', cellKeys: ['a', 'b', 'c'])]
final class StaticCellStep extends Tasklet
{
    public function cellsFor(mixed $input): array
    {
        return ['a', 'b'];
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit("{$ctx->cellKey}:$input");
    }
}

#[Operator(
    factoryId: 'static_cell_steps',
    run: ['steps' => ['each' => 'per_input']],
    durable: FIXTURE_DURABLE,
    maxDurationMs: 600_000,
)]
final class StaticCellSteps extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(StaticCellStep::class)
            ->sink('out');
    }
}

// -- complete_flow / chained_steps -------------------------------------------

/** Echoes each input and ends its instance on `bye`, the echo of `bye` included. */
#[Task(name: 'complete_flow_tasklet')]
final class CompleteFlow extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        if ($input === 'bye') {
            $ctx->complete($input);
        }
        $ctx->emit($input);
    }
}

#[Operator(factoryId: 'complete_flow')]
final class CompleteFlowOperator extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)
            ->into(CompleteFlow::class)
            ->sink('complete_flow_out');
    }
}

/**
 * Prints `chained_pass <n>` and hands the next pass `n - 1` on its own
 * channel; the pass at zero hands nothing, and the empty batch ends the loop.
 */
#[Task(name: 'chained_countdown')]
final class ChainedCountdown extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        echo "chained_pass $input\n";
        $n = (int) $input;
        if ($n > 0) {
            $ctx->emit((string) ($n - 1));
        }
    }
}

#[Operator(factoryId: 'chained_steps', run: ['steps' => ['each' => 'chained']])]
final class ChainedSteps extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(ChainedCountdown::class)
            ->named('carry');
    }
}

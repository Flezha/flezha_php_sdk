<?php

declare(strict_types=1);

/**
 * The durable-loop workflow twins — the chat loop with suspension, its
 * stateless twin and the rewindable variant — and the chains and loop
 * `mix bench.workflow` drives. The reference's own partition
 * (test/apps/rust_runner/src/workflow_loop.rs); loaded after
 * fixtures_workflow.php, whose gate and bases these share.
 */

namespace Flezha\TestApp;

use Flezha\Attribute\Operative;
use Flezha\Attribute\Task;
use Flezha\Attribute\Workflow;
use Flezha\ChannelCodec;
use Flezha\Ctx;
use Flezha\Exception\SpawnException;
use Flezha\Graph\Builder;
use Flezha\LoopMode;
use Flezha\SpawnHandle;
use Flezha\Tasklet;
use Flezha\StreamOperative;
use Flezha\WorkflowOperative;

// -- the durable loop family -------------------------------------------------
//
// chat_loop_workflow is the durable-loop + suspension shape
// (workflow_loop.rs:8). One iteration per inbox item: TURN commits the turn to
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
// stateless_loop_workflow is the same loop MINUS its stateful first tasklet:
// ChatReply alone holds no per-Cell state, so nothing writes a Cell WAL
// and the instance's task-journal reaches the driver only if it rides the bind
// itself.
//
// chat_rewind_workflow is the loop whose replying tasklet can be undone. The
// undo handle lives in Cell state, because a rewind's compensation is rebuilt
// from the journal and is handed NO retained outputs, so the hook reads the
// last thing it said, retracts it and pops it.

#[Task(name: 'chat_turn')]
final class ChatTurn extends DurableStep
{
    public const CRASH_TURN = 'mid-crash';
    public const CLOSING_TURN = 'bye';

    public function run(mixed $input, Ctx $ctx): mixed
    {
        if ($input !== self::CLOSING_TURN) {
            return parent::run($input, $ctx);
        }
        $this->release($input, $ctx);
        $ctx->complete(count($ctx->state['items']));

        return null;
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
    public function run(mixed $input, Ctx $ctx): mixed
    {
        $ctx->emit("reply:$input");

        return null;
    }
}

#[Task(name: 'chat_reply_undoable')]
final class ChatReplyUndoable extends Tasklet
{
    public function state(?string $cellKey): mixed
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

    public function run(mixed $input, Ctx $ctx): mixed
    {
        $ctx->change(['said' => $input]);
        $ctx->emit("reply:$input");

        return null;
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

#[Operative(factoryId: 'chat_loop_workflow')]
// A chat session ends on the turn `bye`; the duration backstop keeps a stray
// instance from outliving the test run.
#[Workflow(loopMode: LoopMode::PerInput, maxDurationMs: 600_000)]
final class ChatLoopWorkflow extends WorkflowOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(ChatTurn::class)->named('mid')
            ->into(ChatReply::class)
            ->sink('out');
    }
}

#[Operative(factoryId: 'stateless_loop_workflow')]
#[Workflow(loopMode: LoopMode::PerInput, maxDurationMs: 600_000)]
final class StatelessLoopWorkflow extends WorkflowOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(ChatReply::class)
            ->sink('out');
    }
}

#[Operative(factoryId: 'chat_rewind_workflow')]
#[Workflow(loopMode: LoopMode::PerInput, maxDurationMs: 600_000)]
final class ChatRewindWorkflow extends WorkflowOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(ChatTurn::class)->named('mid')
            ->into(ChatReplyUndoable::class)
            ->sink('out');
    }
}

// The reply that blocks on the turn `reply-crash` while the release env is
// unset, after the root has committed.
#[Task(name: 'blocking_reply')]
final class BlockingReply extends Tasklet
{
    public const REPLY_CRASH_TURN = 'reply-crash';

    public function run(mixed $input, Ctx $ctx): mixed
    {
        if ($input === self::REPLY_CRASH_TURN) {
            WorkflowGate::blockUntilReleased();
        }
        $ctx->emit("reply:$input");

        return null;
    }
}

// The chat loop with the block moved AFTER the root's commit, so a SIGKILL
// lands between a root's `task_committed` and the pass's `iter_committed`.
#[Operative(factoryId: 'reply_block_loop_workflow')]
#[Workflow(loopMode: LoopMode::PerInput, maxDurationMs: 600_000)]
final class ReplyBlockLoopWorkflow extends WorkflowOperative
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
// On each input it spawns stateless_loop_workflow once with no id and once under
// the input as its id, and prints both results, because a handle's answer is
// observable nowhere but in the tasklet that read it.

#[Task(name: 'spawn_requester')]
final class SpawnRequester extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        $handles = [
            'unnamed' => $ctx->spawnWorkflow('stateless_loop_workflow', (string) $input),
            'named' => $ctx->spawnWorkflow('stateless_loop_workflow', (string) $input, id: (string) $input),
        ];
        foreach ($handles as $label => $handle) {
            self::report($label, $handle);
        }

        return null;
    }

    /** A spawn handle's answer on stdout, as `spawn_result <label> <id> <outcome>`. */
    public static function report(string $label, SpawnHandle $handle): void
    {
        try {
            $result = $handle->wait();
            echo "spawn_result $label {$result->id} {$result->outcome->value}\n";
        } catch (SpawnException $e) {
            echo "spawn_result $label error {$e->getMessage()}\n";
        }
    }
}

#[Operative(factoryId: 'spawn_requester')]
final class SpawnRequesterOperative extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(SpawnRequester::class)
            ->sink('out');
    }
}

// -- spawn_once_requester ----------------------------------------------------
//
// On each input it spawns `typed_once_workflow` with no id, the input inside a
// JSON object its entry channel decodes, and prints the result as
// spawn_requester does.

#[Task(name: 'spawn_once_requester')]
final class SpawnOnceRequester extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        SpawnRequester::report('once', $ctx->spawnWorkflow('typed_once_workflow', ['text' => (string) $input]));

        return null;
    }
}

#[Operative(factoryId: 'spawn_once_requester')]
final class SpawnOnceRequesterOperative extends StreamOperative
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
// What `mix bench.workflow --scenario chain` drives (workflow_loop.rs:232): K
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
abstract class BenchChain extends WorkflowOperative
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

#[Operative(factoryId: 'bench_chain_1')]
final class BenchChain1 extends BenchChain
{
    protected const STEPS = 1;
}

#[Operative(factoryId: 'bench_chain_4')]
final class BenchChain4 extends BenchChain
{
    protected const STEPS = 4;
}

#[Operative(factoryId: 'bench_chain_16')]
final class BenchChain16 extends BenchChain
{
    protected const STEPS = 16;
}

#[Operative(factoryId: 'bench_chain_optout_4', persistent: false)]
final class BenchChainOptout4 extends BenchChain
{
    protected const STEPS = 4;
}

// -- bench_loop_workflow -----------------------------------------------------
//
// The long-lived loop the `turns` scenario drives (workflow_loop.rs:359): the
// chat loop's shape with a BOUNDED per-turn state, so a million-turn soak
// snapshots the same few bytes at every iteration, and a wake deadline for the
// timer-wake measurements.

#[Task(name: 'bench_turn')]
final class BenchTurn extends Tasklet
{
    public function state(?string $cellKey): mixed
    {
        return ['turns' => 0, 'last' => ''];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['turns']++;
        $state['last'] = $change['turn'];
    }

    public function run(mixed $input, Ctx $ctx): mixed
    {
        $ctx->change(['turn' => $input]);
        $ctx->emit($input);

        return null;
    }
}

#[Operative(factoryId: 'bench_loop_workflow')]
final class BenchLoopWorkflow extends WorkflowOperative
{
    private const WAKE_AFTER_ENV = 'FLEZHA_BENCH_WAKE_AFTER_MS';

    /**
     * Built rather than written as an attribute: a PHP attribute argument is a
     * constant expression, and the wake deadline is an axis of the bench run.
     * A day, not the chat loop's ten minutes — a soak outlives 600 s.
     */
    protected static function workflowDeclaration(): Workflow
    {
        $wake = (int) (getenv(self::WAKE_AFTER_ENV) ?: '0');

        return new Workflow(
            loopMode: LoopMode::PerInput,
            maxDurationMs: 24 * 3_600_000,
            wakeAfterMs: $wake > 0 ? $wake : null,
        );
    }

    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(BenchTurn::class)->named('mid')
            ->into(ChatReply::class)
            ->sink('out');
    }
}

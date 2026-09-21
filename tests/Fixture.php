<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\Attribute\Operative;
use Flezha\Attribute\Task;
use Flezha\ChannelCodec;
use Flezha\Clock;
use Flezha\Ctx;
use Flezha\Graph\Builder;
use Flezha\Graph\Edge;
use Flezha\Overflow;
use Flezha\Tasklet;
use Flezha\StreamOperative;
use Flezha\WorkflowOperative;

/**
 * The tasklets and operatives the unit suite declares against.
 *
 * One file, so a test that needs "a stateful keyed tasklet" names one rather
 * than declaring a fourth almost-identical class.
 */

/** The echo body, for a tasklet and a short-form operative alike. */
trait Echoes
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        return $input;
    }
}

#[Task]
final class Echoer extends Tasklet
{
    use Echoes;
}

#[Task(name: 'counter')]
final class Counter extends Tasklet
{
    public function state(?string $cellKey): mixed
    {
        return ['count' => 0, 'seen' => []];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['count']++;
        $state['seen'][] = $change['push'];
    }

    public function run(mixed $input, Ctx $ctx): mixed
    {
        $ctx->change(['push' => $input]);

        return null;
    }

    public function onTimer(\Flezha\Timer $timer, Ctx $ctx): void
    {
        $ctx->change(['push' => 'timer:' . $timer->scheduledAt]);
    }
}

#[Task(dynamicCells: true)]
final class Keyed extends Tasklet
{
    public function state(?string $cellKey): mixed
    {
        return ['key' => $cellKey, 'values' => []];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['values'][] = $change;
    }

    public function run(mixed $input, Ctx $ctx): mixed
    {
        $ctx->change($input);
        $ctx->emit($input, key: (string) $ctx->cellKey);

        return null;
    }
}

#[Operative(factoryId: 'echo_operative')]
final class EchoOperative extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('input')->into(Echoer::class)->sink('out');
    }
}

#[Operative(factoryId: 'counter_operative')]
final class CounterOperative extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('input')->into(Counter::class)->sink('out');
    }
}

/** A short form that only keeps state: no `sink`, and a body answering nothing. */
#[Operative(factoryId: 'sf_counter', input: 'ticks', codec: ChannelCodec::Text)]
final class ShortCounter extends StreamOperative
{
    public function state(?string $cellKey): mixed
    {
        return ['seen' => []];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['seen'][] = $change;
    }

    public function run(mixed $input, Ctx $ctx): mixed
    {
        $ctx->change($input);

        return null;
    }
}

#[Operative(factoryId: 'keyed_operative')]
final class KeyedOperative extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('input')
            ->onFull(Overflow::DropNothing)
            ->filter(static fn (mixed $v): bool => $v !== 'drop')
            ->keyBy(static fn (mixed $v): string => explode(':', (string) $v)[0])
            ->into(Keyed::class)
            ->keyed()
            ->sink('out');
    }
}

/**
 * The keyed plugin emitter: one output channel, carrying the identity stamps.
 */
#[Task(name: 'stamp_emitter', dynamicCells: true)]
final class StampEmitter extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        if ($input === 'twice') {
            $ctx->emit('a', channel: 'keyed_out');
            $ctx->emit('b', channel: 'keyed_out');
        } elseif ($input === 'spoof') {
            $ctx->emit('v', channel: 'keyed_out', metadata: ['flz.seq' => '999.0']);
        } elseif ($input === 'fanned') {
            $ctx->emit('x', channel: 'keyed_out');
        } else {
            $ctx->emit('k', channel: 'keyed_out', metadata: ['user' => 'meta']);
        }

        return null;
    }
}

/**
 * One keyed plugin channel: the topology every SDK can express, and where the
 * per-emit ordinal has to be right.
 */
#[Operative(factoryId: 'stamp_operative')]
final class StampOperative extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('input')
            ->keyBy(static fn (mixed $v): string => 'ek')
            ->into(StampEmitter::class)
            ->keyed()
            ->sink('keyed_out');
    }
}

#[Task]
final class PlainEmitter extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        $ctx->emit('p', channel: 'plain_out');

        return null;
    }
}

#[Task]
final class FanTarget extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        return null;
    }
}

/**
 * A tasklet owns ONE output channel, so a keyed and a non-keyed plugin
 * channel are two tasklets. The `flatMap` arm fans onto a CONSUMER edge,
 * which is where a transform chain lives: the plugin frame is serialized once
 * per emit from the value as emitted, in every SDK.
 *
 * The sink is attached last, on the held handle. A transform declared behind an
 * attached sink is `TransformAfterTerminal` in every SDK — the sink serializes
 * the channel's own value, so the order says which value that is.
 */
#[Operative(factoryId: 'mixed_stamp_operative')]
final class MixedStampOperative extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('input')
            ->keyBy(static fn (mixed $v): string => 'ek')
            ->branch(
                static fn (Edge $e) => $e->into(PlainEmitter::class)
                    ->sink('plain_out'),
                static function (Edge $e): void {
                    $keyed = $e->into(StampEmitter::class)->keyed();
                    $keyed->flatMap(static fn (mixed $v): array => [$v . '1', $v . '2'])
                        ->into(FanTarget::class);
                    $keyed->sink('keyed_out');
                },
            );
    }
}

#[Task]
final class Poison extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        if ($input === 'poison') {
            throw new \Flezha\Exception\NonRetryableException('poisoned payload');
        }

        return $input;
    }
}

#[Operative(factoryId: 'poison_operative')]
final class PoisonOperative extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('input')->into(Poison::class)->sink('out');
    }
}

/** Throws on a value that did decode: a handler's failure, not the entrance's. */
#[Task(name: 'flaky')]
final class Flaky extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        if ($input === 'boom') {
            throw new \RuntimeException('the handler rejected it');
        }

        return $input;
    }
}

/** A text entry whose edge transform rejects `poison`, into a handler that rejects `boom`. */
#[Operative(factoryId: 'flaky_entry_operative')]
final class FlakyEntryOperative extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->map(static function (string $text): string {
                if ($text === 'poison') {
                    throw new \RuntimeException('the mapping rejected it');
                }

                return $text;
            })
            ->into(Flaky::class)
            ->sink('out');
    }
}

#[Task(name: 'saga_step')]
final class SagaStep extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        return ['step' => $input, 'receipt' => $ctx->stableId()];
    }

    public function compensate(array $outputs, Ctx $ctx): void
    {
        foreach ($outputs as $output) {
            $ctx->emit(['undo' => $output['receipt']]);
        }
    }
}

#[\Flezha\Attribute\Operative(factoryId: 'saga_operative')]
final class SagaOperative extends \Flezha\WorkflowOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('orders')->into(SagaStep::class)->sink('done');
    }
}

// -- a tasklet's completion ------------------------------------------------
//
// One tasklet under both kinds: the SDK carries the request either way, and
// the core decides what it means, ending a workflow or failing a stream's item.

#[Task(name: 'completer')]
final class Completer extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        // Twice, so the effect shows the later call replacing the earlier one;
        // a string input completes with itself, which rides unquoted.
        $ctx->complete(['first' => $input]);
        $ctx->complete(is_string($input) ? $input : ['seeded' => $input]);
        if ($input === 'fail') {
            throw new \RuntimeException('failed after completing');
        }

        return $input;
    }
}

trait CompletingGraph
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(Completer::class)->sink('out');
    }
}

#[Operative(factoryId: 'completing_workflow')]
final class CompletingWorkflow extends WorkflowOperative
{
    use CompletingGraph;
}

#[Operative(factoryId: 'completing_stream')]
final class CompletingStream extends StreamOperative
{
    use CompletingGraph;
}

#[Task(name: 'timing', clock: Clock::EventTime)]
final class Timing extends Tasklet
{
    public function state(?string $cellKey): mixed
    {
        return ['fired' => []];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['fired'][] = $change;
    }

    public function eventTime(mixed $input): int
    {
        return (int) $input;
    }

    public function run(mixed $input, Ctx $ctx): mixed
    {
        // Both kinds, and a cancellation by the value a registration answered:
        // the processing-time one is anchored on `now()`, never an ambient
        // read, which is what makes a replayed invocation register the same
        // timestamp the live one did.
        $after = $ctx->timerAfter(1_000);
        $at = $ctx->timerAtEventTime($ctx->now() + 5_000);
        $ctx->cancelTimer('processing_time', $after);

        return null;
    }

    public function onTimer(\Flezha\Timer $timer, Ctx $ctx): void
    {
        $ctx->change(['kind' => $timer->kind, 'at' => $timer->scheduledAt, 'now' => $ctx->now()]);
    }
}

// -- the structured-workflow emit arm ----------------------------------------
//
// One released value is ONE edge output on a workflow class, untransformed and
// unkeyed, naming the producing channel and its first consumer: the driver fans
// it out from the journaled handoff. On a stream class the same topology
// releases one output per consumer, transformed and keyed. The plugin output
// rides alongside either way.

#[Task(name: 'wf_producer')]
final class WfProducer extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        $ctx->emit($input);

        return null;
    }
}

/** What a recording consumer was handed, in delivery order. */
final class Delivered
{
    /** @var list<mixed> */
    public static array $values = [];
}

#[Task(name: 'wf_b')]
final class WfB extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        Delivered::$values[] = $input;

        return null;
    }
}

#[Task(name: 'wf_c')]
final class WfC extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        Delivered::$values[] = $input;

        return null;
    }
}

trait WorkflowEmitGraph
{
    public static function channels(Builder $c): void
    {
        $edge = $c->input('in')->into(WfProducer::class)->named('handoff');
        $edge->map(static fn (mixed $v): mixed => $v + 1)->into(WfB::class);
        $edge->into(WfC::class);
        $edge->sink();
    }
}

/** An entry naming no codec, into a consumer that records what it was handed. */
#[Operative(factoryId: 'json_entry_operative')]
final class JsonEntryOperative extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(WfB::class);
    }
}

#[Operative(factoryId: 'wf_emit')]
final class WfEmitOperative extends WorkflowOperative
{
    use WorkflowEmitGraph;
}

#[Operative(factoryId: 'wf_emit_stream')]
final class WfEmitStreamOperative extends StreamOperative
{
    use WorkflowEmitGraph;
}

// -- merges, the router and fanned keys -------------------------------------

/** What it was handed, under which cell. */
#[Task(name: 'recorder', dynamicCells: true)]
final class Recorder extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        Delivered::$values[] = [$ctx->cellKey, $input];

        return null;
    }
}

#[Task(name: 'static_recorder', cellKeys: ['a', 'b'])]
final class StaticRecorder extends Tasklet
{
    public function cellsFor(mixed $input): array
    {
        return [explode(':', (string) $input)[0]];
    }

    public function run(mixed $input, Ctx $ctx): mixed
    {
        Delivered::$values[] = [$ctx->cellKey, $input];

        return null;
    }
}

/** Two entries merged into one tasklet, each tagged by its own mapping and keyed on the tag's symbol. */
#[Operative(factoryId: 'merge_operative')]
final class MergeOperative extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $ticks = $c->input('ticks');
        $quotes = $c->input('quotes');
        $c->merge()
            ->from($ticks, static fn (mixed $v): array => ['tick' => $v, 'symbol' => explode(':', (string) $v)[0]])
            ->from($quotes, static fn (mixed $v): array => ['quote' => $v, 'symbol' => explode(':', (string) $v)[0]])
            ->keyBy(static fn (array $v): string => $v['symbol'])
            ->into(Recorder::class);
    }
}

#[Operative(factoryId: 'route_latest_operative')]
final class RouteLatestOperative extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->routeLatest($c->input('config'), $c->input('trades'), static fn (array $t): string => $t['symbol'])
            ->sink('routed');
    }
}

/** An entry payload fanned into several values, keyed on the wiring and by a static key set. */
#[Operative(factoryId: 'fanned_operative')]
final class FannedOperative extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $split = static fn (mixed $v): array => $v === 'none' ? [] : explode(',', (string) $v);
        $c->input('keyed')
            ->flatMap($split)
            ->keyBy(static fn (mixed $v): string => explode(':', (string) $v)[0])
            ->into(Recorder::class);
        $c->input('static')->flatMap($split)->into(StaticRecorder::class);
    }
}

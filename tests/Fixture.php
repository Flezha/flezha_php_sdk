<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\Attribute\Relay;
use Flezha\Attribute\Task;
use Flezha\ChannelCodec;
use Flezha\Ctx;
use Flezha\Graph\Builder;
use Flezha\Graph\Edge;
use Flezha\Overflow;
use Flezha\Tasklet;

/**
 * The tasklets and relays the unit suite declares against.
 *
 * One file, so a test that needs "a stateful keyed tasklet" names one rather
 * than declaring a fourth almost-identical class.
 */

/** The echo body, for a tasklet and a short-form relay alike. */
trait Echoes
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit($input);
    }
}

#[Task(name: 'echoer')]
final class Echoer extends Tasklet
{
    use Echoes;
}

#[Task(name: 'counter')]
final class Counter extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['count' => 0, 'seen' => []];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['count']++;
        $state['seen'][] = $change['push'];
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->change(['push' => $input]);
    }

    public function onTimer(\Flezha\Timer $timer, Ctx $ctx): void
    {
        $ctx->change(['push' => 'timer:' . $timer->scheduledAt]);
    }
}

#[Task(name: 'keyed')]
final class Keyed extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['key' => $cellKey, 'values' => []];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['values'][] = $change;
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->change($input);
        $ctx->emit($input, key: (string) $ctx->cellKey);
    }
}

#[Relay(factoryId: 'echo_relay')]
final class EchoRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('input')->into(Echoer::class)->output('out');
    }
}

#[Relay(factoryId: 'counter_relay')]
final class CounterRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('input')->into(Counter::class)->output('out');
    }
}

/** A short form that only keeps state: no `output`, and a body answering nothing. */
#[Relay(factoryId: 'sf_counter', input: 'ticks', codec: ChannelCodec::Text)]
final class ShortCounter extends \Flezha\Relay
{
    public function initState(?string $cellKey): mixed
    {
        return ['seen' => []];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['seen'][] = $change;
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->change($input);
    }
}

#[Relay(factoryId: 'keyed_relay')]
final class KeyedRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('input')
            ->onFull(Overflow::DropNothing)
            ->filter(static fn (mixed $v): bool => $v !== 'drop')
            ->keyBy(static fn (mixed $v): string => explode(':', (string) $v)[0])
            ->into(Keyed::class)
            ->keyed()
            ->output('out');
    }
}

/**
 * One entry feeding an unkeyed consumer first and a keyed one after it, each
 * behind its own filter: the crossing's keys are the keyed consumer's.
 */
#[Relay(factoryId: 'split_keyed_relay')]
final class SplitKeyedRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $input = $c->input('input');
        $input->filter(static fn (mixed $v): bool => $v === 'drop')
            ->into(Echoer::class)
            ->output('dropped');
        $input->filter(static fn (mixed $v): bool => $v !== 'drop')
            ->keyBy(static fn (mixed $v): string => explode(':', (string) $v)[0])
            ->into(Keyed::class)
            ->output('out');
    }
}

/**
 * The keyed plugin emitter: one output channel, carrying the identity stamps.
 */
#[Task(name: 'stamp_emitter')]
final class StampEmitter extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
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
    }
}

/**
 * One keyed plugin channel: the topology every SDK can express, and where the
 * per-emit ordinal has to be right.
 */
#[Relay(factoryId: 'stamp_relay')]
final class StampRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('input')
            ->keyBy(static fn (mixed $v): string => 'ek')
            ->into(StampEmitter::class)
            ->keyed()
            ->output('keyed_out');
    }
}

#[Task(name: 'plain_emitter')]
final class PlainEmitter extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit('p', channel: 'plain_out');
    }
}

#[Task(name: 'fan_target')]
final class FanTarget extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
    }
}

/**
 * A tasklet owns ONE output channel, so a keyed and a non-keyed plugin
 * channel are two tasklets. The `flatMap` arm fans onto a CONSUMER edge,
 * which is where a transform chain lives: the plugin frame is serialized once
 * per emit from the value as emitted, in every SDK.
 *
 * The output is attached last, on the held handle. A transform declared behind an
 * attached output is `TransformAfterTerminal` in every SDK — the output serializes
 * the channel's own value, so the order says which value that is.
 */
#[Relay(factoryId: 'mixed_stamp_relay')]
final class MixedStampRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('input')
            ->keyBy(static fn (mixed $v): string => 'ek')
            ->branch(
                static fn (Edge $e) => $e->into(PlainEmitter::class)
                    ->output('plain_out'),
                static function (Edge $e): void {
                    $keyed = $e->into(StampEmitter::class)->keyed();
                    $keyed->flatMap(static fn (mixed $v): array => [$v . '1', $v . '2'])
                        ->into(FanTarget::class);
                    $keyed->output('keyed_out');
                },
            );
    }
}

#[Task(name: 'poison')]
final class Poison extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        if ($input === 'poison') {
            throw new \Flezha\Exception\NonRetryableException('poisoned payload');
        }

        $ctx->emit($input);
    }
}

#[Relay(factoryId: 'poison_relay')]
final class PoisonRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('input')->into(Poison::class)->output('out');
    }
}

/** Throws on a value that did decode: a handler's failure, not the entrance's. */
#[Task(name: 'flaky')]
final class Flaky extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        if ($input === 'boom') {
            throw new \RuntimeException('the handler rejected it');
        }

        $ctx->emit($input);
    }
}

/** A text entry whose edge transform rejects `poison`, into a handler that rejects `boom`. */
#[Relay(factoryId: 'flaky_entry_relay')]
final class FlakyEntryRelay extends \Flezha\Relay
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
            ->output('out');
    }
}

#[Task(name: 'saga_step')]
final class SagaStep extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit(['step' => $input, 'receipt' => $ctx->stableId()]);
    }

    public function compensate(array $outputs, Ctx $ctx): void
    {
        foreach ($outputs as $output) {
            $ctx->emit(['undo' => $output['receipt']]);
        }
    }
}

#[\Flezha\Attribute\Relay(factoryId: 'saga_relay', run: 'steps')]
final class SagaRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('orders')->into(SagaStep::class)->output('done');
    }
}

// -- a tasklet's completion ------------------------------------------------
//
// One tasklet under both kinds: the SDK carries the request either way, and
// the core decides what it means, ending a steps relay or failing a flow relay's item.

#[Task(name: 'completer')]
final class Completer extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        // Twice, so the effect shows the later call replacing the earlier one;
        // a string input completes with itself, which rides unquoted.
        $ctx->complete(['first' => $input]);
        $ctx->complete(is_string($input) ? $input : ['seeded' => $input]);
        if ($input === 'fail') {
            throw new \RuntimeException('failed after completing');
        }

        $ctx->emit($input);
    }
}

trait CompletingGraph
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(Completer::class)->output('out');
    }
}

#[Relay(factoryId: 'completing_steps', run: 'steps')]
final class CompletingSteps extends \Flezha\Relay
{
    use CompletingGraph;
}

#[Relay(factoryId: 'completing_flow')]
final class CompletingFlow extends \Flezha\Relay
{
    use CompletingGraph;
}

#[Task(name: 'timing')]
final class Timing extends Tasklet
{
    public function initState(?string $cellKey): mixed
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

    public function run(mixed $input, Ctx $ctx): void
    {
        // Both kinds, and a cancellation by the value a registration answered:
        // the processing-time one is anchored on `now()`, never an ambient
        // read, which is what makes a replayed invocation register the same
        // timestamp the live one did.
        $after = $ctx->timerAfter(1_000);
        $at = $ctx->timerAtEventTime($ctx->now() + 5_000);
        $ctx->cancelTimer('processing_time', $after);
    }

    public function onTimer(\Flezha\Timer $timer, Ctx $ctx): void
    {
        $ctx->change(['kind' => $timer->kind, 'at' => $timer->scheduledAt, 'now' => $ctx->now()]);
    }
}

trait TimingGraph
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(Timing::class);
    }
}

#[Relay(factoryId: 'timing_steps', run: 'steps')]
final class TimingSteps extends \Flezha\Relay
{
    use TimingGraph;
}

#[Relay(factoryId: 'timing_flow')]
final class TimingFlow extends \Flezha\Relay
{
    use TimingGraph;
}

// -- the structured-steps emit arm ----------------------------------------
//
// One released value is ONE edge output on a steps class, untransformed and
// unkeyed, naming the producing channel and its first consumer: the driver fans
// it out from the journaled handoff. On a flow class the same topology
// releases one output per consumer, transformed and keyed. The plugin output
// rides alongside either way.

#[Task(name: 'steps_producer')]
final class StepsProducer extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit($input);
    }
}

/** What a recording consumer was handed, in delivery order. */
final class Delivered
{
    /** @var list<mixed> */
    public static array $values = [];
}

#[Task(name: 'steps_b')]
final class StepsB extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        Delivered::$values[] = $input;
    }
}

#[Task(name: 'steps_c')]
final class StepsC extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        Delivered::$values[] = $input;
    }
}

trait StepsEmitGraph
{
    public static function channels(Builder $c): void
    {
        $edge = $c->input('in')->into(StepsProducer::class)->named('handoff');
        $edge->map(static fn (mixed $v): mixed => $v + 1)->into(StepsB::class);
        $edge->into(StepsC::class);
        $edge->output();
    }
}

/** An entry naming no codec, into a consumer that records what it was handed. */
#[Relay(factoryId: 'json_entry_relay')]
final class JsonEntryRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(StepsB::class);
    }
}

#[Relay(factoryId: 'steps_emit', run: 'steps')]
final class StepsEmitRelay extends \Flezha\Relay
{
    use StepsEmitGraph;
}

#[Relay(factoryId: 'steps_emit_flow')]
final class StepsEmitFlowRelay extends \Flezha\Relay
{
    use StepsEmitGraph;
}

/** A chained loop whose terminal nothing consumes: its value is the pass's carry. */
#[Relay(factoryId: 'steps_carry', run: ['steps' => ['each' => 'chained']])]
final class StepsCarryRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(StepsProducer::class)->named('carry');
    }
}

// -- merges, the router and fanned keys -------------------------------------

/** What it was handed, under which cell. */
#[Task(name: 'recorder')]
final class Recorder extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        Delivered::$values[] = [$ctx->cellKey, $input];
    }
}

#[Task(name: 'static_recorder', cellKeys: ['a', 'b'])]
final class StaticRecorder extends Tasklet
{
    public function cellsFor(mixed $input): array
    {
        return [explode(':', (string) $input)[0]];
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        Delivered::$values[] = [$ctx->cellKey, $input];
    }
}

/** Two entries merged into one tasklet, each tagged by its own mapping and keyed on the tag's symbol. */
#[Relay(factoryId: 'merge_relay')]
final class MergeRelay extends \Flezha\Relay
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

#[Relay(factoryId: 'route_latest_relay')]
final class RouteLatestRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->routeLatest($c->input('config'), $c->input('trades'), static fn (array $t): string => $t['symbol'])
            ->output('routed');
    }
}

/** An entry payload fanned into several values, keyed on the wiring and by a static key set. */
#[Relay(factoryId: 'fanned_relay')]
final class FannedRelay extends \Flezha\Relay
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

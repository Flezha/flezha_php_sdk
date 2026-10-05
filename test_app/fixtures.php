<?php

declare(strict_types=1);

/**
 * Twins of the rust_runner e2e fixtures (test/apps/rust_runner/src/main.rs)
 * under the same factory ids, tasklet names, channel names and output bytes.
 *
 * Entry payloads and plugin frames are UTF-8 text on the wire, the same as the
 * reference: the SDK's default codec is JSON, so every fixture that exchanges
 * bytes with another SDK or with a plugin declares its own decoder and
 * encoder. An inter-tasklet hop between two PHP tasklets carries JSON,
 * which no one else reads.
 */

namespace Flezha\TestApp;

use Flezha\Attribute\Relay;
use Flezha\Attribute\Task;
use Flezha\ChannelCodec;
use Flezha\Ctx;
use Flezha\Exception\NonRetryableException;
use Flezha\Graph\Builder;
use Flezha\Overflow;
use Flezha\Tasklet;
use Flezha\Timer;

/** The readings of a text input every fixture that talks to another SDK shares. */
final class Wire
{
    /** The key convention every keyed fixture shares: text before the first ':'. */
    public static function prefix(mixed $input): string
    {
        return explode(':', (string) $input)[0];
    }

    /** An entry as the reference's `i64`: an unparsable input reads 0. */
    public static function int(mixed $input): int
    {
        return preg_match('/^-?\d+$/', (string) $input) === 1 ? (int) $input : 0;
    }
}

// Settings trees the environment chooses, defined before any class registers:
// an attribute argument is read then, and a constant is what it may name.
//
// A kept instance that sleeps after `FLEZHA_FIXTURE_SLEEP_AFTER_MS`, so a test
// can watch one sleep; the class default when the variable is unset.
define(__NAMESPACE__ . '\\FIXTURE_DURABLE', Wire::int(getenv('FLEZHA_FIXTURE_SLEEP_AFTER_MS')) > 0
    ? ['local' => ['sleep_after' => Wire::int(getenv('FLEZHA_FIXTURE_SLEEP_AFTER_MS'))]]
    : 'local');

// `bench_checkpoint`'s acknowledgement: every `FLEZHA_BENCH_CHECKPOINT_EVERY`
// inputs, and with `FLEZHA_BENCH_CHECKPOINT_OUTPUTS=at_checkpoint` its outputs
// held until the interval closes, for at most `FLEZHA_BENCH_CHECKPOINT_DELAY_MS`.
define(__NAMESPACE__ . '\\BENCH_CHECKPOINT', ['checkpoint' => [
    'every' => Wire::int(getenv('FLEZHA_BENCH_CHECKPOINT_EVERY')) ?: 500,
    ...(getenv('FLEZHA_BENCH_CHECKPOINT_OUTPUTS') === 'at_checkpoint'
        ? ['outputs' => 'at_checkpoint', 'max_delay_ms' => Wire::int(getenv('FLEZHA_BENCH_CHECKPOINT_DELAY_MS')) ?: 50]
        : []),
]]);

// -- do_nothing / bench_echo -------------------------------------------------

#[Task(name: 'do_nothing_tasklet')]
final class DoNothing extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit($input);
    }
}

#[Relay(factoryId: 'do_nothing')]
final class DoNothingRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)
            ->into(DoNothing::class)
            ->sink('echo');
    }
}

/**
 * Echo with a declared DropNothing on its entry mailbox. `do_nothing` is the
 * merge-blocking bench factory and must not change; this keeps the round-trip
 * bench's back-pressure posture independent of it.
 */
#[Relay(factoryId: 'bench_echo')]
final class BenchEchoRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)->onFull(Overflow::DropNothing)
            ->into(DoNothing::class)
            ->sink('echo');
    }
}

// -- compare_filter ----------------------------------------------------------

/**
 * State seeded by the controller's init values and then only read: there is
 * nothing to journal, so the tasklet declares no change fold.
 */
#[Task(name: 'compare_filter_tasklet')]
final class CompareFilter extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['comparable' => 'default', 'inverted' => false];
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        if ($ctx->state['comparable'] === $input) {
            $ctx->emit($input);
        }
    }
}

#[Relay(factoryId: 'compare_filter')]
final class CompareFilterRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)
            ->into(CompareFilter::class)
            ->sink('sink');
    }
}

// -- static_cell / sugar_static_cell / mixed_relay -------------------

#[Task(name: 'static_entry_tasklet', cellKeys: ['a', 'b', 'c', 'd'])]
class StaticEntry extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['seen' => []];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['seen'][] = $change['push'];
    }

    public function cellsFor(mixed $input): array
    {
        $first = substr((string) $input, 0, 1);

        return [in_array($first, ['a', 'b', 'c', 'd'], true) ? $first : 'd'];
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->change(['push' => $input]);

        $ctx->emit($input);
    }
}

/** A subclass keeps every declaration its parent made, but not its node name. */
#[Task(name: 'macro_static_entry_tasklet')]
final class MacroStaticEntry extends StaticEntry
{
}

/** The body that answers its input unchanged, for a tasklet and a short form alike. */
trait PassesThrough
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit($input);
    }
}

#[Task(name: 'pass_through')]
class PassThrough extends Tasklet
{
    use PassesThrough;
}

#[Task(name: 'static_downstream_tasklet')]
final class StaticDownstream extends PassThrough
{
}

#[Task(name: 'macro_static_downstream_tasklet')]
final class MacroStaticDownstream extends PassThrough
{
}

#[Relay(factoryId: 'static_cell')]
final class StaticCellRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)
            ->into(StaticEntry::class)->named('hop')
            ->into(StaticDownstream::class)
            ->sink('kafka');
    }
}

/**
 * The reference pipeline sugar leaves the hop unnamed and lets the sink name
 * the producing channel.
 */
#[Relay(factoryId: 'sugar_static_cell')]
final class SugarStaticCellRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)
            ->into(MacroStaticEntry::class)
            ->into(MacroStaticDownstream::class)
            ->sink('test_kafka');
    }
}

#[Task(name: 'mixed_macro_entry_tasklet')]
final class MixedMacroEntry extends PassThrough
{
}

#[Task(name: 'mixed_hand_downstream')]
final class MixedHandDownstream extends PassThrough
{
}

#[Relay(factoryId: 'mixed_relay')]
final class MixedRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)
            ->into(MixedMacroEntry::class)->named('hop')
            ->into(MixedHandDownstream::class)
            ->sink('kafka');
    }
}

// -- the §5 short forms ------------------------------------------------------
//
// One class each: a relay that defines its one tasklet's `run` instead
// of `channels()`, and `runner.php` registers the class itself. No tasklet
// class stands beside them.

#[Relay(factoryId: 'sugar_do_nothing', sink: 'sugar_do_nothing_echo', codec: ChannelCodec::Text)]
final class SugarDoNothing extends \Flezha\Relay
{
    use PassesThrough;
}

#[Relay(factoryId: 'sugar_store_input', sink: 'sugar_store_sink', codec: ChannelCodec::Text)]
final class SugarStoreInput extends \Flezha\Relay
{
    public function initState(?string $cellKey): mixed
    {
        return ['text_inputs' => []];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['text_inputs'][] = $change['push'];
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->change(['push' => $input]);

        $ctx->emit($input);
    }
}

#[Relay(
    factoryId: 'sugar_named_input',
    sink: 'sugar_named_input_sink',
    input: 'prices',
    codec: ChannelCodec::Text,
)]
final class SugarNamedInput extends \Flezha\Relay
{
    use PassesThrough;
}

#[Relay(
    factoryId: 'sugar_steps',
    run: 'steps',
    durable: FIXTURE_DURABLE,
    sink: 'sugar_steps_placed',
    input: 'order',
    codec: ChannelCodec::Text,
)]
final class SugarSteps extends \Flezha\Relay
{
    use PassesThrough;
}

/** The loop mode, the one class-level setting a short form keeps. */
#[Relay(
    factoryId: 'sugar_steps_per_input',
    run: ['steps' => ['each' => 'per_input']],
    durable: FIXTURE_DURABLE,
    sink: 'sugar_steps_per_input_out',
    codec: ChannelCodec::Text,
)]
final class SugarStepsPerInput extends \Flezha\Relay
{
    use PassesThrough;
}

/** No `sink`: the tasklet only keeps what it is sent, and answers nothing. */
#[Relay(factoryId: 'sugar_state_only', input: 'ticks', codec: ChannelCodec::Text)]
final class SugarStateOnly extends \Flezha\Relay
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

// -- hybrid_channel ----------------------------------------------------------
//
// One emit reaching two destinations: an inter-tasklet consumer and a plugin
// wire with its own serializer. The serializer refuses a sentinel value, so an
// user can observe end to end that a refusal is isolated to the emit rather
// than failing the item.

#[Task(name: 'hybrid_producer_tasklet')]
final class HybridProducer extends Tasklet
{
    public const SENTINEL = 0xDEADBEEF;

    public function run(mixed $input, Ctx $ctx): void
    {
        $parsed = filter_var((string) $input, FILTER_VALIDATE_INT);
        $value = is_int($parsed) && $parsed >= 0 && $parsed <= 0xFFFFFFFF ? $parsed : 0;

        try {
            $ctx->emit($value);
        } catch (\Throwable $e) {
            fwrite(STDERR, "hybrid_producer_tasklet: serializer refused an emit: {$e->getMessage()}\n");
        }
    }
}

#[Task(name: 'hybrid_consumer_tasklet')]
final class HybridConsumer extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        echo "hybrid_consumer_tasklet: received $input\n";
    }
}

#[Relay(factoryId: 'hybrid_channel')]
final class HybridChannelRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)->into(HybridProducer::class)->branch(
            // The consumer edge first, so a refused plugin serialization leaves
            // the in-process fan-out already recorded — the reference's isolation.
            static fn ($edge) => $edge->named('hybrid_indicator')->into(HybridConsumer::class),
            static fn ($edge) => $edge->sink(static function (mixed $value): string {
                if ($value === HybridProducer::SENTINEL) {
                    throw new \RuntimeException('hybrid_indicators: serializer rejected sentinel 0xDEADBEEF');
                }

                return pack('N', $value);
            }),
        );
    }
}

// -- merged_input ------------------------------------------------------------
//
// Two entry channels merged into one tasklet. Each source's mapping tags the
// value with the channel it came from, the merge keys on the symbol the mapping
// read, and the emitted line is `<source>:<payload>`.

#[Task(name: 'merge_label_tasklet')]
final class MergeLabel extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit("{$input['source']}:{$input['payload']}");
    }
}

#[Relay(factoryId: 'merged_input')]
final class MergedInputRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $ticks = $c->input('ticks', codec: ChannelCodec::Text);
        $quotes = $c->input('quotes', codec: ChannelCodec::Text);
        $c->merge()
            ->from($ticks, self::tagged('ticks'))
            ->from($quotes, self::tagged('quotes'))
            ->keyBy(static fn (array $value): string => $value['symbol'])
            ->into(MergeLabel::class)
            ->keyed()
            ->sink('merged');
    }

    /** @return callable(string):array{source: string, symbol: string, payload: string} */
    private static function tagged(string $source): callable
    {
        return static fn (string $payload): array => [
            'source' => $source,
            'symbol' => Wire::prefix($payload),
            'payload' => $payload,
        ];
    }
}

// -- transform_chain ---------------------------------------------------------
//
// Every value transform on one edge: `drop` is filtered out, a comma list fans
// out into one value per element, each is upper-cased and keyed on its prefix.

#[Task(name: 'transform_label_tasklet')]
final class TransformLabel extends PassThrough
{
}

#[Relay(factoryId: 'transform_chain')]
final class TransformChainRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('trades', codec: ChannelCodec::Text)
            ->filter(static fn (string $trade): bool => $trade !== 'drop')
            ->flatMap(static fn (string $trade): array => explode(',', $trade))
            ->map(strtoupper(...))
            ->keyBy(Wire::prefix(...))
            ->into(TransformLabel::class)
            ->keyed()
            ->sink('transformed');
    }
}

// -- cell_stress -----------------------------------------------------------

#[Task(name: 'stress_a', cellKeys: ['p0', 'p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'p7'])]
final class StressA extends Tasklet
{
    public function cellsFor(mixed $input): array
    {
        return ['p0', 'p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'p7'];
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit($input);
    }
}

#[Task(name: 'stress_to_p0', cellKeys: ['p0', 'p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'p7'])]
class StressToP0 extends Tasklet
{
    public function cellsFor(mixed $input): array
    {
        return ['p0'];
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit($input);
    }
}

#[Task(name: 'stress_b')]
final class StressB extends StressToP0
{
}

#[Task(name: 'stress_c')]
final class StressC extends StressToP0
{
}

#[Task(name: 'stress_d')]
final class StressD extends StressToP0
{
}

#[Relay(factoryId: 'cell_stress')]
final class CellStressRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)
            ->into(StressA::class)->named('a_to_b')
            ->into(StressB::class)->named('b_to_c')
            ->into(StressC::class)->named('c_to_d')
            ->into(StressD::class)
            ->sink('echo');
    }
}

// -- candle ------------------------------------------------------------------
//
// Late-input correction is about fold ORDER, and the only way to prove that is
// to look at the order. Send 100, 300, then the late 200. Without correction the
// fold order is [100, 300, 200] and the candle is permanently wrong. With it,
// the engine rewinds behind 200, replays the retained input log sorted by event
// time, and the fold order becomes [100, 200, 300].

#[Task(name: 'candle_tasklet')]
final class Candle extends Tasklet
{
    /** Event times in fold order: the assertion surface. */
    public function initState(?string $cellKey): mixed
    {
        return ['folded' => []];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['folded'][] = $change['fold'];
    }

    public function eventTime(mixed $input): int
    {
        return Wire::int(explode(':', (string) $input, 2)[0]);
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        // `$ctx->now()` IS the extracted event time under an event-time clock.
        $eventTime = $ctx->now();
        $ctx->change(['fold' => $eventTime]);
        // One row per candle bucket, so a corrected replay overwrites by key
        // rather than appending a second row.
        $bucket = intdiv($eventTime, 1000);
        $ctx->emitKeyed("candle/$bucket", "$bucket=$input");
    }
}

#[Relay(factoryId: 'candle', run: ['flow' => ['exactly' => ['effectively_once' => ['correction_ms' => 600_000]]]])]
final class CandleRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)
            ->into(Candle::class)->keyed()
            ->sink('sink');
    }
}

// -- session_timeout ---------------------------------------------------------
//
// The worked session-timeout shape: each input pushes the idle deadline out,
// and the firing closes the session. Single-cell so the e2e's crash-resume
// half exercises a durable Cell. The timeout is read from
// `TIMER_TEST_TIMEOUT_MS` (the e2e sets it short).

#[Task(name: 'session_timeout_tasklet')]
final class SessionTimeout extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['touched' => [], 'closed_at' => []];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        if (array_key_exists('touched', $change)) {
            $state['touched'][] = $change['touched'];
        } else {
            $state['closed_at'][] = $change['closed'];
        }
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->change(['touched' => $input]);
        // A processing-time timer, because the answer to "nothing has arrived"
        // cannot be an event-time one: nothing would advance the clock.
        $ctx->timerAfter(Wire::int(getenv('TIMER_TEST_TIMEOUT_MS')) ?: 1000);
    }

    public function onTimer(Timer $timer, Ctx $ctx): void
    {
        $ctx->change(['closed' => $timer->scheduledAt]);
        $ctx->emit("closed@{$timer->scheduledAt}");
    }
}

#[Relay(factoryId: 'session_timeout')]
final class SessionTimeoutRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)
            ->into(SessionTimeout::class)
            ->sink('kafka');
    }
}

// -- bounded_mailbox_{drop_nothing,drop_newest,drop_oldest} ------------------

/** The capacity and the fold reach each subclass by inheritance. */
#[Task(name: 'bounded_mailbox', mailboxCapacity: 4)]
class BoundedMailbox extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['processed' => 0];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['processed']++;
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->change(['processed' => true]);

        $ctx->emit($input);
    }
}

#[Task(name: 'drop_nothing_test_tasklet')]
final class DropNothingMailbox extends BoundedMailbox
{
}

#[Task(name: 'drop_newest_test_tasklet')]
final class DropNewestMailbox extends BoundedMailbox
{
}

#[Task(name: 'drop_oldest_test_tasklet')]
final class DropOldestMailbox extends BoundedMailbox
{
}

#[Relay(factoryId: 'bounded_mailbox_drop_nothing')]
final class BoundedMailboxDropNothingRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('drop_nothing_input', codec: ChannelCodec::Text)->onFull(Overflow::DropNothing)
            ->into(DropNothingMailbox::class)
            ->sink('drop_nothing_echo');
    }
}

#[Relay(factoryId: 'bounded_mailbox_drop_newest')]
final class BoundedMailboxDropNewestRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('drop_newest_input', codec: ChannelCodec::Text)->onFull(Overflow::DropNewest)
            ->into(DropNewestMailbox::class)
            ->sink('drop_newest_echo');
    }
}

#[Relay(factoryId: 'bounded_mailbox_drop_oldest')]
final class BoundedMailboxDropOldestRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('drop_oldest_input', codec: ChannelCodec::Text)->onFull(Overflow::DropOldest)
            ->into(DropOldestMailbox::class)
            ->sink('drop_oldest_echo');
    }
}

// -- partition_pass ---------------------------------------------------------
//
// The partitioned-relays upstream: keyed on the wiring so it can be
// partitioned at all, and emitting keyed so a partitioned forward target can
// resolve the owning member. The plugin channel is named `input` because a
// forwarded frame keeps its producing channel's name, and that is what selects
// the receiving relay's entry channel — so the entry here is `in`.

#[Task(name: 'partition_pass_tasklet')]
final class PartitionPass extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['seen' => []];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['seen'][] = $change['push'];
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->change(['push' => $input]);
        $ctx->emitKeyed(Wire::prefix($input), $input);
    }
}

#[Relay(factoryId: 'partition_pass')]
final class PartitionPassRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)->keyBy(Wire::prefix(...))
            ->into(PartitionPass::class)->keyed()
            ->sink('input');
    }
}

// -- dynamic_cell ----------------------------------------------------------

// Rust reads the eviction TTL from `DYN_TEST_TTL_MS`; an attribute argument
// must be a constant expression, so this fixture declares none. Only the
// reference-runtime scenario arms a TTL, so nothing here reads one.
#[Task(name: 'dynamic_cell_tasklet')]
final class DynamicCell extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['seen' => []];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['seen'][] = $change['push'];
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->change(['push' => $input]);

        $ctx->emit($input);
    }
}

#[Relay(factoryId: 'dynamic_cell')]
final class DynamicCellRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)->onFull(Overflow::DropNothing)
            // Filtered before keying: a dropped value answers no key, so the
            // core never mints a cell for it.
            ->filter(static fn (mixed $input): bool => $input !== 'drop')
            ->keyBy(Wire::prefix(...))
            ->into(DynamicCell::class)
            ->sink('kafka');
    }
}

// -- call_response -------------------------------------------------------------
//
// Relays an outside caller calls and whose sink output answers it: from the
// first tasklet, from a downstream one, and from whichever of two filtered
// paths the input took. `call_turn` is the steps
// one, in fixtures_steps.php.

#[Task(name: 'call_echo')]
final class CallEchoStep extends PassThrough
{
}

#[Relay(factoryId: 'call_echo')]
final class CallEcho extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)->into(CallEchoStep::class)->sink();
    }
}

#[Task(name: 'call_parse')]
final class CallParse extends PassThrough
{
}

#[Task(name: 'call_answer')]
final class CallAnswer extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit("re:$input");
    }
}

#[Relay(factoryId: 'call_downstream')]
final class CallDownstream extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(CallParse::class)
            ->into(CallAnswer::class)
            ->sink();
    }
}

// An input starting `no` takes the refused path, every other the accepted one.
#[Task(name: 'call_admit')]
final class CallAdmit extends PassThrough
{
}

#[Task(name: 'call_accept')]
final class CallAccept extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit("accepted:$input");
    }
}

#[Task(name: 'call_refuse')]
final class CallRefuse extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit("refused:$input");
    }
}

#[Relay(factoryId: 'call_split')]
final class CallSplit extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $admitted = $c->input('in', codec: ChannelCodec::Text)->into(CallAdmit::class);
        $admitted->filter(static fn (mixed $input): bool => !str_starts_with($input, 'no'))
            ->into(CallAccept::class)
            ->sink();
        $admitted->filter(static fn (mixed $input): bool => str_starts_with($input, 'no'))
            ->into(CallRefuse::class)
            ->sink();
    }
}

/** Every relay this app registers, in declaration order. */
final class Fixtures
{
    /** @return list<class-string<\Flezha\Relay>> */
    public static function all(): array
    {
        return [
            DoNothingRelay::class,
            BenchEchoRelay::class,
            CompareFilterRelay::class,
            StaticCellRelay::class,
            SugarStaticCellRelay::class,
            MixedRelay::class,
            HybridChannelRelay::class,
            MergedInputRelay::class,
            TransformChainRelay::class,
            CellStressRelay::class,
            BenchKeyedNoPersistRelay::class,
            StoreInputRelay::class,
            StatefulCounterRelay::class,
            PersistenceOffCounterRelay::class,
            SleepingCounterRelay::class,
            CoalescingCounterRelay::class,
            ResumeProbeRelay::class,
            BenchKeyedRelay::class,
            SchemaEvolutionV1Relay::class,
            SchemaEvolutionV2Relay::class,
            TierStoreRelay::class,
            RemoteTierStoreRelay::class,
            TierChainRelay::class,
            CheckpointStoreRelay::class,
            CheckpointBatchedRelay::class,
            BenchCheckpointRelay::class,
            CandleRelay::class,
            PoisonProbeRelay::class,
            WedgeProbeRelay::class,
            SessionTimeoutRelay::class,
            BoundedMailboxDropNothingRelay::class,
            BoundedMailboxDropNewestRelay::class,
            BoundedMailboxDropOldestRelay::class,
            PartitionPassRelay::class,
            DynamicCellRelay::class,
            SpawnRequesterRelay::class,
            SpawnHoldRequesterRelay::class,
            SpawnOnceRequesterRelay::class,
            CallEcho::class,
            CallDownstream::class,
            CallSplit::class,
            // The §5 short forms.
            SugarDoNothing::class,
            SugarStoreInput::class,
            SugarNamedInput::class,
            SugarSteps::class,
            SugarStepsPerInput::class,
            SugarStateOnly::class,
        ];
    }
}

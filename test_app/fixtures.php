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

use Flezha\Attribute\Operative;
use Flezha\Attribute\Task;
use Flezha\Attribute\Workflow;
use Flezha\ChannelCodec;
use Flezha\Clock;
use Flezha\Ctx;
use Flezha\Exception\NonRetryableException;
use Flezha\Graph\Builder;
use Flezha\LoopMode;
use Flezha\Overflow;
use Flezha\Tasklet;
use Flezha\StreamOperative;
use Flezha\Timer;
use Flezha\WorkflowOperative;

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

// -- do_nothing / bench_echo -------------------------------------------------

#[Task(name: 'do_nothing_tasklet')]
final class DoNothing extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        return $input;
    }
}

#[Operative(factoryId: 'do_nothing')]
final class DoNothingOperative extends StreamOperative
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
#[Operative(factoryId: 'bench_echo')]
final class BenchEchoOperative extends StreamOperative
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
    public function state(?string $cellKey): mixed
    {
        return ['comparable' => 'default', 'inverted' => false];
    }

    public function run(mixed $input, Ctx $ctx): mixed
    {
        return $ctx->state['comparable'] === $input ? $input : null;
    }
}

#[Operative(factoryId: 'compare_filter')]
final class CompareFilterOperative extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)
            ->into(CompareFilter::class)
            ->sink('sink');
    }
}

// -- static_cell / sugar_static_cell / mixed_operative -------------------

#[Task(name: 'static_entry_tasklet', cellKeys: ['a', 'b', 'c', 'd'])]
class StaticEntry extends Tasklet
{
    public function state(?string $cellKey): mixed
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

    public function run(mixed $input, Ctx $ctx): mixed
    {
        $ctx->change(['push' => $input]);

        return $input;
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
    public function run(mixed $input, Ctx $ctx): mixed
    {
        return $input;
    }
}

#[Task]
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

#[Operative(factoryId: 'static_cell')]
final class StaticCellOperative extends StreamOperative
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
#[Operative(factoryId: 'sugar_static_cell')]
final class SugarStaticCellOperative extends StreamOperative
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

#[Operative(factoryId: 'mixed_operative')]
final class MixedOperative extends StreamOperative
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
// One class each: an operative that defines its one tasklet's `run` instead
// of `channels()`, and `runner.php` registers the class itself. No tasklet
// class stands beside them.

#[Operative(factoryId: 'sugar_do_nothing', sink: 'sugar_do_nothing_echo', codec: ChannelCodec::Text)]
final class SugarDoNothing extends StreamOperative
{
    use PassesThrough;
}

#[Operative(factoryId: 'sugar_store_input', sink: 'sugar_store_sink', codec: ChannelCodec::Text)]
final class SugarStoreInput extends StreamOperative
{
    public function state(?string $cellKey): mixed
    {
        return ['text_inputs' => []];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['text_inputs'][] = $change['push'];
    }

    public function run(mixed $input, Ctx $ctx): mixed
    {
        $ctx->change(['push' => $input]);

        return $input;
    }
}

#[Operative(
    factoryId: 'sugar_named_input',
    sink: 'sugar_named_input_sink',
    input: 'prices',
    codec: ChannelCodec::Text,
)]
final class SugarNamedInput extends StreamOperative
{
    use PassesThrough;
}

#[Operative(factoryId: 'sugar_workflow', sink: 'sugar_workflow_placed', input: 'order', codec: ChannelCodec::Text)]
final class SugarWorkflow extends WorkflowOperative
{
    use PassesThrough;
}

/** The loop mode, the one class-level setting a short form keeps. */
#[Operative(factoryId: 'sugar_workflow_per_input', sink: 'sugar_workflow_per_input_out', codec: ChannelCodec::Text)]
#[Workflow(loopMode: LoopMode::PerInput)]
final class SugarWorkflowPerInput extends WorkflowOperative
{
    use PassesThrough;
}

/** No `sink`: the tasklet only keeps what it is sent, and answers nothing. */
#[Operative(factoryId: 'sugar_state_only', input: 'ticks', codec: ChannelCodec::Text)]
final class SugarStateOnly extends StreamOperative
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

// -- hybrid_channel ----------------------------------------------------------
//
// One emit reaching two destinations: an inter-tasklet consumer and a plugin
// wire with its own serializer. The serializer refuses a sentinel value, so an
// operator can observe end to end that a refusal is isolated to the emit rather
// than failing the item.

#[Task(name: 'hybrid_producer_tasklet')]
final class HybridProducer extends Tasklet
{
    public const SENTINEL = 0xDEADBEEF;

    public function run(mixed $input, Ctx $ctx): mixed
    {
        $parsed = filter_var((string) $input, FILTER_VALIDATE_INT);
        $value = is_int($parsed) && $parsed >= 0 && $parsed <= 0xFFFFFFFF ? $parsed : 0;

        try {
            $ctx->emit($value);
        } catch (\Throwable $e) {
            fwrite(STDERR, "hybrid_producer_tasklet: serializer refused an emit: {$e->getMessage()}\n");
        }

        return null;
    }
}

#[Task(name: 'hybrid_consumer_tasklet')]
final class HybridConsumer extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        echo "hybrid_consumer_tasklet: received $input\n";

        return null;
    }
}

#[Operative(factoryId: 'hybrid_channel')]
final class HybridChannelOperative extends StreamOperative
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

#[Task(name: 'merge_label_tasklet', dynamicCells: true)]
final class MergeLabel extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        return "{$input['source']}:{$input['payload']}";
    }
}

#[Operative(factoryId: 'merged_input')]
final class MergedInputOperative extends StreamOperative
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

#[Task(name: 'transform_label_tasklet', dynamicCells: true)]
final class TransformLabel extends PassThrough
{
}

#[Operative(factoryId: 'transform_chain')]
final class TransformChainOperative extends StreamOperative
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

    public function run(mixed $input, Ctx $ctx): mixed
    {
        return $input;
    }
}

#[Task(cellKeys: ['p0', 'p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'p7'])]
class StressToP0 extends Tasklet
{
    public function cellsFor(mixed $input): array
    {
        return ['p0'];
    }

    public function run(mixed $input, Ctx $ctx): mixed
    {
        return $input;
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

#[Operative(factoryId: 'cell_stress')]
final class CellStressOperative extends StreamOperative
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

#[Task(name: 'candle_tasklet', clock: Clock::EventTime)]
final class Candle extends Tasklet
{
    /** Event times in fold order: the assertion surface. */
    public function state(?string $cellKey): mixed
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

    public function run(mixed $input, Ctx $ctx): mixed
    {
        // `$ctx->now()` IS the extracted event time under an event-time clock.
        $eventTime = $ctx->now();
        $ctx->change(['fold' => $eventTime]);
        // One row per candle bucket, so a corrected replay overwrites by key
        // rather than appending a second row.
        $bucket = intdiv($eventTime, 1000);
        $ctx->emitKeyed("candle/$bucket", "$bucket=$input");

        return null;
    }
}

#[Operative(factoryId: 'candle')]
final class CandleOperative extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->effectivelyOnce()->correctionWindow(600_000);
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
    public function state(?string $cellKey): mixed
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

    public function run(mixed $input, Ctx $ctx): mixed
    {
        $ctx->change(['touched' => $input]);
        // A processing-time timer, because the answer to "nothing has arrived"
        // cannot be an event-time one: nothing would advance the clock.
        $ctx->timerAfter(Wire::int(getenv('TIMER_TEST_TIMEOUT_MS')) ?: 1000);

        return null;
    }

    public function onTimer(Timer $timer, Ctx $ctx): void
    {
        $ctx->change(['closed' => $timer->scheduledAt]);
        $ctx->emit("closed@{$timer->scheduledAt}");
    }
}

#[Operative(factoryId: 'session_timeout')]
final class SessionTimeoutOperative extends StreamOperative
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
#[Task(mailboxCapacity: 4)]
class BoundedMailbox extends Tasklet
{
    public function state(?string $cellKey): mixed
    {
        return ['processed' => 0];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['processed']++;
    }

    public function run(mixed $input, Ctx $ctx): mixed
    {
        $ctx->change(['processed' => true]);

        return $input;
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

#[Operative(factoryId: 'bounded_mailbox_drop_nothing')]
final class BoundedMailboxDropNothingOperative extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('drop_nothing_input', codec: ChannelCodec::Text)->onFull(Overflow::DropNothing)
            ->into(DropNothingMailbox::class)
            ->sink('drop_nothing_echo');
    }
}

#[Operative(factoryId: 'bounded_mailbox_drop_newest')]
final class BoundedMailboxDropNewestOperative extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('drop_newest_input', codec: ChannelCodec::Text)->onFull(Overflow::DropNewest)
            ->into(DropNewestMailbox::class)
            ->sink('drop_newest_echo');
    }
}

#[Operative(factoryId: 'bounded_mailbox_drop_oldest')]
final class BoundedMailboxDropOldestOperative extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('drop_oldest_input', codec: ChannelCodec::Text)->onFull(Overflow::DropOldest)
            ->into(DropOldestMailbox::class)
            ->sink('drop_oldest_echo');
    }
}

// -- partition_relay ---------------------------------------------------------
//
// The partitioned-operatives upstream: keyed on the wiring so it can be
// partitioned at all, and emitting keyed so a partitioned forward target can
// resolve the owning member. The plugin channel is named `input` because a
// forwarded frame keeps its producing channel's name, and that is what selects
// the receiving operative's entry channel — so the entry here is `in`.

#[Task(name: 'partition_relay_tasklet', dynamicCells: true)]
final class PartitionRelay extends Tasklet
{
    public function state(?string $cellKey): mixed
    {
        return ['seen' => []];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['seen'][] = $change['push'];
    }

    public function run(mixed $input, Ctx $ctx): mixed
    {
        $ctx->change(['push' => $input]);
        $ctx->emitKeyed(Wire::prefix($input), $input);

        return null;
    }
}

#[Operative(factoryId: 'partition_relay')]
final class PartitionRelayOperative extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)->keyBy(Wire::prefix(...))
            ->into(PartitionRelay::class)->keyed()
            ->sink('input');
    }
}

// -- dynamic_cell ----------------------------------------------------------

// Rust reads the eviction TTL from `DYN_TEST_TTL_MS`; an attribute argument
// must be a constant expression, so this fixture declares none. Only the
// reference-runtime scenario arms a TTL, so nothing here reads one.
#[Task(name: 'dynamic_cell_tasklet', dynamicCells: true)]
final class DynamicCell extends Tasklet
{
    public function state(?string $cellKey): mixed
    {
        return ['seen' => []];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['seen'][] = $change['push'];
    }

    public function run(mixed $input, Ctx $ctx): mixed
    {
        $ctx->change(['push' => $input]);

        return $input;
    }
}

#[Operative(factoryId: 'dynamic_cell')]
final class DynamicCellOperative extends StreamOperative
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

/** Every operative this app registers, in declaration order. */
final class Fixtures
{
    /** @return list<class-string<\Flezha\Operative>> */
    public static function all(): array
    {
        return [
            DoNothingOperative::class,
            BenchEchoOperative::class,
            CompareFilterOperative::class,
            StaticCellOperative::class,
            SugarStaticCellOperative::class,
            MixedOperative::class,
            HybridChannelOperative::class,
            MergedInputOperative::class,
            TransformChainOperative::class,
            CellStressOperative::class,
            BenchKeyedNoPersistOperative::class,
            StoreInputOperative::class,
            StatefulCounterOperative::class,
            PersistenceOffCounterOperative::class,
            CoalescingCounterOperative::class,
            ResumeProbeOperative::class,
            BenchKeyedOperative::class,
            SchemaEvolutionV1Operative::class,
            SchemaEvolutionV2Operative::class,
            TierStoreOperative::class,
            CheckpointStoreOperative::class,
            CheckpointBatchedOperative::class,
            BenchCheckpointOperative::class,
            CandleOperative::class,
            PoisonProbeOperative::class,
            WedgeProbeOperative::class,
            SessionTimeoutOperative::class,
            BoundedMailboxDropNothingOperative::class,
            BoundedMailboxDropNewestOperative::class,
            BoundedMailboxDropOldestOperative::class,
            PartitionRelayOperative::class,
            DynamicCellOperative::class,
            SpawnRequesterOperative::class,
            SpawnOnceRequesterOperative::class,
            // The §5 short forms.
            SugarDoNothing::class,
            SugarStoreInput::class,
            SugarNamedInput::class,
            SugarWorkflow::class,
            SugarWorkflowPerInput::class,
            SugarStateOnly::class,
        ];
    }
}

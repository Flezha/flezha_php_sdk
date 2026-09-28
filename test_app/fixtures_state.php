<?php

declare(strict_types=1);

/**
 * Fixtures whose subject is persisted Cell state: the keyed upsert the bench
 * shares, and the counter and resume twins — the same partition as
 * test/apps/python_runner/fixtures_state.py and the reference's state.rs.
 */

namespace Flezha\TestApp;

use Flezha\Attribute\Operator;
use Flezha\Attribute\Task;
use Flezha\ChannelCodec;
use Flezha\Codec\Json;
use Flezha\Ctx;
use Flezha\Graph\Builder;
use Flezha\Overflow;
use Flezha\Tasklet;
use Flezha\UnboundedOperator;

// -- bench_keyed_nopersist / bench_keyed / the tier stores -------------------
//
// The reference's `TierStoreState` and its upsert fold: `tier_store`,
// `checkpoint_store` and `bench_checkpoint` are the same keyed upsert under
// three acknowledgement postures; only `run` differs.

#[Task]
abstract class KeyedUpsert extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['values' => []];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['values'][$change['key']] = $change['value'];
    }

    /** Record and emit one `key:value` input under its natural key. */
    protected function upsert(mixed $input, Ctx $ctx): void
    {
        [$key, $value] = self::split($input);
        $ctx->change(['key' => $key, 'value' => $value]);
        $ctx->emitKeyed($key, "$key=$value");
    }

    /** A `key:value` input as its two halves; the value keeps any later colon. */
    protected static function split(mixed $input): array
    {
        [$key, $value] = array_pad(explode(':', (string) $input, 2), 2, null);

        return [(string) $key, $value];
    }
}

#[Task(name: 'tier_store_tasklet')]
final class TierStore extends KeyedUpsert
{
    public function run(mixed $input, Ctx $ctx): void
    {
        // Dead-letter e2e: a "poison" value fails every attempt, exhausting
        // the retry budget so the tier-on atomic park advances the cell
        // past it.
        if (self::split($input)[1] === 'poison') {
            throw new \RuntimeException('poison value');
        }
        $this->upsert($input, $ctx);
    }
}

/** `tier_store`'s twin, whose interval closes on a `commit:` input. */
#[Task(name: 'checkpoint_store_tasklet')]
final class CheckpointStore extends KeyedUpsert
{
    public function run(mixed $input, Ctx $ctx): void
    {
        if (self::split($input)[0] === 'commit') {
            $ctx->checkpoint();

            return;
        }
        $this->upsert($input, $ctx);
    }
}

/** `checkpoint_store` driven by a DECLARED cadence rather than an input. */
#[Task(name: 'bench_checkpoint_tasklet')]
final class BenchCheckpoint extends KeyedUpsert
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $this->upsert($input, $ctx);
    }
}

/**
 * The same keyed upsert under both durability postures, so the round-trip
 * bench can isolate the cost of per-Cell persistence.
 */
abstract class KeyedUpsertOperator extends UnboundedOperator
{
    public static function channels(Builder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)->onFull(Overflow::DropNothing)
            ->into(TierStore::class)->keyed()
            ->sink('sink');
    }
}

/**
 * Per-cell persistence off: the heap-only posture the bench isolates against
 * the durable one.
 */
#[Operator(factoryId: 'bench_keyed_nopersist', persistent: false)]
final class BenchKeyedNoPersistOperator extends KeyedUpsertOperator
{
}

#[Operator(factoryId: 'bench_keyed')]
final class BenchKeyedOperator extends KeyedUpsertOperator
{
}

/**
 * The effectively-once reference: a keyed upsert under the tier. A 300 ms
 * backoff base keeps the min-watermark freeze observable in the DLQ e2e.
 * Declared DropNothing: a durable-input-log operator that shed at its mailbox
 * would hold the min-watermark on the shed offset forever while the log behind
 * it kept growing.
 */
#[Operator(factoryId: 'tier_store')]
final class TierStoreOperator extends UnboundedOperator
{
    public static function channels(Builder $c): void
    {
        $c->effectivelyOnce();
        $c->input('input', codec: ChannelCodec::Text)->onFull(Overflow::DropNothing)->retryBackoffMs(300)
            ->into(TierStore::class)->keyed()
            ->sink('sink');
    }
}

// -- tier_chain --------------------------------------------------------------
//
// An effectively-once stream of two tasklets. `tier_relay` counts what it
// relays and passes each `key:value` on; `tier_tally` counts each key's inputs
// and emits `key=count`. Until the release env is set the tally fails the value
// `hold` and keeps retrying it, so a runner killed there has made the relay's
// record durable and not the tally's.

#[Task]
abstract class Counting extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['value' => 0];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['value'] += $change['delta'];
    }
}

#[Task(name: 'tier_relay')]
final class TierRelay extends Counting
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->change(['delta' => 1]);
        $ctx->emit($input);
    }
}

#[Task(name: 'tier_tally')]
final class TierTally extends Counting
{
    public function run(mixed $input, Ctx $ctx): void
    {
        [$key, $value] = explode(':', (string) $input, 2) + [1 => ''];
        if ($value === 'hold' && !BoundedGate::released()) {
            throw new \RuntimeException('held until released');
        }
        $ctx->change(['delta' => 1]);
        $ctx->emitKeyed($key, "{$key}={$ctx->state['value']}");
    }
}

#[Operator(factoryId: 'tier_chain')]
final class TierChainOperator extends UnboundedOperator
{
    public static function channels(Builder $c): void
    {
        $c->effectivelyOnce();
        $c->input('input', codec: ChannelCodec::Text)->onFull(Overflow::DropNothing)
            ->into(TierRelay::class)
            ->keyBy(Wire::prefix(...))->retryMax(1_000)
            ->into(TierTally::class)->keyed()
            ->sink('sink');
    }
}

#[Operator(factoryId: 'checkpoint_store')]
final class CheckpointStoreOperator extends UnboundedOperator
{
    public static function channels(Builder $c): void
    {
        $c->effectivelyOnce()->checkpointAck();
        $c->input('input', codec: ChannelCodec::Text)
            ->into(CheckpointStore::class)->keyed()
            ->sink('sink');
    }
}

/**
 * The same tasklet declared `outputsAtCheckpoint`: the interval's outputs are
 * held with its changes and ship inside its checkpoint record.
 * `checkpointEvery` is deliberately absent so the test drives the close itself
 * — with `commit:` for the batch case, and with the delay alone for the
 * quiet-Cell case.
 */
#[Operator(factoryId: 'checkpoint_batched_store')]
final class CheckpointBatchedOperator extends UnboundedOperator
{
    public static function channels(Builder $c): void
    {
        $c->effectivelyOnce()->checkpointAck()->outputsAtCheckpoint(1_500);
        $c->input('input', codec: ChannelCodec::Text)
            ->into(CheckpointStore::class)->keyed()
            ->sink('sink');
    }
}

/**
 * `checkpoint_store` driven by a DECLARED cadence instead of a `commit:` input:
 * acknowledging every `FLEZHA_BENCH_CHECKPOINT_EVERY` inputs (default 500) per
 * Cell. `FLEZHA_BENCH_CHECKPOINT_OUTPUTS=at_checkpoint` (with
 * `FLEZHA_BENCH_CHECKPOINT_DELAY_MS`) additionally holds the interval's outputs
 * until it closes.
 */
#[Operator(factoryId: 'bench_checkpoint')]
final class BenchCheckpointOperator extends UnboundedOperator
{
    public static function channels(Builder $c): void
    {
        $c->effectivelyOnce()->checkpointEvery(Wire::int(getenv('FLEZHA_BENCH_CHECKPOINT_EVERY')) ?: 500);
        if (getenv('FLEZHA_BENCH_CHECKPOINT_OUTPUTS') === 'at_checkpoint') {
            $c->outputsAtCheckpoint(Wire::int(getenv('FLEZHA_BENCH_CHECKPOINT_DELAY_MS')) ?: 50);
        }
        $c->input('input', codec: ChannelCodec::Text)->onFull(Overflow::DropNothing)
            ->into(BenchCheckpoint::class)->keyed()
            ->sink('sink');
    }
}

// -- store_input -------------------------------------------------------------

/** The durable-state reference: every input is appended through a change. */
#[Task(name: 'store_input_tasklet')]
final class StoreInput extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['binary_inputs' => [], 'text_inputs' => []];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['text_inputs'][] = $change['push_text'];
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->change(['push_text' => $input]);

        $ctx->emit($input);
    }
}

#[Operator(factoryId: 'store_input')]
final class StoreInputOperator extends UnboundedOperator
{
    public static function channels(Builder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)
            ->into(StoreInput::class)
            ->sink('sink');
    }
}

// -- stateful_counter / persistence_off_counter ------------------------------

#[Task(name: 'counter_entry_tasklet')]
final class CounterEntry extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit(Wire::int($input));
    }
}

#[Task(name: 'counter_tasklet')]
final class Counter extends Tasklet
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
        $ctx->change(['delta' => $input]);
    }
}

#[Operator(factoryId: 'stateful_counter')]
final class StatefulCounterOperator extends UnboundedOperator
{
    public static function channels(Builder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)
            ->into(CounterEntry::class)->named('ticks')
            ->into(Counter::class);
    }
}

/**
 * The same topology with per-Cell persistence off, under the reference's
 * node names: the toggle is a class property, not a different computation.
 */
#[Operator(factoryId: 'persistence_off_counter', persistent: false)]
final class PersistenceOffCounterOperator extends UnboundedOperator
{
    public static function channels(Builder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)
            ->into(CounterEntry::class, 'persistence_off_entry_tasklet')->named('ticks')
            ->into(Counter::class, 'persistence_off_counter_tasklet');
    }
}

// -- sleeping_counter --------------------------------------------------------
//
// An unbounded operator that sleeps when idle: `key:delta` inputs fold into one running
// total per key, and every input emits `key=total` on the `totals` sink. The
// sleep e2e reads the total after a wake to prove the Cell came back with the
// state it slept with.

#[Task(name: 'sleeping_counter_tasklet')]
final class SleepingCounter extends Tasklet
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
        [$key, $delta] = explode(':', (string) $input, 2) + [1 => ''];
        $ctx->change(['delta' => Wire::int($delta)]);

        $ctx->emit("{$key}={$ctx->state['value']}");
    }
}

#[Operator(factoryId: 'sleeping_counter', sleepWhenIdle: true)]
final class SleepingCounterOperator extends UnboundedOperator
{
    public static function channels(Builder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)
            ->keyBy(Wire::prefix(...))
            ->into(SleepingCounter::class)
            ->sink('totals');
    }
}

// -- coalescing_counter ------------------------------------------------------

/** Wire format: `<interval>:<ts>:<delta>`; a missing or unparsable part reads 0. */
#[Task(name: 'tick_entry_tasklet')]
final class TickEntry extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        [$interval, $ts, $delta] = array_pad(explode(':', (string) $input, 3), 3, '');

        $ctx->emit(['interval' => Wire::int($interval), 'ts' => Wire::int($ts), 'delta' => Wire::int($delta)]);
    }
}

/**
 * The reference coalesces this change on `(interval, ts)` through a `Change`
 * trait method. The SDK carries no coalesce-key seam, so the change ships
 * uncoalesced — a throughput property, not a semantic one: the fold is
 * idempotent per `(interval, ts)` either way, so replay produces the same
 * state.
 */
#[Task(name: 'tick_tasklet')]
final class Tick extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['last' => 0];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['last'] = $change['delta'];
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->change($input);
    }
}

#[Operator(factoryId: 'coalescing_counter')]
final class CoalescingCounterOperator extends UnboundedOperator
{
    public static function channels(Builder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)
            ->into(TickEntry::class)->named('ticks')
            ->into(Tick::class);
    }
}

// -- resume_probe ------------------------------------------------------------

/**
 * Emits the running total, so a resume is observable on the wire. Default +
 * change replay rebuilds the total on restore; no snapshot fast path needed.
 */
#[Task(name: 'resume_probe_tasklet')]
final class ResumeProbe extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['total' => 0];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['total'] += $change['add'];
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $delta = Wire::int($input);
        $total = $ctx->state['total'] + $delta;
        $ctx->change(['add' => $delta]);

        $ctx->emit((string) $total);
    }
}

#[Operator(factoryId: 'resume_probe')]
final class ResumeProbeOperator extends UnboundedOperator
{
    public static function channels(Builder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)
            ->into(ResumeProbe::class)
            ->sink('total');
    }
}

// -- schema_evolution_v1 / schema_evolution_v2 -------------------------------
//
// The same class either side of a deploy that edits its own state type. Both
// halves declare the SAME tasklet name, so an operator started under v1 and
// restarted under v2 restores from the very WAL v1 wrote — which is what a
// deploy does, with the two binaries collapsed into one fixture file.
//
// v1: `{total}`. v2: `{total, currency}` at schema version 2, reading down to 1
// through a declared migration. Output is `total:currency` so the e2e can read
// the migrated field off the wire.

#[Task(name: 'evolving_cart')]
final class EvolvingCartV1 extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['total' => 0];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['total'] += $change['add'];
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $delta = Wire::int($input);
        $total = $ctx->state['total'] + $delta;
        $ctx->change(['add' => $delta]);

        $ctx->emit("$total:");
    }
}

#[Task(name: 'evolving_cart', schemaVersion: 2, minReadableSchemaVersion: 1)]
final class EvolvingCartV2 extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['total' => 0, 'currency' => ''];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['total'] += $change['add'];
    }

    public function migrateState(int $fromVersion, string $bytes): mixed
    {
        if ($fromVersion !== 1) {
            throw new \RuntimeException("no migration from $fromVersion");
        }
        $old = Json::decode($bytes) ?? [];

        return ['total' => $old['total'] ?? 0, 'currency' => 'EUR'];
    }

    public function migrateChange(int $fromVersion, string $bytes): mixed
    {
        if ($fromVersion !== 1) {
            throw new \RuntimeException("no migration from $fromVersion");
        }
        $old = Json::decode($bytes) ?? [];

        return ['add' => $old['add'] ?? 0, 'note' => 'migrated'];
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $delta = Wire::int($input);
        $total = $ctx->state['total'] + $delta;
        $currency = $ctx->state['currency'] ?: 'EUR';
        $ctx->change(['add' => $delta, 'note' => 'live']);

        $ctx->emit("$total:$currency");
    }
}

#[Operator(factoryId: 'schema_evolution_v1')]
final class SchemaEvolutionV1Operator extends UnboundedOperator
{
    public static function channels(Builder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)
            ->into(EvolvingCartV1::class)
            ->sink('total');
    }
}

#[Operator(factoryId: 'schema_evolution_v2')]
final class SchemaEvolutionV2Operator extends UnboundedOperator
{
    public static function channels(Builder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)
            ->into(EvolvingCartV2::class)
            ->sink('total');
    }
}

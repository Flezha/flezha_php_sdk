<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\Attribute\Operator;
use Flezha\Attribute\Task;
use Flezha\Attribute\Bounded;
use Flezha\BeyondWindow;
use Flezha\Clock;
use Flezha\Ctx;
use Flezha\Ffi\Core;
use Flezha\Graph\Builder;
use Flezha\Graph\ClassSpec;
use Flezha\Graph\HostClassDecl;
use Flezha\Graph\Validate;
use Flezha\LoopMode;
use Flezha\Tasklet;
use Flezha\RollbackMode;
use Flezha\UnboundedOperator;
use Flezha\BoundedOperator;

/**
 * Every `HostClassDecl` key the core learns, spelled by this builder.
 *
 * The fixture is the list of record, shared with the `flz_core` test that
 * asserts the core's own struct and with the Java, Node and Ruby builders'
 * tests. A key the core learns that PHP cannot spell fails here, which is the
 * point: the cross-SDK rule is that a declaration key lands in every SDK in
 * one change, and this is what makes forgetting one a red build rather than a
 * silently narrower SDK.
 */
final class DeclKeysTest extends RepositoryTestCase
{
    public function testTheBuilderSpellsEveryDeclaredKey(): void
    {
        $missing = array_diff($this->declared(), $this->spelled());

        self::assertSame([], array_values($missing), sprintf(
            'the PHP builder cannot spell %s; teach it, or the core learned a key this SDK will '
            . 'never send',
            implode(', ', $missing),
        ));
    }

    public function testTheBuilderInventsNoKeyTheCoreNeverLearned(): void
    {
        // `deny_unknown_fields` would refuse one at declare time, but at the
        // first controller connection rather than here.
        $extra = array_diff($this->spelled(), $this->declared());

        self::assertSame([], array_values($extra), sprintf(
            'the PHP builder emits %s, which the core never learned',
            implode(', ', $extra),
        ));
    }

    public function testTheRetiredWakeArgumentIsRefusedWhenTheAttributeIsBuilt(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Unknown named parameter $wakeAfterMs');

        new Bounded(...['wakeAfterMs' => 5_000]);
    }

    /**
     * No kind gate: a bounded class puts every key its builder recorded on
     * the wire, so the core judges what the author wrote and refuses a
     * unbounded-only setting under its own name.
     */
    public function testABoundedClassDeclaresEveryClassKeyAnUnboundedClassDoes(): void
    {
        $dropped = array_diff(
            array_keys(HostClassDecl::of(self::tierSpec('unbounded'))),
            array_keys(HostClassDecl::of(self::tierSpec('bounded'))),
        );

        self::assertSame([], array_values($dropped), sprintf(
            'keys a bounded class does not declare: %s',
            implode(', ', $dropped),
        ));
    }

    /** The unbounded fixture's spec re-kinded: every unbounded-only key populated at once. */
    private static function tierSpec(string $kind): ClassSpec
    {
        $stream = FullUnbounded::spec();

        return new ClassSpec(
            factoryId: "decl_keys_{$kind}_tier",
            kind: $kind,
            persistent: $stream->persistent,
            effectivelyOnce: $stream->effectivelyOnce,
            checkpointAck: $stream->checkpointAck,
            checkpointEvery: $stream->checkpointEvery,
            checkpointOutputs: $stream->checkpointOutputs,
            checkpointMaxDelayMs: $stream->checkpointMaxDelayMs,
            correctionWindowMs: $stream->correctionWindowMs,
            beyondWindowPolicy: $stream->beyondWindowPolicy,
            compactAfterBytes: $stream->compactAfterBytes,
            tasklets: $stream->tasklets,
            channels: $stream->channels,
            bounded: $kind === 'bounded' ? FullBounded::spec()->bounded : null,
        );
    }

    /** @return list<string> */
    private function spelled(): array
    {
        $stream = HostClassDecl::of(FullUnbounded::spec());
        $bounded = HostClassDecl::of(FullBounded::spec());

        $keys = array_map(static fn (string $k): string => "class.$k", array_keys($stream));
        foreach (array_keys($bounded) as $key) {
            $keys[] = "class.$key";
        }
        foreach ($stream['channels'] as $channel) {
            foreach (array_keys($channel) as $key) {
                $keys[] = "channel.$key";
            }
        }
        foreach ($stream['tasklets'] as $tasklet) {
            foreach (array_keys($tasklet) as $key) {
                $keys[] = "tasklet.$key";
            }
        }
        foreach (array_keys($bounded['bounded']) as $key) {
            $keys[] = "bounded.$key";
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /** @return list<string> */
    private function declared(): array
    {
        $lines = file($this->repositoryFile('test/fixtures/host_class_decl_keys.txt'), FILE_IGNORE_NEW_LINES) ?: [];
        $keys = array_values(array_filter(
            $lines,
            static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#'),
        ));
        sort($keys);

        return $keys;
    }

    /**
     * A merge source is a chain like any other: its mapping and the merge's key
     * are ordinary links, closed by the `merge` link the core deserializes, and
     * every closed merge takes a group of its own.
     */
    public function testAMergeChainIsRecordedInTheCoresLinkShape(): void
    {
        $merged = static fn (int $group): array => [
            ['link' => 'transform'],
            ['link' => 'key'],
            ['link' => 'merge', 'tasklet' => 0, 'group' => $group],
        ];
        self::assertSame(
            [[$merged(0)], [$merged(0)]],
            array_column(HostClassDecl::of(MergeOperator::spec())['channels'], 'chains'),
        );

        $same = static fn (mixed $v): mixed => $v;
        $c = new Builder('two_merges');
        $a = $c->input('a');
        $b = $c->input('b');
        $c->merge()->from($a, $same)->from($b, $same)->into(Echoer::class);
        $c->merge()->from($a, $same)->from($b, $same)->into(Echoer::class, 'second');
        $c->seal();

        self::assertSame(
            [['link' => 'transform'], ['link' => 'merge', 'tasklet' => 1, 'group' => 1]],
            $c->channels[0]->chains[1],
        );
    }

    /**
     * The cross-language topology golden.
     *
     * `test/fixtures/reference_topologies.txt` is the Rust reference's channel
     * topology for two fixtures, transcribed by hand. Several SDKs implement
     * one design with no compiler between them, so the file is what keeps them
     * from drifting: this builder declares both shapes in PHP's spelling and
     * the lines the CORE DERIVES from its record must be the golden's, in
     * order.
     */
    public function testTheHybridUnboundedTopologyIsTheReference(): void
    {
        self::assertSame(
            $this->golden('hybrid_channel'),
            $this->rendered('hybrid_channel', HybridChannelTopology::spec()),
        );
    }

    public function testTheStructuredBoundedTopologyIsTheReference(): void
    {
        self::assertSame(
            $this->golden('commit_block_chain'),
            $this->rendered('commit_block_chain', CommitBlockChainTopology::spec()),
        );
    }

    /** @return list<string> */
    private function golden(string $topology): array
    {
        $lines = file($this->repositoryFile('test/fixtures/reference_topologies.txt'), FILE_IGNORE_NEW_LINES) ?: [];

        return array_values(array_filter(
            array_map(trim(...), $lines),
            static fn (string $line): bool => str_starts_with($line, "$topology |"),
        ));
    }

    /**
     * The DERIVED declaration rendered in the golden's columns, one line per
     * channel. Every column but the index is the core's answer now — the
     * record carries chains, and the consumers, the plugin kind and the sink
     * name are read off them.
     *
     * @return list<string>
     */
    private function rendered(string $topology, ClassSpec $spec): array
    {
        $core = Core::create();

        try {
            $derived = Validate::spec($spec, $core);
        } finally {
            $core->free();
        }

        $lines = [];
        foreach ($derived['channels'] as $index => $channel) {
            $consumers = array_map(
                static fn (array $edge): int => $edge['tasklet'],
                $channel['consumers'],
            );
            $lines[] = implode(' | ', [
                $topology,
                (string) $index,
                $channel['name'],
                $channel['kind'],
                $channel['producer'] === null ? '-' : (string) $channel['producer'],
                $consumers === [] ? '-' : implode(',', $consumers),
                $channel['sink'] ?? '-',
            ]);
        }

        return $lines;
    }
}

/** A declaration populated far enough that every optional key is present. */
#[Task(name: 'entry', clock: Clock::EventTime, mailboxCapacity: 8, schemaVersion: 3, minReadableSchemaVersion: 1)]
final class FullEntry extends Tasklet
{
    public function state(?string $cellKey): mixed
    {
        return ['seen' => []];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['seen'][] = $change['push'];
    }

    public function eventTime(mixed $input): int
    {
        return (int) $input;
    }

    public function migrateState(int $fromVersion, string $bytes): mixed
    {
        return \Flezha\Codec\Json::decode($bytes);
    }

    public function run(mixed $input, Ctx $ctx): mixed
    {
        $ctx->change(['push' => $input]);

        return null;
    }
}

#[Task(name: 'downstream', dynamicCells: true, idleTtlMs: 1_000, maxCells: 16)]
final class FullDownstream extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        return $input;
    }

    public function compensate(array $outputs, Ctx $ctx): void
    {
    }
}

#[Task(name: 'fanned', cellKeys: ['a', 'b'])]
final class FullFanned extends Tasklet
{
    public function cellsFor(mixed $input): array
    {
        return ['a'];
    }

    public function run(mixed $input, Ctx $ctx): mixed
    {
        return $input;
    }
}

#[Operator(factoryId: 'decl_keys_unbounded')]
final class FullUnbounded extends UnboundedOperator
{
    public static function channels(Builder $c): void
    {
        $c->effectivelyOnce();
        $c->checkpointEvery(4);
        $c->outputsAtCheckpoint(250);
        $c->correctionWindow(5_000);
        $c->beyondWindowPolicy(BeyondWindow::Drop);
        $c->compactAfterBytes(4_096);

        $edge = $c->input('input')->into(FullEntry::class);
        $edge->named('hop')->keyBy(static fn (mixed $v): string => (string) $v)
            ->retryMax(5)->retryBackoffMs(50)->into(FullDownstream::class);
        $edge->named('fan')->into(FullFanned::class);
        $c->input('second')->into(FullEntry::class)->keyed()->sink('sink');
    }
}

#[Operator(factoryId: 'decl_keys_bounded')]
#[Bounded(
    loopMode: LoopMode::Chained,
    rollbackMode: RollbackMode::KeepProgress,
    maxDurationMs: 60_000,
    syncDurability: true,
)]
final class FullBounded extends BoundedOperator
{
    public static function channels(Builder $c): void
    {
        // A chained loop seeds each iteration from the terminal's handoff, so
        // the terminal's output channel is not plugin-bound.
        $c->input('in')->into(Echoer::class)->named('carry');
    }
}

#[Task(name: 'hybrid_producer_tasklet')]
final class HybridProducer extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        $ctx->emit($input);

        return null;
    }
}

#[Task(name: 'hybrid_consumer_tasklet')]
final class HybridConsumer extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        return null;
    }
}

/** The reference's unbounded fixture: one producing channel, a sink AND a consumer. */
#[Operator(factoryId: 'hybrid_channel')]
final class HybridChannelTopology extends UnboundedOperator
{
    public static function channels(Builder $c): void
    {
        $c->input('input')->into(HybridProducer::class)
            ->sink('hybrid_indicator', static fn (mixed $v): string => (string) $v)
            ->into(HybridConsumer::class);
    }
}

#[Task(name: 'tier_commit_a')]
final class TierCommitA extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        $ctx->emit($input, 'job');

        return null;
    }
}

#[Task(name: 'block_b')]
final class BlockB extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        return null;
    }
}

/** The reference's durable structured bounded operator: the same hybrid, mid-chain. */
#[Operator(factoryId: 'commit_block_chain')]
final class CommitBlockChainTopology extends BoundedOperator
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(TierCommitA::class)->keyed()
            ->sink('mid', static fn (mixed $v): string => (string) $v)
            ->into(BlockB::class);
    }
}

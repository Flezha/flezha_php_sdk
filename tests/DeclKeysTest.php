<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\Attribute\Relay;
use Flezha\ChannelBuilder;
use Flezha\Ctx;
use Flezha\Ffi\Core;
use Flezha\Graph\ClassSpec;
use Flezha\Graph\HostClassDecl;
use Flezha\Graph\Validate;
use Flezha\Tasklet;

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

        new Relay(...['factoryId' => 'w', 'wakeAfterMs' => 5_000]);
    }

    /** @return list<string> */
    private function spelled(): array
    {
        $stream = HostClassDecl::of(FullFlow::spec());

        $keys = array_map(static fn (string $k): string => "class.$k", array_keys($stream));
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
            array_column(HostClassDecl::of(MergeRelay::spec())['channels'], 'chains'),
        );

        $same = static fn (mixed $v): mixed => $v;
        $c = new ChannelBuilder('two_merges');
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
    public function testTheHybridFlowTopologyIsTheReference(): void
    {
        self::assertSame(
            $this->golden('hybrid_channel'),
            $this->rendered('hybrid_channel', HybridChannelTopology::spec()),
        );
    }

    public function testTheStructuredStepsTopologyIsTheReference(): void
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
     * record carries chains, and the consumers, the plugin kind and the output
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
#[Tasklet\Spec(
    name: 'entry',
    mailboxCapacity: 8,
    schemaVersion: 3,
    minReadableSchemaVersion: 1,
    maxTimersPerCell: 16,
    compactAfterBytes: 4_096,
)]
final class FullEntry extends Tasklet
{
    public function initState(?string $cellKey): mixed
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

    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->change(['push' => $input]);
    }
}

#[Tasklet\Spec(name: 'downstream', idleTtlSeconds: 1, maxCells: 16)]
final class FullDownstream extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit($input);
    }

    public function compensate(array $outputs, Ctx $ctx): void
    {
    }
}

#[Tasklet\Spec(name: 'fanned', cellKeys: ['a', 'b'])]
final class FullFanned extends Tasklet
{
    public function cellsFor(mixed $input): array
    {
        return ['a'];
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit($input);
    }
}

#[Relay(
    factoryId: 'decl_keys_flow',
    run: ['flow' => ['exactly' => ['effectively_once' => [
        'ack' => ['checkpoint' => ['every' => 4, 'outputs' => 'at_checkpoint', 'max_delay_ms' => 250]],
        'correction_ms' => 5_000,
        'beyond_window' => 'drop',
    ]]]],
    durable: ['local' => ['sleep_after' => 2_000]],
    maxDurationMs: 60_000,
    description: 'every flow key',
)]
final class FullFlow extends \Flezha\Relay
{
    public static function channels(ChannelBuilder $c): void
    {
        $edge = $c->input('input', description: 'every key', schema: ['type' => 'object'])->into(FullEntry::class);
        $edge->named('hop')->keyBy(static fn (mixed $v): string => (string) $v)
            ->retryMax(5)->retryBackoffMs(50)->into(FullDownstream::class);
        $edge->named('fan')->into(FullFanned::class);
        $c->input('second')->into(FullEntry::class)->keyed()->output('sink');
    }
}

#[Tasklet\Spec(name: 'hybrid_producer_tasklet')]
final class HybridProducer extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit($input);
    }
}

#[Tasklet\Spec(name: 'hybrid_consumer_tasklet')]
final class HybridConsumer extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
    }
}

/** The reference's flow fixture: one producing channel, an output AND a consumer. */
#[Relay(factoryId: 'hybrid_channel')]
final class HybridChannelTopology extends \Flezha\Relay
{
    public static function channels(ChannelBuilder $c): void
    {
        $c->input('input')->into(HybridProducer::class)
            ->output('hybrid_indicator', static fn (mixed $v): string => (string) $v)
            ->into(HybridConsumer::class);
    }
}

#[Tasklet\Spec(name: 'tier_commit_a')]
final class TierCommitA extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit($input, 'job');
    }
}

#[Tasklet\Spec(name: 'block_b')]
final class BlockB extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
    }
}

/** The reference's durable structured steps relay: the same hybrid, mid-chain. */
#[Relay(factoryId: 'commit_block_chain', run: 'steps')]
final class CommitBlockChainTopology extends \Flezha\Relay
{
    public static function channels(ChannelBuilder $c): void
    {
        $c->input('in')->into(TierCommitA::class)->keyed()
            ->output('mid', static fn (mixed $v): string => (string) $v)
            ->into(BlockB::class);
    }
}

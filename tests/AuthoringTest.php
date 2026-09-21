<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\Attribute\Operative;
use Flezha\Attribute\Task;
use Flezha\Attribute\Workflow;
use Flezha\ChannelCodec;
use Flezha\CheckpointOutputs;
use Flezha\Clock;
use Flezha\Ctx;
use Flezha\Definition;
use Flezha\Dispatch\Registry;
use Flezha\Exception\DeclarationException;
use Flezha\Exception\DecodeException;
use Flezha\Exception\EmitException;
use Flezha\Exception\FlezhaException;
use Flezha\Exception\UnrepresentableValueException;
use Flezha\Ffi\Core;
use Flezha\Flezha;
use Flezha\Graph\Builder;
use Flezha\Graph\ClassSpec;
use Flezha\Graph\Edge;
use Flezha\Graph\HostClassDecl;
use Flezha\Graph\Validate;
use Flezha\LoopMode;
use Flezha\Operative as OperativeBase;
use Flezha\Tasklet;
use Flezha\Runner;
use Flezha\StreamOperative;
use Flezha\Testing\Harness;
use Flezha\Timer;
use Flezha\Transforms;
use Flezha\WorkflowOperative;

// ---------------------------------------------------------------------------
// The §5 short form

#[Operative(factoryId: 'sf_upcase', sink: 'out', codec: ChannelCodec::Text)]
final class ShortUpcase extends StreamOperative
{
    use Echoes;
}

/** `ShortUpcase` as its full form, the tasklet named after the factory id. */
#[Operative(factoryId: 'sf_upcase')]
final class ShortUpcaseTwinOperative extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)
            ->into(Echoer::class, 'sf_upcase')
            ->sink('out');
    }
}

#[Operative(factoryId: 'sf_place', sink: 'placed', input: 'order', codec: ChannelCodec::Text)]
final class ShortPlace extends WorkflowOperative
{
    use Echoes;
}

#[Operative(factoryId: 'sf_place')]
final class ShortPlaceTwinOperative extends WorkflowOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('order', codec: ChannelCodec::Text)
            ->into(Echoer::class, 'sf_place')
            ->sink('placed');
    }
}

/** A workflow short form under the id `ShortUpcase` already holds. */
#[Operative(factoryId: 'sf_upcase', sink: 'placed')]
final class ShortUpcaseWorkflow extends WorkflowOperative
{
    use Echoes;
}

#[Operative(factoryId: 'sf_both', sink: 'out')]
final class ShortWithTopology extends StreamOperative
{
    use Echoes;

    public static function channels(Builder $c): void
    {
        $c->input('input')->into(Echoer::class)->sink('out');
    }
}

#[Operative(factoryId: 'sf_no_body')]
final class ShortNoBody extends StreamOperative
{
}

final class ShortUnattributed extends StreamOperative
{
    use Echoes;
}

#[Operative(factoryId: 'sf_renamed')]
#[Task(name: 'elsewhere')]
final class ShortRenamed extends StreamOperative
{
    use Echoes;
}

#[Operative(factoryId: 'sf_unpersisted', persistent: false)]
final class ShortUnpersisted extends StreamOperative
{
    use Echoes;
}

#[Operative(factoryId: 'sf_per_input')]
#[Workflow(loopMode: LoopMode::PerInput)]
final class ShortPerInput extends WorkflowOperative
{
    use Echoes;
}

#[Operative(factoryId: 'sf_codec_on_topology', codec: ChannelCodec::Text)]
final class ShortCodecOnTopology extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('input')->into(Echoer::class)->sink('out');
    }
}

#[Operative(factoryId: 'sf_task_on_topology')]
#[Task(mailboxCapacity: 8)]
final class ShortTaskOnTopology extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('input')->into(Echoer::class)->sink('out');
    }
}

/** A fold taking the state by value, which would fold into a copy. */
#[Operative(factoryId: 'sf_fold_by_value')]
final class ShortFoldByValue extends StreamOperative
{
    use Echoes;

    public function state(?string $cellKey): mixed
    {
        return ['seen' => []];
    }

    public function applyChange(mixed $state, mixed $change): void
    {
        $state['seen'][] = $change;
    }
}

#[Operative(factoryId: 'sf_narrowed_key')]
final class ShortNarrowedKey extends StreamOperative
{
    use Echoes;

    public function state(string $cellKey): mixed
    {
        return [];
    }
}

#[Operative(factoryId: 'sf_wider_keys')]
final class ShortWiderKeys extends StreamOperative
{
    use Echoes;

    public function cellsFor(mixed $input): ?array
    {
        return null;
    }
}

#[Operative(factoryId: 'sf_void_state')]
final class ShortVoidState extends StreamOperative
{
    use Echoes;

    public function state(?string $cellKey): void
    {
    }
}

#[Operative(factoryId: 'sf_untyped_return')]
final class ShortUntypedReturn extends WorkflowOperative
{
    use Echoes;

    public function compensate(array $outputs, Ctx $ctx)
    {
    }
}

#[Operative(factoryId: 'sf_extra_argument')]
final class ShortExtraArgument extends StreamOperative
{
    use Echoes;

    public function onTimer(Timer $timer, Ctx $ctx, int $extra): void
    {
    }
}

#[Operative(factoryId: 'sf_dropped_argument')]
final class ShortDroppedArgument extends StreamOperative
{
    use Echoes;

    public function onTimer(Timer $timer): void
    {
    }
}

#[Operative(factoryId: 'sf_intersected_timer')]
final class ShortIntersectedTimer extends StreamOperative
{
    use Echoes;

    public function onTimer(Timer&\Countable $timer, Ctx $ctx): void
    {
    }
}

#[Operative(factoryId: 'sf_static_hook')]
final class ShortStaticHook extends StreamOperative
{
    use Echoes;

    public static function eventTime(mixed $input): int
    {
        return 0;
    }
}

#[Operative(factoryId: 'sf_hidden_hook')]
final class ShortHiddenHook extends WorkflowOperative
{
    use Echoes;

    protected function compensate(array $outputs, Ctx $ctx): void
    {
    }
}

/** Every widening PHP lets an override of these hooks make. */
#[Operative(factoryId: 'sf_widened_hooks')]
final class ShortWidenedHooks extends WorkflowOperative
{
    use Echoes;

    public function state($cellKey): array
    {
        return [];
    }

    public function applyChange(&$state, $change, bool $unused = false): void
    {
    }

    public function onTimer(object $timer, ?Ctx $ctx): void
    {
    }

    public function migrateState(int|string ...$record): never
    {
        throw new \LogicException('nothing was written at an older version');
    }

    public function compensate(iterable $outputs, Ctx $ctx): void
    {
    }
}

#[Task(name: 'sf_task_kind', kind: 'stream')]
final class TaskWithKind extends Tasklet
{
    use Echoes;
}

#[Task(name: 'sf_task_sink', sink: 'out')]
final class TaskWithSink extends Tasklet
{
    use Echoes;
}

#[Task(name: 'sf_task_input', input: 'prices')]
final class TaskWithInput extends Tasklet
{
    use Echoes;
}

#[Task(name: 'sf_task_codec', codec: ChannelCodec::Text)]
final class TaskWithCodec extends Tasklet
{
    use Echoes;
}

#[Task(clock: Clock::EventTime)]
final class NoExtractor extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        return null;
    }
}

#[Task(cellKeys: ['a'], dynamicCells: true)]
final class BothModes extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        return null;
    }
}

#[Task]
final class FoldWithoutState extends Tasklet
{
    public function applyChange(mixed &$state, mixed $change): void
    {
    }

    public function run(mixed $input, Ctx $ctx): mixed
    {
        return null;
    }
}

/**
 * What this package's authoring surface answers, and what it hands the core.
 *
 * The topology itself is judged once, in `flz_core::host::register::check`, and
 * the reference refusals are pinned by `test/fixtures/reference_topologies.txt`
 * — not here. What is left here is the ergonomics: which shapes the fluent
 * builder BUILDS, the defects a record cannot carry (a PHP class that does not
 * define the method its declaration promises, a plugin sink on a channel no
 * tasklet produces on), and that the core's verdict reaches an author as this
 * package's own refusal.
 */
final class AuthoringTest extends RepositoryTestCase
{
    /** @return iterable<string, array{callable, string}> */
    public static function refusals(): iterable
    {
        // A clock is a promise about a method, and a record carries the clock
        // but not the class: only this package can see the method is missing.
        yield 'an event-time clock with no extractor' => [
            static fn (): mixed => self::validate(static function (Builder $c): void {
                $c->input('in')->into(NoExtractor::class)->sink();
            }),
            'declares an event_time clock but no eventTime method',
        ];
        yield 'both cell modes' => [
            static fn (): mixed => Definition::of(BothModes::class),
            'declares both a static key set and dynamic cells',
        ];
        yield 'a fold with nothing to fold into' => [
            static fn (): mixed => Definition::of(FoldWithoutState::class),
            'declares applyChange but no state',
        ];
        // The other refusal the builder keeps: a channel with no producer has
        // no value to serialize, and the core reads a sink as the terminal of
        // the channel it sits on — it cannot see this one is controller-fed.
        yield 'a sink fed by an entry channel' => [
            static fn (): mixed => self::validate(static function (Builder $c): void {
                $c->input('in')->sink();
            }),
            'a plugin sink is fed by a tasklet',
        ];
        // The one refusal the builder raises under the core's vocabulary reads
        // the way the core's own verdicts do.
        yield 'two tasklets claiming one node identity' => [
            static fn (): mixed => self::validate(static function (Builder $c): void {
                $in = $c->input('in');
                $in->into(Echoer::class, 'twin');
                $in->into(Keyed::class, 'twin');
            }),
            'topology rejected [NodeIdentityCollision]',
        ];
        // `Validate::spec` IS the submission: the record crosses the ABI and
        // the core's verdict comes back as this package's own refusal, in the
        // core's vocabulary.
        yield 'the core verdict, rendered here' => [
            static fn (): mixed => self::validate(static function (Builder $c): void {
                $c->input('in')
                    ->keyBy(static fn (mixed $v): string => 'a')
                    ->keyBy(static fn (mixed $v): string => 'b')
                    ->into(Keyed::class);
            }),
            'topology rejected [DuplicateKeyBy]',
        ];
    }

    /**
     * @dataProvider refusals
     * @dataProvider shortFormRefusals
     */
    public function testTheDeclarationIsRefusedWithItsDefectNamed(callable $declare, string $defect): void
    {
        $this->expectException(DeclarationException::class);
        $this->expectExceptionMessage($defect);

        $declare();
    }

    /**
     * And the same verdict as data: the core hands its refusal names out
     * beside the rendered message, so nothing here reads them out of prose.
     */
    public function testARefusedDeclarationCarriesTheCoresOwnNames(): void
    {
        try {
            self::validate(static function (Builder $c): void {
                $c->input('in')
                    ->keyBy(static fn (mixed $v): string => 'a')
                    ->keyBy(static fn (mixed $v): string => 'b')
                    ->into(Keyed::class);
            });
            self::fail('the core refuses two keys before one into');
        } catch (DeclarationException $e) {
            self::assertSame(['DuplicateKeyBy'], $e->refusals);
            self::assertStringStartsWith("under_test: topology rejected [DuplicateKeyBy]:\n  - ", $e->getMessage());
        }
    }

    public function testAnOperativeWithoutTheAttributeIsRefused(): void
    {
        $this->expectException(DeclarationException::class);
        $this->expectExceptionMessage('declares no #[Flezha\Attribute\Operative(factoryId: ...)]');

        Unattributed::spec();
    }

    public function testAWorkflowDeclaringNoTaskletsIsRefused(): void
    {
        $this->expectException(DeclarationException::class);
        $this->expectExceptionMessage('declares no tasklets; a workflow runs a graph of tasklets');

        EmptyWorkflow::spec();
    }

    /**
     * Two unnamed mentions of one tasklet are one node, so a diamond that
     * fans out and back in has one instance rather than two.
     */
    public function testOneTaskletMentionedTwiceIsOneNode(): void
    {
        $spec = Diamond::spec();
        self::derived($spec);

        self::assertSame(['echoer', 'counter'], array_map(
            static fn ($node): string => $node->name,
            $spec->tasklets,
        ));
    }

    /**
     * `named()` is honoured on the inter-task path and was dropped on the
     * plugin path, so `named('input')->sink('relay_out')` produced a channel
     * called `relay_out` — and the channel name is what a forwarded frame
     * carries and what selects the receiving operative's entry channel.
     */
    public function testANamedEdgeTerminatingOnASinkNamesTheChannelToo(): void
    {
        $spec = NamedSink::spec();
        self::derived($spec);

        self::assertSame(['in', 'input'], array_map(
            static fn ($channel): string => $channel->name,
            $spec->channels,
        ));
    }

    /**
     * `sdk-graph-authoring`: the no-argument `sink()` names nothing, so the
     * channel keeps the PRODUCER NODE's name — the derivation a channel with
     * no sink at all would take.
     */
    public function testAnUnnamedEdgeTerminatingOnASinkTakesTheProducingNodesName(): void
    {
        $spec = UnnamedSink::spec();
        self::derived($spec);

        self::assertSame(['in', 'counter'], array_map(
            static fn ($channel): string => $channel->name,
            $spec->channels,
        ));
    }

    /**
     * PHP does not inherit attributes, and taking that literally would make
     * `class Sub extends Base {}` silently single-cell with the default
     * capacity and schema version 1 — wrong at runtime, reported nowhere.
     */
    public function testASubclassKeepsEveryDeclarationItsParentMade(): void
    {
        $definition = Definition::of(SilentSubclass::class);

        self::assertSame('static', $definition->cellMode());
        self::assertSame(['a', 'b'], $definition->decl->cellKeys);
        self::assertSame(8, $definition->decl->mailboxCapacity);
        self::assertSame(3, $definition->decl->schemaVersion);
    }

    /**
     * The one declaration that is NOT inherited: a node identity is per class,
     * and an inherited one would make two tasklets claim one node.
     */
    public function testASubclassIsNamedAfterItselfRatherThanItsParent(): void
    {
        self::assertSame('declaring_base', Definition::of(DeclaringBase::class)->name);
        self::assertSame('silent_subclass', Definition::of(SilentSubclass::class)->name);
        self::assertSame('renamed', Definition::of(RenamingSubclass::class)->name);
    }

    /**
     * The common case, and the one whole-attribute inheritance gets wrong: a
     * subclass that renames the node and changes nothing else keeps the key
     * set, the capacity and the schema version its parent declared.
     */
    public function testRenamingASubclassDropsNothingElse(): void
    {
        $definition = Definition::of(RenamingSubclass::class);

        self::assertSame('static', $definition->cellMode());
        self::assertSame(['a', 'b'], $definition->decl->cellKeys);
        self::assertSame(8, $definition->decl->mailboxCapacity);
        self::assertSame(3, $definition->decl->schemaVersion);
    }

    /**
     * The three checkpoint options each write their own raw flag now — the
     * granularity is the core's to imply — so none may clear another.
     */
    public function testTheCheckpointOptionsComposeInAnyOrderAndNoneClearsAnother(): void
    {
        $builder = new Builder('compose');
        $builder->effectivelyOnce();
        $builder->outputsAtCheckpoint(250);
        $builder->checkpointAck();
        $builder->checkpointEvery(4);

        self::assertTrue($builder->checkpointAck);
        self::assertSame(4, $builder->checkpointEvery);
        self::assertSame(CheckpointOutputs::AtCheckpoint, $builder->checkpointOutputs);
        self::assertSame(250, $builder->checkpointMaxDelayMs);
    }

    /**
     * Submit a graph declared inline, and answer the core's derived
     * declaration.
     *
     * A context of its own per call: `flz_core_check_class` registers nothing
     * and a context is a plain allocation, so a case pays one rather than
     * sharing a handle with the suites that make and free theirs.
     *
     * @return array<string, mixed>
     */
    private static function validate(callable $channels): array
    {
        $builder = new Builder('under_test');
        $channels($builder);
        $builder->seal();

        $core = Core::create();

        try {
            return Validate::spec(new ClassSpec(
                factoryId: 'under_test',
                kind: 'stream',
                persistent: true,
                effectivelyOnce: $builder->effectivelyOnce,
                checkpointAck: $builder->checkpointAck,
                checkpointEvery: $builder->checkpointEvery,
                checkpointOutputs: $builder->checkpointOutputs,
                checkpointMaxDelayMs: $builder->checkpointMaxDelayMs,
                correctionWindowMs: $builder->correctionWindowMs,
                beyondWindowPolicy: $builder->beyondWindowPolicy,
                compactAfterBytes: $builder->compactAfterBytes,
                syncDurability: $builder->syncDurability,
                tasklets: $builder->tasklets,
                channels: $builder->channels,
            ), $core);
        } finally {
            $core->free();
        }
    }

    /**
     * One declared class's verdict, with a context of its own.
     *
     * @return array<string, mixed>
     */
    private static function derived(ClassSpec $spec): array
    {
        $core = Core::create();

        try {
            return Validate::spec($spec, $core);
        } finally {
            $core->free();
        }
    }

    /** The sink names the channel; the no-argument form leaves it derived. */
    public function testTheSinkNamesTheChannelAndTheNoArgumentFormDoesNot(): void
    {
        self::assertSame(
            ['in', 'input'],
            array_column(self::derived(NamedSink::spec())['channels'], 'name'),
        );
        self::assertSame(
            ['in', 'counter'],
            array_column(self::derived(UnnamedSink::spec())['channels'], 'name'),
        );
    }

    /** A channel both `named()` and `sink()` name is refused, naming both. */
    public function testAChannelNamedTwiceIsRefusedNamingBoth(): void
    {
        $this->expectException(DeclarationException::class);
        $this->expectExceptionMessage('ChannelNamedTwice');

        self::derived(NamedTwice::spec());
    }

    /** A channel option with nothing wired after it still reaches the channel. */
    public function testAChannelOptionWithNothingWiredAfterItStillReachesTheChannel(): void
    {
        self::assertSame(
            ['in', 'renamed'],
            array_column(self::derived(TrailingNamed::spec())['channels'], 'name'),
        );
        $this->expectException(DeclarationException::class);
        $this->expectExceptionMessage('KeyedChannelWithoutPluginWire');

        self::derived(TrailingKeyed::spec());
    }

    /** Two channels naming one sink is a duplicate channel name. */
    public function testTwoChannelsNamingOneSinkIsRefused(): void
    {
        $this->expectException(DeclarationException::class);
        $this->expectExceptionMessage('DuplicateSinkName');

        self::derived(OneSinkTwoChannels::spec());
    }

    // -- one output channel per producing tasklet --------------------------
    //
    // The model Rust and Java carry: a tasklet node owns ONE output channel,
    // created on first wiring and reused after. A held edge wired twice appends
    // a second consumer to it — it does not declare a second channel claiming
    // the same producer, which the core resolves by first match
    // (`host/register.rs` `tasklet_wiring`) and so silently truncates.

    /**
     * Read off the DERIVED declaration: the consumers, the plugin kind and the
     * sink name are what the core made of the chains this builder recorded.
     *
     * @param ClassSpec|array<string, mixed> $declaration a class, or what the core already derived
     * @return list<array{string, string, ?int, list<int>, ?string}>
     */
    private static function channelsOf(ClassSpec|array $declaration): array
    {
        $derived = $declaration instanceof ClassSpec ? self::derived($declaration) : $declaration;

        return array_map(
            static fn (array $c): array => [
                $c['name'],
                $c['kind'],
                $c['producer'],
                array_map(static fn (array $e): int => $e['tasklet'], $c['consumers']),
                $c['sink'],
            ],
            $derived['channels'],
        );
    }

    public function testAHeldEdgeWiredTwiceIsOneChannelWithTwoConsumers(): void
    {
        self::assertSame(
            [['in', 'entry', null, [0], null], ['counter', 'inter_task', 0, [1, 2], null]],
            self::channelsOf(FanOut::spec()),
        );
    }

    /**
     * A re-declaration carrying neither transform nor key is the fan-in /
     * repeated-`into` case both references make a true no-op: one consumer
     * edge, not two, and not a refusal.
     */
    public function testABareReDeclaredEdgeIsANoOpNotASecondEdge(): void
    {
        $builder = null;
        self::validate(static function (Builder $c) use (&$builder): void {
            $edge = $c->input('in');
            $edge->into(Echoer::class);
            $edge->into(Echoer::class);
            $builder = $c;
        });

        self::assertSame([[0, 0]], array_map(
            static fn ($edge): array => [$edge->tasklet, count($edge->transforms)],
            $builder->channels[0]->consumers,
        ));
    }

    // -- merge and the router --------------------------------------------------

    /** Each source is its own consumer edge into the one node, with its own mapping and the merge's key. */
    public function testTwoSourcesMergeIntoOneNodeThroughEdgesOfTheirOwn(): void
    {
        $spec = MergeOperative::spec();

        self::assertSame(
            [['ticks', 'entry', null, [0], null], ['quotes', 'entry', null, [0], null]],
            self::channelsOf($spec),
        );
        [$ticks, $quotes] = array_map(static fn ($channel) => $channel->consumers[0], $spec->channels);
        self::assertSame([['tick' => 'A:1', 'symbol' => 'A']], Transforms::apply($ticks->transforms, 'A:1'));
        self::assertSame([['quote' => 'A:2', 'symbol' => 'A']], Transforms::apply($quotes->transforms, 'A:2'));
        self::assertSame('A', ($quotes->keyBy)(['symbol' => 'A']));
    }

    public function testAnEntryAndATaskletOutputMergeIntoOneNode(): void
    {
        $same = static fn (mixed $v): mixed => $v;
        $derived = self::validate(static function (Builder $c) use ($same): void {
            $upstream = $c->input('a')->into(Echoer::class);
            $c->merge()->from($upstream, $same)->from($c->input('b'), $same)->into(Counter::class);
        });

        self::assertSame(
            [['a', 'entry', null, [0], null], ['b', 'entry', null, [1], null], ['echoer', 'inter_task', 0, [1], null]],
            self::channelsOf($derived),
        );
    }

    /** @return iterable<string, array{callable(Builder):void, string}> */
    public static function malformedMerges(): iterable
    {
        $same = static fn (mixed $v): mixed => $v;
        yield 'one source' => [
            static function (Builder $c) use ($same): void {
                $c->merge()->from($c->input('in'), $same)->into(Echoer::class);
            },
            'MergeTooFewSources',
        ];
        yield 'one source twice' => [
            static function (Builder $c) use ($same): void {
                $in = $c->input('in');
                $c->input('other');
                $c->merge()->from($in, $same)->from($in, $same)->into(Echoer::class);
            },
            'DuplicateMergeSource',
        ];
        yield 'a source already mapped into the node' => [
            static function (Builder $c) use ($same): void {
                $in = $c->input('in');
                $in->map($same)->into(Echoer::class);
                $c->merge()->from($in, $same)->from($c->input('other'), $same)->into(Echoer::class);
            },
            'EdgeAlreadyWired',
        ];
    }

    /** @dataProvider malformedMerges */
    public function testAMalformedMergeIsRefusedByTheCoresName(callable $declare, string $name): void
    {
        try {
            self::validate($declare);
            self::fail("the core refuses the merge with $name");
        } catch (DeclarationException $e) {
            self::assertContains($name, $e->refusals);
        }
    }

    /** The router is one node both edges merge into; a second router in the class names its own. */
    public function testEachRouterIsOneNodeItsDocumentsAndKeysMergeInto(): void
    {
        $builder = null;
        $key = static fn (array $t): string => $t['symbol'];
        $derived = self::validate(static function (Builder $c) use (&$builder, $key): void {
            $c->routeLatest($c->input('config'), $c->input('trades'), $key)->sink('routed');
            $c->routeLatestNamed($c->input('limits'), $c->input('orders'), $key, 'limits_router')->sink('limited');
            $builder = $c;
        });

        self::assertSame(['route_latest', 'limits_router'], array_map(
            static fn ($node): string => $node->name,
            $builder->tasklets,
        ));
        self::assertSame(
            [
                ['config', 'entry', null, [0], null],
                ['trades', 'entry', null, [0], null],
                ['routed', 'plugin', 0, [], 'routed'],
                ['limits', 'entry', null, [1], null],
                ['orders', 'entry', null, [1], null],
                ['limited', 'plugin', 1, [], 'limited'],
            ],
            self::channelsOf($derived),
        );
    }

    public function testBranchAndTwoIntoStatementsBuildTheIdenticalDeclaration(): void
    {
        self::assertSame(self::channelsOf(FanOut::spec()), self::channelsOf(Branched::spec()));
    }

    public function testSinkReturnsTheEdgeSoAConsumerWiresBehindIt(): void
    {
        self::assertSame(
            [
                ['in', 'entry', null, [0], null],
                ['hybrid_indicator', 'inter_task', 0, [1], 'hybrid_indicator'],
            ],
            self::channelsOf(Hybrid::spec()),
        );
    }

    /** `keyed()` reaches the channel it was declared on, and the record. */
    public function testKeyedReachesTheChannelOfAHybridSink(): void
    {
        $spec = KeyedHybrid::spec();
        self::derived($spec);

        self::assertTrue(HostClassDecl::of($spec)['channels'][1]['keyed']);
    }

    public function testATransformBeforeOneIntoReachesThatConsumerOnly(): void
    {
        $spec = PerEdgeTransform::spec();
        self::derived($spec);

        self::assertSame(
            [[1, 1], [2, 0]],
            array_map(
                static fn ($edge): array => [$edge->tasklet, count($edge->transforms)],
                $spec->channels[1]->consumers,
            ),
        );
    }

    /**
     * The reference declares exactly this — `tier_commit_block_workflow` opens
     * with `c.effectively_once()` on a workflow class in Rust and Java — and
     * `Operative::classSpec` already reads the builder flag for both kinds, so
     * PHP needed no change for it. This pins that, and that the flag stays off
     * the wire: the core refuses `effectively_once` on a workflow class, and
     * Java's `HostClassDeclaration.forWorkflow` never writes it either.
     */
    public function testAWorkflowClassCarriesItsEffectivelyOnceDeclaration(): void
    {
        $spec = TierWorkflow::spec();
        self::derived($spec);

        self::assertTrue($spec->effectivelyOnce);
        self::assertArrayNotHasKey('effectively_once', HostClassDecl::of($spec));
    }

    // -- channel codecs --------------------------------------------------------
    //
    // A codec is resolved when the class is declared, into the two slots the
    // dispatcher and the plugin frame already read. What every SDK sends alike
    // is `test/fixtures/channel_codecs.txt`; what is PHP's own is asserted here.

    /**
     * The entry decoder and the sink encoder `input` and `sink` resolved.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $sink
     * @return array{callable(string):mixed, callable(mixed):string}
     */
    private static function codecSlots(array $input = [], array $sink = []): array
    {
        $builder = new Builder('codecs');
        $builder->input('in', ...$input)->into(Echoer::class)->sink('out', ...$sink);

        return [$builder->channels[0]->decode, $builder->channels[1]->sinkEncode];
    }

    public function testTheCodecVocabularyIsBytesTextAndJson(): void
    {
        self::assertSame(
            ['Bytes' => 'bytes', 'Text' => 'text', 'Json' => 'json'],
            array_column(ChannelCodec::cases(), 'value', 'name'),
        );
    }

    public function testEverySharedCodecCaseSendsTheBytesTheFixtureRecords(): void
    {
        $rows = array_filter(
            file($this->repositoryFile('test/fixtures/channel_codecs.txt'), FILE_IGNORE_NEW_LINES) ?: [],
            // PHP ships no MessagePack, so a case naming it is not PHP's.
            static fn (string $row): bool => $row !== ''
                && !str_starts_with($row, '#')
                && !str_contains($row, 'msgpack'),
        );
        self::assertNotEmpty($rows, 'the shared codec cases were read');

        foreach ($rows as $row) {
            [$entry, $sink, $payload, $expected] = explode('|', $row, 4);
            [$decode, $encode] = self::codecSlots(self::codecArgument($entry), self::codecArgument($sink));
            $want = str_ends_with($expected, '_error') ? $expected : self::caseBytes($expected);

            self::assertSame($want, self::caseOutcome($decode, $encode, self::caseBytes($payload)), $row);
        }
    }

    public function testAJsonEntryReadsAnObjectAsAnArray(): void
    {
        self::assertSame(['a' => 1], self::codecSlots()[0]('{"a":1}'));
    }

    /** @return array<string, ChannelCodec> */
    private static function codecArgument(string $name): array
    {
        return $name === 'none' ? [] : ['codec' => ChannelCodec::from($name)];
    }

    private static function caseBytes(string $field): string
    {
        return match (true) {
            $field === '<empty>' => '',
            str_starts_with($field, 'hex:') => (string) hex2bin(substr($field, 4)),
            default => $field,
        };
    }

    /** A case records that a step refused, whatever it threw. */
    private static function caseOutcome(callable $decode, callable $encode, string $payload): string
    {
        try {
            $value = $decode($payload);
        } catch (\Throwable) {
            return 'decode_error';
        }

        try {
            return $encode($value);
        } catch (\Throwable) {
            return 'encode_error';
        }
    }

    public function testAJsonFailureNamesTheChannel(): void
    {
        $this->expectException(UnrepresentableValueException::class);
        $this->expectExceptionMessage('out.<root>');

        self::codecSlots()[1](NAN);
    }

    public function testTextRefusesInvalidUtf8OnTheWayInNamingTheChannel(): void
    {
        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('channel `in`');

        self::codecSlots(['codec' => ChannelCodec::Text])[0]("\xFF");
    }

    /** @return iterable<string, array{ChannelCodec, mixed}> */
    public static function unsendable(): iterable
    {
        yield 'a number to a text sink' => [ChannelCodec::Text, 42];
        yield 'invalid UTF-8 to a text sink' => [ChannelCodec::Text, "\xFF"];
        yield 'a number to a bytes sink' => [ChannelCodec::Bytes, 42];
    }

    /** @dataProvider unsendable */
    public function testASinkRefusesAValueItsCodecCannotCarryNamingTheChannel(ChannelCodec $codec, mixed $value): void
    {
        $this->expectException(EmitException::class);
        $this->expectExceptionMessage("channel `out` names the {$codec->value} codec");

        self::codecSlots([], ['codec' => $codec])[1]($value);
    }

    /** A PHP string is its bytes, so a `Bytes` sink takes one, where Python and Node refuse a string there. */
    public function testABytesSinkPassesAnyString(): void
    {
        self::assertSame("\xFF", self::codecSlots([], ['codec' => ChannelCodec::Bytes])[1]("\xFF"));
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, string}> */
    public static function codecBesideCallable(): iterable
    {
        $same = static fn (mixed $v): mixed => $v;
        yield 'an entry' => [['codec' => ChannelCodec::Text, 'decode' => $same], [], 'in'];
        yield 'a sink' => [[], ['codec' => ChannelCodec::Text, 'encode' => $same], 'out'];
    }

    /**
     * @dataProvider codecBesideCallable
     * @param array<string, mixed> $input
     * @param array<string, mixed> $sink
     */
    public function testACodecAndACallableTogetherAreRefused(array $input, array $sink, string $channel): void
    {
        $this->expectException(DeclarationException::class);
        $this->expectExceptionMessage("codecs: $channel: names both a codec");

        self::codecSlots($input, $sink);
    }

    // -- the §5 short form -------------------------------------------------

    /** @return iterable<string, array{class-string<OperativeBase>, class-string<OperativeBase>}> */
    public static function shortFormTwins(): iterable
    {
        yield 'a stream' => [ShortUpcase::class, ShortUpcaseTwinOperative::class];
        yield 'a workflow' => [ShortPlace::class, ShortPlaceTwinOperative::class];
    }

    /**
     * The declaration a short form hands the core is the one its full form
     * hands it, byte for byte, down to the tasklet named after the factory
     * id: which is what keeps every cell where it was when a short form
     * grows into its full form.
     *
     * @dataProvider shortFormTwins
     * @param class-string<OperativeBase> $short
     * @param class-string<OperativeBase> $hand
     */
    public function testAShortFormDeclaresWhatItsHandWrittenTwinDeclares(string $short, string $hand): void
    {
        $spec = $short::spec();

        self::assertSame($hand::spec()->kind, $spec->kind);
        self::assertSame(HostClassDecl::of($hand::spec()), HostClassDecl::of($spec));
        self::assertSame([$spec->factoryId], array_column($spec->tasklets, 'name'));
        self::derived($spec);
    }

    public function testAWorkflowShortFormIsAOnceWorkflow(): void
    {
        self::assertSame(LoopMode::Once, ShortPlace::spec()->workflow?->loopMode);
    }

    public function testASinklessShortFormDeclaresItsEntryIntoItsTaskletAndNothingElse(): void
    {
        $spec = ShortCounter::spec();

        self::assertSame(
            [['ticks', 'entry']],
            array_map(static fn ($c): array => [$c->name, $c->kind], $spec->channels),
        );
        self::assertSame(['sf_counter'], array_column($spec->tasklets, 'name'));
        // The codec reached the entry with no sink written: JSON would refuse this.
        self::assertSame('one', ($spec->channels[0]->decode)('one'));
        // The core takes a channel ending at a tasklet, as it does a full form's.
        self::assertCount(1, self::derived($spec)['channels']);
    }

    public function testAShortFormsTaskletRunsItsBodyThroughItsOwnHooks(): void
    {
        $harness = new Harness(ShortCounter::class);
        $harness->feed('one');
        $harness->feed('two');

        self::assertSame(['seen' => ['one', 'two']], $harness->state());
        self::assertTrue(Harness::replayEquals(ShortCounter::class, ['one', 'two']));
    }

    public function testAChannelsBodyStillDeclaresAFullForm(): void
    {
        self::assertSame([Echoer::class], array_column(ShortUpcaseTwinOperative::spec()->tasklets, 'class'));
    }

    public function testTheRunnerRegistersAShortFormAsTheClassTheAuthorWrote(): void
    {
        $runner = Flezha::createRunner('test')
            ->registerFactory(ShortUpcase::class)
            ->registerFactory(ShortPlace::class);
        $registered = new \ReflectionProperty(Runner::class, 'classes');

        self::assertSame([ShortUpcase::class, ShortPlace::class], $registered->getValue($runner));
        $runner->unregisterFactory(ShortUpcase::class)->unregisterFactory(ShortPlace::class);
        self::assertSame([], $registered->getValue($runner));
    }

    /**
     * The shapes a short form, or a class mistaken for one, is refused for:
     * fed to {@see testTheDeclarationIsRefusedWithItsDefectNamed()}.
     *
     * @return iterable<string, array{callable, string}>
     */
    public static function shortFormRefusals(): iterable
    {
        yield 'a short form with neither body' => [
            static fn (): mixed => Flezha::createRunner('test')->registerFactory(ShortNoBody::class),
            'defines neither `run` nor `channels()`',
        ];
        yield 'a short form whose factory id would be derived' => [
            static fn (): mixed => Flezha::createRunner('test')->registerFactory(ShortUnattributed::class),
            'declares no #[Flezha\Attribute\Operative(factoryId: ...)]',
        ];
        yield 'a short form naming its tasklet' => [
            static fn (): mixed => ShortRenamed::spec(),
            'a short form\'s tasklet is named after its factory id',
        ];
        // A class-level setting is the full form's to write.
        yield 'a short form with persistence off' => [
            static fn (): mixed => ShortUnpersisted::spec(),
            'a short form keeps every class-level setting at its default',
        ];
        yield 'a short form with a workflow setting' => [
            static fn (): mixed => ShortPerInput::spec(),
            'a short form keeps every class-level setting at its default',
        ];
        yield 'a short-form argument beside a topology body' => [
            static fn (): mixed => ShortCodecOnTopology::spec(),
            '`codec` names a channel a short form generates',
        ];
        yield 'a tasklet setting beside a topology body' => [
            static fn (): mixed => ShortTaskOnTopology::spec(),
            '#[Task] carries a tasklet\'s settings, and this class wires its tasklets in '
            . '`channels()`; a full form\'s tasklet settings go on its tasklet classes',
        ];
        yield 'an operative with no run wired as a tasklet' => [
            static fn (): mixed => Definition::of(ShortUpcaseTwinOperative::class),
            'is an operative with no `run`',
        ];
        yield 'a tasklet registered as an operative' => [
            static fn (): mixed => Flezha::createRunner('test')->registerFactory(Echoer::class),
            'is a tasklet, which declares no operative',
        ];
    }

    public function testARunBodyBesideAChannelsBodyIsRefused(): void
    {
        try {
            Flezha::createRunner('test')->registerFactory(ShortWithTopology::class);
            self::fail('a class carrying both bodies declares two operatives in one');
        } catch (DeclarationException $e) {
            self::assertSame(['ShortFormWithTopology'], $e->refusals);
            self::assertStringContainsString("\n  - ShortFormWithTopology: ", $e->getMessage());
        }
    }

    /** @return iterable<string, array{class-string<OperativeBase>, string, string}> */
    public static function shortFormsWithAHookATaskletCouldNotDeclare(): iterable
    {
        yield 'a fold by value' => [
            ShortFoldByValue::class,
            'applyChange',
            '`$state` is taken by value, not by reference',
        ];
        yield 'a narrowed parameter' => [
            ShortNarrowedKey::class,
            'state',
            '`$cellKey` is typed `string`, which does not accept every `?string`',
        ];
        yield 'a wider return' => [ShortWiderKeys::class, 'cellsFor', 'it returns `?array`, not `array`'];
        yield 'a void for a mixed' => [ShortVoidState::class, 'state', 'it returns `void`, not `mixed`'];
        yield 'no return type' => [ShortUntypedReturn::class, 'compensate', 'it declares no return type, not `void`'];
        yield 'an extra required argument' => [
            ShortExtraArgument::class,
            'onTimer',
            'it requires 3 arguments, and the hook is passed 2',
        ];
        yield 'a dropped argument' => [ShortDroppedArgument::class, 'onTimer', 'it has no parameter for `$ctx`'];
        yield 'a narrowing intersection' => [
            ShortIntersectedTimer::class,
            'onTimer',
            '`$timer` is typed `Flezha\Timer&Countable`, which does not accept every `Flezha\Timer`',
        ];
        yield 'a static hook' => [ShortStaticHook::class, 'eventTime', 'it must be a public instance method'];
        yield 'a protected hook' => [ShortHiddenHook::class, 'compensate', 'it must be a public instance method'];
    }

    /**
     * PHP refuses to load a `Tasklet` subclass declaring any of these, and
     * a short form declares no hook to PHP, so the SDK holds it to the same
     * rule: a fold by value would otherwise fold nothing, silently.
     *
     * @dataProvider shortFormsWithAHookATaskletCouldNotDeclare
     * @param class-string<OperativeBase> $class
     */
    public function testAShortFormsHookKeepsATaskletsSignature(string $class, string $hook, string $defect): void
    {
        $this->expectException(DeclarationException::class);
        $this->expectExceptionMessage("`$hook` must be compatible with Tasklet::$hook, as a tasklet's is: $defect");

        $class::spec();
    }

    public function testAShortFormsHookMayWidenWhatATaskletsMay(): void
    {
        $definition = Definition::of(ShortWidenedHooks::class);

        foreach (['state', 'applyChange', 'onTimer', 'migrateState', 'compensate'] as $hook) {
            self::assertTrue($definition->declares($hook), $hook);
        }
    }

    /** @return iterable<string, array{class-string<Tasklet>, string}> */
    public static function taskletsWithAnOperativeArgument(): iterable
    {
        yield 'kind' => [TaskWithKind::class, 'kind'];
        yield 'sink' => [TaskWithSink::class, 'sink'];
        yield 'input' => [TaskWithInput::class, 'input'];
        yield 'codec' => [TaskWithCodec::class, 'codec'];
    }

    /**
     * @dataProvider taskletsWithAnOperativeArgument
     * @param class-string<Tasklet> $class
     */
    public function testATaskletCarryingAnOperativeArgumentIsRefusedNamingIt(string $class, string $argument): void
    {
        $this->expectException(DeclarationException::class);
        $this->expectExceptionMessageMatches("/#\\[Task\\] takes no `$argument`.*#\\[Operative\\]/");

        Flezha::createRunner('test')->registerFactory($class);
    }

    public function testAStreamAndAWorkflowShortFormUnderOneIdCollide(): void
    {
        $registry = new Registry();
        $registry->add(ShortUpcase::spec());

        $this->expectException(FlezhaException::class);
        $this->expectExceptionMessageMatches('/^DuplicateFactoryId: `sf_upcase`/');

        $registry->add(ShortUpcaseWorkflow::spec());
    }

    /**
     * fails when: either `run` a body can override stops being declared
     * `: mixed`, which is what makes a body declaring no output unwritable in
     * PHP, and so a `SinkWithoutOutput` this SDK does not owe.
     */
    public function testNoBodyCanDeclareNoOutput(): void
    {
        foreach ([Tasklet::class, OperativeBase::class] as $base) {
            $returns = (new \ReflectionMethod($base, 'run'))->getReturnType();
            self::assertInstanceOf(\ReflectionNamedType::class, $returns);
            self::assertSame('mixed', $returns->getName(), $base);
        }
    }
}

#[Operative(factoryId: 'fan_out')]
final class FanOut extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $edge = $c->input('in')->into(Counter::class);
        $edge->into(Echoer::class, 'b');
        $edge->into(Echoer::class, 'c');
    }
}

#[Operative(factoryId: 'branched')]
final class Branched extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(Counter::class)->branch(
            static fn (Edge $e) => $e->into(Echoer::class, 'b'),
            static fn (Edge $e) => $e->into(Echoer::class, 'c'),
        );
    }
}

#[Operative(factoryId: 'hybrid')]
final class Hybrid extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(Counter::class)
            ->sink('hybrid_indicator')->into(Echoer::class);
    }
}

#[Operative(factoryId: 'keyed_hybrid')]
final class KeyedHybrid extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(Counter::class)->keyed()
            ->sink('mid')->into(Echoer::class);
    }
}

#[Operative(factoryId: 'per_edge_transform')]
final class PerEdgeTransform extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $edge = $c->input('in')->into(Counter::class);
        $edge->map(static fn (mixed $v): mixed => $v)->into(Echoer::class, 'b');
        $edge->into(Echoer::class, 'c');
    }
}

#[Operative(factoryId: 'tier_workflow')]
final class TierWorkflow extends WorkflowOperative
{
    public static function channels(Builder $c): void
    {
        $c->effectivelyOnce();
        $c->input('in')->into(Counter::class)->keyed()->sink('out');
    }
}

final class Unattributed extends StreamOperative
{
}

#[Operative(factoryId: 'empty_workflow')]
final class EmptyWorkflow extends WorkflowOperative
{
    public static function channels(Builder $c): void
    {
    }
}

#[Operative(factoryId: 'named_sink')]
final class NamedSink extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(Echoer::class)->sink('input');
    }
}

#[Operative(factoryId: 'unnamed_sink')]
final class UnnamedSink extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(Counter::class)->sink();
    }
}

#[Operative(factoryId: 'named_twice')]
final class NamedTwice extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(Echoer::class)->named('a')->sink('b');
    }
}

#[Operative(factoryId: 'trailing_named')]
final class TrailingNamed extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(Echoer::class)->named('renamed');
    }
}

#[Operative(factoryId: 'trailing_keyed')]
final class TrailingKeyed extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(Echoer::class)->keyed();
    }
}

#[Operative(factoryId: 'one_sink_two_channels')]
final class OneSinkTwoChannels extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(Echoer::class)->sink('shared')
            ->into(Counter::class)->sink('shared');
    }
}

#[Operative(factoryId: 'diamond')]
final class Diamond extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $edge = $c->input('in')->into(Echoer::class);
        $edge->named('left')->into(Counter::class);
        $edge->sink();
    }
}

#[Task(cellKeys: ['a', 'b'], mailboxCapacity: 8, schemaVersion: 3)]
class DeclaringBase extends Tasklet
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

/** Declares nothing of its own: everything but the node name is the parent's. */
final class SilentSubclass extends DeclaringBase
{
}

#[Task(name: 'renamed')]
final class RenamingSubclass extends DeclaringBase
{
}

<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\Attribute\Relay;
use Flezha\ChannelCodec;
use Flezha\Ctx;
use Flezha\Definition;
use Flezha\Dispatch\Registry;
use Flezha\Exception\DecodeException;
use Flezha\Exception\FlezhaException;
use Flezha\Ffi\Core;
use Flezha\Flezha;
use Flezha\ChannelBuilder;
use Flezha\Graph\ClassSpec;
use Flezha\Stream;
use Flezha\Graph\HostClassDecl;
use Flezha\Graph\Validate;
use Flezha\Relay as RelayBase;
use Flezha\Tasklet;
use Flezha\Runner;
use Flezha\Testing\TaskletTestHarness;
use Flezha\TimerFiring;
use Flezha\Transforms;

// ---------------------------------------------------------------------------
// The §5 short form

#[Relay(factoryId: 'sf_upcase', output: 'out', codec: ChannelCodec::Text)]
final class ShortUpcase extends RelayBase
{
    use Echoes;
}

/** `ShortUpcase` as its full form, the tasklet named after the factory id. */
#[Relay(factoryId: 'sf_upcase')]
final class ShortUpcaseTwinRelay extends RelayBase
{
    public static function channels(ChannelBuilder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)
            ->into(Echoer::class, 'sf_upcase')
            ->output('out');
    }
}

#[Relay(factoryId: 'sf_place', run: 'steps', output: 'placed', input: 'order', codec: ChannelCodec::Text)]
final class ShortPlace extends RelayBase
{
    use Echoes;
}

#[Relay(factoryId: 'sf_place', run: 'steps')]
final class ShortPlaceTwinRelay extends RelayBase
{
    public static function channels(ChannelBuilder $c): void
    {
        $c->input('order', codec: ChannelCodec::Text)
            ->into(Echoer::class, 'sf_place')
            ->output('placed');
    }
}

/** A steps short form under the id `ShortUpcase` already holds. */
#[Relay(factoryId: 'sf_upcase', run: 'steps', output: 'placed')]
final class ShortUpcaseSteps extends RelayBase
{
    use Echoes;
}

#[Relay(factoryId: 'sf_both', output: 'out')]
final class ShortWithTopology extends RelayBase
{
    use Echoes;

    public static function channels(ChannelBuilder $c): void
    {
        $c->input('input')->into(Echoer::class)->output('out');
    }
}

#[Relay(factoryId: 'sf_no_body')]
final class ShortNoBody extends RelayBase
{
}

final class ShortUnattributed extends RelayBase
{
    use Echoes;
}

#[Relay(factoryId: 'sf_renamed')]
#[Tasklet\Spec(name: 'elsewhere')]
final class ShortRenamed extends RelayBase
{
    use Echoes;
}

#[Relay(factoryId: 'sf_unpersisted', durable: false)]
final class ShortUnpersisted extends RelayBase
{
    use Echoes;
}

#[Relay(factoryId: 'sf_per_input', run: ['steps' => ['each' => 'per_input']])]
final class ShortPerInput extends RelayBase
{
    use Echoes;
}

#[Relay(
    factoryId: 'sf_per_input_time_limited',
    run: ['steps' => ['each' => 'per_input']],
    maxDurationMs: 60_000,
)]
final class ShortPerInputTimeLimited extends RelayBase
{
    use Echoes;
}

#[Relay(factoryId: 'sf_codec_on_topology', codec: ChannelCodec::Text)]
final class ShortCodecOnTopology extends RelayBase
{
    public static function channels(ChannelBuilder $c): void
    {
        $c->input('input')->into(Echoer::class)->output('out');
    }
}

#[Relay(factoryId: 'sf_task_on_topology')]
#[Tasklet\Spec(mailboxCapacity: 8)]
final class ShortTaskOnTopology extends RelayBase
{
    public static function channels(ChannelBuilder $c): void
    {
        $c->input('input')->into(Echoer::class)->output('out');
    }
}

/** A fold taking the state by value, which would fold into a copy. */
#[Relay(factoryId: 'sf_fold_by_value')]
final class ShortFoldByValue extends RelayBase
{
    use Echoes;

    public function initState(?string $cellKey): mixed
    {
        return ['seen' => []];
    }

    public function applyChange(mixed $state, mixed $change): void
    {
        $state['seen'][] = $change;
    }
}

#[Relay(factoryId: 'sf_narrowed_key')]
final class ShortNarrowedKey extends RelayBase
{
    use Echoes;

    public function initState(string $cellKey): mixed
    {
        return [];
    }
}

#[Relay(factoryId: 'sf_wider_keys')]
final class ShortWiderKeys extends RelayBase
{
    use Echoes;

    public function cellsFor(mixed $input): ?array
    {
        return null;
    }
}

#[Relay(factoryId: 'sf_void_state')]
final class ShortVoidState extends RelayBase
{
    use Echoes;

    public function initState(?string $cellKey): void
    {
    }
}

#[Relay(factoryId: 'sf_untyped_return', run: 'steps')]
final class ShortUntypedReturn extends RelayBase
{
    use Echoes;

    public function compensate(array $outputs, Ctx $ctx)
    {
    }
}

#[Relay(factoryId: 'sf_extra_argument')]
final class ShortExtraArgument extends RelayBase
{
    use Echoes;

    public function onTimer(TimerFiring $timer, Ctx $ctx, int $extra): void
    {
    }
}

#[Relay(factoryId: 'sf_dropped_argument')]
final class ShortDroppedArgument extends RelayBase
{
    use Echoes;

    public function onTimer(TimerFiring $timer): void
    {
    }
}

#[Relay(factoryId: 'sf_intersected_timer')]
final class ShortIntersectedTimer extends RelayBase
{
    use Echoes;

    public function onTimer(TimerFiring&\Countable $timer, Ctx $ctx): void
    {
    }
}

#[Relay(factoryId: 'sf_static_hook')]
final class ShortStaticHook extends RelayBase
{
    use Echoes;

    public static function eventTime(mixed $input): int
    {
        return 0;
    }
}

#[Relay(factoryId: 'sf_hidden_hook', run: 'steps')]
final class ShortHiddenHook extends RelayBase
{
    use Echoes;

    protected function compensate(array $outputs, Ctx $ctx): void
    {
    }
}

/** Every widening PHP lets an override of these hooks make. */
#[Relay(factoryId: 'sf_widened_hooks', run: 'steps')]
final class ShortWidenedHooks extends RelayBase
{
    use Echoes;

    public function initState($cellKey): array
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

#[Tasklet\Spec(name: 'sf_task_run', run: 'steps')]
final class TaskWithRun extends Tasklet
{
    use Echoes;
}

#[Tasklet\Spec(name: 'sf_task_sink', output: 'out')]
final class TaskWithOutput extends Tasklet
{
    use Echoes;
}

#[Tasklet\Spec(name: 'sf_task_input', input: 'prices')]
final class TaskWithInput extends Tasklet
{
    use Echoes;
}

#[Tasklet\Spec(name: 'sf_task_codec', codec: ChannelCodec::Text)]
final class TaskWithCodec extends Tasklet
{
    use Echoes;
}

#[Tasklet\Spec(name: 'fold_without_state')]
final class FoldWithoutState extends Tasklet
{
    public function applyChange(mixed &$state, mixed $change): void
    {
    }

    public function run(mixed $input, Ctx $ctx): void
    {
    }
}

/**
 * What this package's authoring surface answers, and what it hands the core.
 *
 * The topology itself is judged once, in `flz_core::host::register::check`, and
 * the reference refusals are pinned by `test/fixtures/reference_topologies.txt`
 * — not here. What is left here is the ergonomics: which shapes the fluent
 * builder BUILDS, the defects a record cannot carry (a PHP class that does not
 * define the method its declaration promises, a plugin output on a channel no
 * tasklet produces on), and that the core's verdict reaches an author as this
 * package's own refusal.
 */
final class AuthoringTest extends RepositoryTestCase
{
    /** @return iterable<string, array{callable, string, string}> */
    public static function refusals(): iterable
    {
        yield 'a fold with nothing to fold into' => [
            static fn (): mixed => Definition::of(FoldWithoutState::class),
            'topology.change_without_state',
            'declares applyChange but no initial state',
        ];
        // The other refusal the builder keeps: a channel with no producer has
        // no value to serialize, and the core reads an output as the terminal of
        // the channel it sits on — it cannot see this one is controller-fed.
        yield 'a sink fed by an entry channel' => [
            static fn (): mixed => self::validate(static function (ChannelBuilder $c): void {
                $c->input('in')->output();
            }),
            'topology.output_on_entry',
            'an output is fed by a tasklet',
        ];
        // The one refusal the builder raises under a code of the core's.
        yield 'two tasklets claiming one node identity' => [
            static fn (): mixed => self::validate(static function (ChannelBuilder $c): void {
                $in = $c->input('in');
                $in->into(Echoer::class, 'twin');
                $in->into(Keyed::class, 'twin');
            }),
            'topology.node_identity_collision',
            "node identity 'twin'",
        ];
        // `Validate::spec` IS the submission: the record crosses the ABI and
        // the core's verdict comes back as this package's own refusal, in the
        // core's vocabulary.
        yield 'the core verdict, rendered here' => [
            static fn (): mixed => self::validate(static function (ChannelBuilder $c): void {
                $c->input('in')
                    ->keyBy(static fn (mixed $v): string => 'a')
                    ->keyBy(static fn (mixed $v): string => 'b')
                    ->into(Keyed::class);
            }),
            'topology.duplicate_key_by',
            "channel 'in' declares key_by twice before one into",
        ];
    }

    /**
     * @dataProvider refusals
     * @dataProvider shortFormRefusals
     */
    public function testTheDeclarationIsRefusedWithItsDefectNamed(callable $declare, string $code, string $defect): void
    {
        $this->expectException(FlezhaException::class);
        $this->expectExceptionCode($code);
        $this->expectExceptionMessage($defect);

        $declare();
    }

    /**
     * And every issue as data, so nothing here reads them out of prose.
     */
    public function testARefusedDeclarationCarriesTheCoresOwnCodes(): void
    {
        try {
            self::validate(static function (ChannelBuilder $c): void {
                $c->input('in')
                    ->keyBy(static fn (mixed $v): string => 'a')
                    ->keyBy(static fn (mixed $v): string => 'b')
                    ->into(Keyed::class);
            });
            self::fail('the core refuses two keys before one into');
        } catch (FlezhaException $e) {
            self::assertSame(['topology.duplicate_key_by'], array_column($e->details['issues'], 'code'));
        }
    }

    public function testARelayWithoutTheAttributeIsRefused(): void
    {
        $this->expectException(FlezhaException::class);
        $this->expectExceptionCode('topology.missing_factory_id');
        $this->expectExceptionMessage('declares no factory id');

        Unattributed::spec();
    }

    public function testAStepsClassDeclaringNoTaskletsIsRefused(): void
    {
        $this->expectException(FlezhaException::class);
        $this->expectExceptionCode('topology.no_tasklets');

        self::validate(static function (ChannelBuilder $c): void {
        }, 'steps');
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
     * plugin path, so `named('input')->output('pass_out')` produced a channel
     * called `pass_out` — and the channel name is what a forwarded frame
     * carries and what selects the receiving relay's entry channel.
     */
    public function testANamedEdgeTerminatingOnAnOutputNamesTheChannelToo(): void
    {
        $spec = NamedSink::spec();
        self::derived($spec);

        self::assertSame(['in', 'input'], array_map(
            static fn ($channel): string => $channel->name,
            $spec->channels,
        ));
    }

    /**
     * `sdk-graph-authoring`: the no-argument `output()` names nothing, so the
     * channel keeps the PRODUCER NODE's name — the derivation a channel with
     * no output at all would take.
     */
    public function testAnUnnamedEdgeTerminatingOnAnOutputTakesTheProducingNodesName(): void
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

        self::assertSame(['a', 'b'], $definition->decl->cellKeys);
        self::assertSame(8, $definition->decl->mailboxCapacity);
        self::assertSame(3, $definition->decl->schemaVersion);
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
    private static function validate(callable $channels, mixed $run = null, mixed $durable = null): array
    {
        $core = Core::create();

        try {
            return Validate::spec(self::spec($channels, $run, $durable), $core);
        } finally {
            $core->free();
        }
    }

    /** A class declared inline, with the settings tree as written. */
    private static function spec(callable $channels, mixed $run = null, mixed $durable = null): ClassSpec
    {
        $builder = new ChannelBuilder('under_test');
        $channels($builder);
        $builder->seal();

        return new ClassSpec(
            factoryId: 'under_test',
            description: null,
            maxDurationMs: null,
            run: $run,
            durable: $durable,
            tasklets: $builder->tasklets,
            channels: $builder->channels,
        );
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

    /** The output names the channel; the no-argument form leaves it derived. */
    public function testTheOutputNamesTheChannelAndTheNoArgumentFormDoesNot(): void
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

    /** A channel both `named()` and `output()` name is refused, naming both. */
    public function testAChannelNamedTwiceIsRefusedNamingBoth(): void
    {
        $this->expectException(FlezhaException::class);
        $this->expectExceptionCode('topology.channel_named_twice');

        self::derived(NamedTwice::spec());
    }

    /** A channel option with nothing wired after it still reaches the channel. */
    public function testAChannelOptionWithNothingWiredAfterItStillReachesTheChannel(): void
    {
        self::assertSame(
            ['in', 'renamed'],
            array_column(self::derived(TrailingNamed::spec())['channels'], 'name'),
        );
        $this->expectException(FlezhaException::class);
        $this->expectExceptionCode('topology.keyed_channel_without_plugin_wire');

        self::derived(TrailingKeyed::spec());
    }

    /** Two channels naming one output is a duplicate channel name. */
    public function testTwoChannelsNamingOneOutputIsRefused(): void
    {
        $this->expectException(FlezhaException::class);
        $this->expectExceptionCode('topology.duplicate_output_name');

        self::derived(OneSinkTwoChannels::spec());
    }

    /** An output beside a call on one channel is refused as ending twice. */
    public function testAnOutputBesideACallOnOneChannelIsRefused(): void
    {
        try {
            self::validate(static function (ChannelBuilder $c): void {
                $c->input('in')->into(Echoer::class)->output('out')
                    ->call('pricing', deadlineMs: 500)->into(Counter::class);
            });
            self::fail('a channel ending twice was accepted');
        } catch (FlezhaException $e) {
            self::assertSame(['topology.channel_ends_twice'], array_column($e->details['issues'], 'code'));
            self::assertSame('out', $e->details['issues'][0]['details']['channel']);
        }
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
     * output name are what the core made of the chains this builder recorded.
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
        self::validate(static function (ChannelBuilder $c) use (&$builder): void {
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
        $spec = MergeRelay::spec();

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
        $derived = self::validate(static function (ChannelBuilder $c) use ($same): void {
            $upstream = $c->input('a')->into(Echoer::class);
            $c->merge()->from($upstream, $same)->from($c->input('b'), $same)->into(Counter::class);
        });

        self::assertSame(
            [['a', 'entry', null, [0], null], ['b', 'entry', null, [1], null], ['echoer', 'inter_task', 0, [1], null]],
            self::channelsOf($derived),
        );
    }

    /** @return iterable<string, array{callable(ChannelBuilder):void, string}> */
    public static function malformedMerges(): iterable
    {
        $same = static fn (mixed $v): mixed => $v;
        yield 'one source' => [
            static function (ChannelBuilder $c) use ($same): void {
                $c->merge()->from($c->input('in'), $same)->into(Echoer::class);
            },
            'topology.merge_too_few_sources',
        ];
        yield 'one source twice' => [
            static function (ChannelBuilder $c) use ($same): void {
                $in = $c->input('in');
                $c->input('other');
                $c->merge()->from($in, $same)->from($in, $same)->into(Echoer::class);
            },
            'topology.duplicate_merge_source',
        ];
        yield 'a source already mapped into the node' => [
            static function (ChannelBuilder $c) use ($same): void {
                $in = $c->input('in');
                $in->map($same)->into(Echoer::class);
                $c->merge()->from($in, $same)->from($c->input('other'), $same)->into(Echoer::class);
            },
            'topology.edge_already_wired',
        ];
    }

    /** @dataProvider malformedMerges */
    public function testAMalformedMergeIsRefusedByTheCoresName(callable $declare, string $name): void
    {
        try {
            self::validate($declare);
            self::fail("the core refuses the merge with $name");
        } catch (FlezhaException $e) {
            self::assertContains($name, array_column($e->details['issues'], 'code'));
        }
    }

    /** The router is one node both edges merge into; a second router in the class names its own. */
    public function testEachRouterIsOneNodeItsDocumentsAndKeysMergeInto(): void
    {
        $builder = null;
        $key = static fn (array $t): string => $t['symbol'];
        $derived = self::validate(static function (ChannelBuilder $c) use (&$builder, $key): void {
            $c->routeLatest($c->input('config'), $c->input('trades'), $key)->output('routed');
            $c->routeLatestNamed($c->input('limits'), $c->input('orders'), $key, 'limits_router')->output('limited');
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

    public function testOutputReturnsTheEdgeSoAConsumerWiresBehindIt(): void
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
    public function testKeyedReachesTheChannelOfAHybridOutput(): void
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

    /** @return iterable<string, array{string, array<string, mixed>, string, string}> */
    public static function misplacedSettings(): iterable
    {
        yield 'each under flow' => ['run', ['flow' => ['each' => 'per_input']], 'each', 'flow'];
        yield 'correction_ms under steps' => ['run', ['steps' => ['correction_ms' => 5_000]], 'correction_ms', 'steps'];
        yield 'ack under at_least_once' => [
            'run', ['flow' => ['exactly' => ['at_least_once' => ['ack' => 'checkpoint']]]], 'ack', 'at_least_once',
        ];
        yield 'sleep_after under false' => ['durable', ['false' => ['sleep_after' => 1_000]], 'sleep_after', 'false'];
    }

    /**
     * The tree reaches the core as written, and a setting under a parent that
     * does not carry it is refused there by its own name and its parent's.
     *
     * @dataProvider misplacedSettings
     * @param array<string, mixed> $tree
     */
    public function testAMisplacedSettingIsRefusedNamingItAndItsParent(
        string $setting,
        array $tree,
        string $field,
        string $parent,
    ): void {
        try {
            self::validate(static fn (ChannelBuilder $c) => $c->input('in')->into(Echoer::class), ...[$setting => $tree]);
            self::fail("$field under $parent was accepted");
        } catch (FlezhaException $e) {
            self::assertSame(['topology.unknown_field'], array_column($e->details['issues'], 'code'));
            self::assertStringContainsString($field, $e->getMessage());
            self::assertStringContainsString("'$parent'", $e->getMessage());
        }
    }

    public function testAFlowClassMayKeepItsWorkRemote(): void
    {
        $channels = static fn (ChannelBuilder $c) => $c->input('in')->into(Echoer::class);
        self::validate($channels, durable: 'remote');
        self::assertSame('remote', HostClassDecl::of(self::spec($channels, durable: 'remote'))['durable']);
    }

    /** @return iterable<string, array{string|array<string, mixed>, string}> */
    public static function valuesOutsideTheirChoice(): iterable
    {
        yield 'a loop shape' => [['steps' => ['each' => 'forever']], 'forever'];
        yield 'a failure mode' => [['steps' => ['on_failure' => 'best_effort']], 'best_effort'];
        yield 'a retired kind' => ['bounded', 'bounded'];
    }

    /**
     * @dataProvider valuesOutsideTheirChoice
     * @param string|array<string, mixed> $run
     */
    public function testAValueOutsideItsChoiceIsRefusedNamingIt(string|array $run, string $value): void
    {
        try {
            self::validate(static fn (ChannelBuilder $c) => $c->input('in')->into(Echoer::class), $run);
            self::fail("the run $value was accepted");
        } catch (FlezhaException $e) {
            self::assertSame(['topology.invalid_setting'], array_column($e->details['issues'], 'code'));
            self::assertStringContainsString($value, $e->getMessage());
        }
    }

    public function testTheDurabilityReachesTheDeclarationAsWritten(): void
    {
        $durable = static fn (mixed $tree): mixed => HostClassDecl::of(self::spec(
            static fn (ChannelBuilder $c) => $c->input('in')->into(Echoer::class),
            'steps',
            $tree,
        ))['durable'] ?? null;

        self::assertSame(
            [null, false, ['local' => ['sleep_after' => false]], ['remote' => ['keep_finished' => 24]]],
            [
                $durable(null),
                $durable(false),
                $durable(['local' => ['sleep_after' => false]]),
                $durable(['remote' => ['keep_finished' => 24]]),
            ],
        );
    }

    // -- channel codecs --------------------------------------------------------
    //
    // A codec is resolved when the class is declared, into the two slots the
    // dispatcher and the plugin frame already read. What every SDK sends alike
    // is `test/fixtures/channel_codecs.txt`; what is PHP's own is asserted here.

    /**
     * The entry decoder and the output encoder `input` and `output` resolved.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $output
     * @return array{callable(string):mixed, callable(mixed):string}
     */
    private static function codecSlots(array $input = [], array $sink = []): array
    {
        $builder = new ChannelBuilder('codecs');
        $builder->input('in', ...$input)->into(Echoer::class)->output('out', ...$sink);

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
        $this->expectException(FlezhaException::class);
        $this->expectExceptionCode('runtime.value_not_json');
        $this->expectExceptionMessage("channel 'out' sends a float that has no JSON form");

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
    public function testAnOutputRefusesAValueItsCodecCannotCarryNamingTheChannel(ChannelCodec $codec, mixed $value): void
    {
        $this->expectException(FlezhaException::class);
        $this->expectExceptionCode('runtime.value_wrong_for_codec');
        $this->expectExceptionMessageMatches("/^channel 'out' names the {$codec->value} codec and cannot send a /");

        self::codecSlots([], ['codec' => $codec])[1]($value);
    }

    /** A PHP string is its bytes, so a `Bytes` output takes one, where Python and Node refuse a string there. */
    public function testABytesOutputPassesAnyString(): void
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
     * @param array<string, mixed> $output
     */
    public function testACodecAndACallableTogetherAreRefused(array $input, array $sink, string $channel): void
    {
        $this->expectException(FlezhaException::class);
        $this->expectExceptionCode('topology.codec_with_callable');
        $this->expectExceptionMessage("channel '$channel' names both a codec");

        self::codecSlots($input, $sink);
    }

    // -- the §5 short form -------------------------------------------------

    /** @return iterable<string, array{class-string<RelayBase>, class-string<RelayBase>}> */
    public static function shortFormTwins(): iterable
    {
        yield 'a flow relay' => [ShortUpcase::class, ShortUpcaseTwinRelay::class];
        yield 'a steps relay' => [ShortPlace::class, ShortPlaceTwinRelay::class];
    }

    /**
     * The declaration a short form hands the core is the one its full form
     * hands it, byte for byte, down to the tasklet named after the factory
     * id: which is what keeps every cell where it was when a short form
     * grows into its full form.
     *
     * @dataProvider shortFormTwins
     * @param class-string<RelayBase> $short
     * @param class-string<RelayBase> $hand
     */
    public function testAShortFormDeclaresWhatItsHandWrittenTwinDeclares(string $short, string $hand): void
    {
        $spec = $short::spec();

        self::assertSame($hand::spec()->run, $spec->run);
        self::assertSame(HostClassDecl::of($hand::spec()), HostClassDecl::of($spec));
        self::assertSame([$spec->factoryId], array_column($spec->tasklets, 'name'));
        self::derived($spec);
    }

    public function testAStepsShortFormDeclaresTheLoopShapeItWrites(): void
    {
        $spec = ShortPerInput::spec();

        self::assertSame(['steps' => ['each' => 'per_input']], HostClassDecl::of($spec)['run']);
        self::assertSame(['sf_per_input'], array_column($spec->tasklets, 'name'));
        self::derived($spec);
    }

    public function testAShortFormTakesTheClassSettings(): void
    {
        $unkept = HostClassDecl::of(ShortUnpersisted::spec());
        $limited = HostClassDecl::of(ShortPerInputTimeLimited::spec());

        self::assertSame([false, 60_000], [$unkept['durable'], $limited['max_duration_ms']]);
        self::derived(ShortPerInputTimeLimited::spec());
    }

    public function testAShortFormWithNoOutputDeclaresItsEntryIntoItsTaskletAndNothingElse(): void
    {
        $spec = ShortCounter::spec();

        self::assertSame(
            [['ticks', 'entry']],
            array_map(static fn ($c): array => [$c->name, $c->kind], $spec->channels),
        );
        self::assertSame(['sf_counter'], array_column($spec->tasklets, 'name'));
        // The codec reached the entry with no output written: JSON would refuse this.
        self::assertSame('one', ($spec->channels[0]->decode)('one'));
        // The core takes a channel ending at a tasklet, as it does a full form's.
        self::assertCount(1, self::derived($spec)['channels']);
    }

    public function testAShortFormsTaskletRunsItsBodyThroughItsOwnHooks(): void
    {
        $harness = new TaskletTestHarness(ShortCounter::class);
        $harness->feed('one');
        $harness->feed('two');

        self::assertSame(['seen' => ['one', 'two']], $harness->state());
        $harness->replayEquals();
    }

    public function testAChannelsBodyStillDeclaresAFullForm(): void
    {
        self::assertSame([Echoer::class], array_column(ShortUpcaseTwinRelay::spec()->tasklets, 'class'));
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
     * @return iterable<string, array{callable, string, string}>
     */
    public static function shortFormRefusals(): iterable
    {
        yield 'a short form with neither body' => [
            static fn (): mixed => Flezha::createRunner('test')->registerFactory(ShortNoBody::class),
            'topology.missing_body',
            "declares no body; it needs `channels()`, or one tasklet's `run` as the short form",
        ];
        yield 'a short form whose factory id would be derived' => [
            static fn (): mixed => Flezha::createRunner('test')->registerFactory(ShortUnattributed::class),
            'topology.missing_factory_id',
            'declares no factory id',
        ];
        yield 'a short form naming its tasklet' => [
            static fn (): mixed => ShortRenamed::spec(),
            'topology.short_form_name_mismatch',
            'a short form\'s tasklet is named after its id',
        ];
        yield 'a short-form argument beside a topology body' => [
            static fn (): mixed => ShortCodecOnTopology::spec(),
            'topology.short_form_field_on_full_form',
            'declares `codec` beside its channels body',
        ];
        yield 'a tasklet setting beside a topology body' => [
            static fn (): mixed => ShortTaskOnTopology::spec(),
            'topology.short_form_field_on_full_form',
            'declares #[Tasklet\Spec] beside its channels body',
        ];
        yield 'a relay with no run wired as a tasklet' => [
            static fn (): mixed => Definition::of(ShortUpcaseTwinRelay::class),
            'topology.missing_body',
            'declares no body; it needs `run`',
        ];
        yield 'a tasklet registered as a relay' => [
            static fn (): mixed => Flezha::createRunner('test')->registerFactory(Echoer::class),
            'topology.no_relay_exported',
            'exports no relay declaration',
        ];
    }

    public function testARunBodyBesideAChannelsBodyIsRefused(): void
    {
        try {
            Flezha::createRunner('test')->registerFactory(ShortWithTopology::class);
            self::fail('a class carrying both bodies declares two relays in one');
        } catch (FlezhaException $e) {
            self::assertSame('topology.short_form_with_topology', $e->getCode());
            self::assertSame(['factory_id' => 'sf_both'], $e->details);
        }
    }

    /** @return iterable<string, array{class-string<RelayBase>, string, string}> */
    public static function shortFormsWithAHookATaskletCouldNotDeclare(): iterable
    {
        yield 'a fold by value' => [
            ShortFoldByValue::class,
            'applyChange',
            '`$state` is taken by value, not by reference',
        ];
        yield 'a narrowed parameter' => [
            ShortNarrowedKey::class,
            'initState',
            '`$cellKey` is typed `string`, which does not accept every `?string`',
        ];
        yield 'a wider return' => [ShortWiderKeys::class, 'cellsFor', 'it returns `?array`, not `array`'];
        yield 'a void for a mixed' => [ShortVoidState::class, 'initState', 'it returns `void`, not `mixed`'];
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
            '`$timer` is typed `Flezha\TimerFiring&Countable`, which does not accept every `Flezha\TimerFiring`',
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
     * @param class-string<RelayBase> $class
     */
    public function testAShortFormsHookKeepsATaskletsSignature(string $class, string $hook, string $defect): void
    {
        $this->expectException(FlezhaException::class);
        $this->expectExceptionCode('topology.invalid_tasklet');
        $this->expectExceptionMessage("`$hook` must be compatible with Tasklet::$hook, as a tasklet's is: $defect");

        $class::spec();
    }

    public function testAShortFormsHookMayWidenWhatATaskletsMay(): void
    {
        $definition = Definition::of(ShortWidenedHooks::class);

        foreach (['initState', 'applyChange', 'onTimer', 'migrateState', 'compensate'] as $hook) {
            self::assertTrue($definition->declares($hook), $hook);
        }
    }

    /** @return iterable<string, array{class-string<Tasklet>, string}> */
    public static function taskletsWithARelayArgument(): iterable
    {
        yield 'run' => [TaskWithRun::class, 'run'];
        yield 'output' => [TaskWithOutput::class, 'output'];
        yield 'input' => [TaskWithInput::class, 'input'];
        yield 'codec' => [TaskWithCodec::class, 'codec'];
    }

    /**
     * @dataProvider taskletsWithARelayArgument
     * @param class-string<Tasklet> $class
     */
    public function testATaskletCarryingARelayArgumentIsRefusedNamingIt(string $class, string $argument): void
    {
        $this->expectException(FlezhaException::class);
        $this->expectExceptionCode('topology.relay_field_on_tasklet');
        $this->expectExceptionMessageMatches("/declares `$argument` on #\\[Tasklet\\\\Spec\\].*#\\[Relay\\]/");

        Flezha::createRunner('test')->registerFactory($class);
    }

    public function testAFlowAndAStepsShortFormUnderOneIdCollide(): void
    {
        $registry = new Registry();
        $registry->add(ShortUpcase::spec());

        $this->expectException(FlezhaException::class);
        $this->expectExceptionCode('topology.duplicate_factory_id');
        $this->expectExceptionMessage("factory 'sf_upcase'");

        $registry->add(ShortUpcaseSteps::spec());
    }

    /**
     * fails when: either `run` a body can override stops being declared
     * `: void`, which is what holds every body to emitting through its
     * context. No body declares an output type, so this SDK owes no
     * `topology.output_without_type`.
     */
    public function testEveryBodyIsVoid(): void
    {
        foreach ([Tasklet::class, RelayBase::class] as $base) {
            $returns = (new \ReflectionMethod($base, 'run'))->getReturnType();
            self::assertInstanceOf(\ReflectionNamedType::class, $returns);
            self::assertSame('void', $returns->getName(), $base);
        }
    }
}

#[Relay(factoryId: 'fan_out')]
final class FanOut extends RelayBase
{
    public static function channels(ChannelBuilder $c): void
    {
        $edge = $c->input('in')->into(Counter::class);
        $edge->into(Echoer::class, 'b');
        $edge->into(Echoer::class, 'c');
    }
}

#[Relay(factoryId: 'branched')]
final class Branched extends RelayBase
{
    public static function channels(ChannelBuilder $c): void
    {
        $c->input('in')->into(Counter::class)->branch(
            static fn (Stream $e) => $e->into(Echoer::class, 'b'),
            static fn (Stream $e) => $e->into(Echoer::class, 'c'),
        );
    }
}

#[Relay(factoryId: 'hybrid')]
final class Hybrid extends RelayBase
{
    public static function channels(ChannelBuilder $c): void
    {
        $c->input('in')->into(Counter::class)
            ->output('hybrid_indicator')->into(Echoer::class);
    }
}

#[Relay(factoryId: 'keyed_hybrid')]
final class KeyedHybrid extends RelayBase
{
    public static function channels(ChannelBuilder $c): void
    {
        $c->input('in')->into(Counter::class)->keyed()
            ->output('mid')->into(Echoer::class);
    }
}

#[Relay(factoryId: 'per_edge_transform')]
final class PerEdgeTransform extends RelayBase
{
    public static function channels(ChannelBuilder $c): void
    {
        $edge = $c->input('in')->into(Counter::class);
        $edge->map(static fn (mixed $v): mixed => $v)->into(Echoer::class, 'b');
        $edge->into(Echoer::class, 'c');
    }
}

final class Unattributed extends RelayBase
{
}

#[Relay(factoryId: 'named_sink')]
final class NamedSink extends RelayBase
{
    public static function channels(ChannelBuilder $c): void
    {
        $c->input('in')->into(Echoer::class)->output('input');
    }
}

#[Relay(factoryId: 'unnamed_sink')]
final class UnnamedSink extends RelayBase
{
    public static function channels(ChannelBuilder $c): void
    {
        $c->input('in')->into(Counter::class)->output();
    }
}

#[Relay(factoryId: 'named_twice')]
final class NamedTwice extends RelayBase
{
    public static function channels(ChannelBuilder $c): void
    {
        $c->input('in')->into(Echoer::class)->named('a')->output('b');
    }
}

#[Relay(factoryId: 'trailing_named')]
final class TrailingNamed extends RelayBase
{
    public static function channels(ChannelBuilder $c): void
    {
        $c->input('in')->into(Echoer::class)->named('renamed');
    }
}

#[Relay(factoryId: 'trailing_keyed')]
final class TrailingKeyed extends RelayBase
{
    public static function channels(ChannelBuilder $c): void
    {
        $c->input('in')->into(Echoer::class)->keyed();
    }
}

#[Relay(factoryId: 'one_sink_two_channels')]
final class OneSinkTwoChannels extends RelayBase
{
    public static function channels(ChannelBuilder $c): void
    {
        $c->input('in')->into(Echoer::class)->output('shared')
            ->into(Counter::class)->output('shared');
    }
}

#[Relay(factoryId: 'diamond')]
final class Diamond extends RelayBase
{
    public static function channels(ChannelBuilder $c): void
    {
        $edge = $c->input('in')->into(Echoer::class);
        $edge->named('left')->into(Counter::class);
        $edge->output();
    }
}

#[Tasklet\Spec(name: 'declaring_base', cellKeys: ['a', 'b'], mailboxCapacity: 8, schemaVersion: 3)]
class DeclaringBase extends Tasklet
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

/** Declares only its name: everything else is the parent's. */
#[Tasklet\Spec(name: 'silent_subclass')]
final class SilentSubclass extends DeclaringBase
{
}

#[Tasklet\Spec(name: 'renamed')]
final class RenamingSubclass extends DeclaringBase
{
}

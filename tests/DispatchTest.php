<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\Ctx;
use Flezha\Dispatch\Dispatcher;
use Flezha\Dispatch\Registry;
use Flezha\Codec\Json;
use Flezha\Exception\FlezhaException;
use Flezha\Ffi\Core;
use Flezha\Graph\Validate;
use Flezha\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Every activation kind, against a real core context.
 *
 * A real one rather than a double: the context is what `Ctx::spawnSteps`
 * and the completion path talk to, and a double would prove that the
 * dispatcher agrees with the double. Nothing here starts a runner, so no
 * controller connection is attempted.
 */
final class DispatchTest extends TestCase
{
    use Dispatches;

    private static ?Core $core = null;
    private Dispatcher $dispatcher;
    private Registry $registry;

    public static function setUpBeforeClass(): void
    {
        self::$core = Core::create();
    }

    public static function tearDownAfterClass(): void
    {
        self::$core?->free();
        self::$core = null;
    }

    protected function setUp(): void
    {
        $this->registry = new Registry();
        foreach (
            [EchoRelay::class, CounterRelay::class, KeyedRelay::class,
                MixedStampRelay::class, StepsEmitRelay::class,
                StepsEmitFlowRelay::class, StepsCarryRelay::class, JsonEntryRelay::class,
                MergeRelay::class,
                RouteLatestRelay::class, FannedRelay::class, CompletingSteps::class,
                CompletingFlow::class, FlakyEntryRelay::class, TimingSteps::class,
                TimingFlow::class, SplitKeyedRelay::class] as $class
        ) {
            $spec = $class::spec();
            Validate::spec($spec, self::core());
            $this->registry->add($spec);
        }
        $this->dispatcher = new Dispatcher($this->registry, self::core());
    }

    public function testAnInputReachesTheTaskletAndWhatItEmitsIsSent(): void
    {
        $result = $this->work('echo_relay', 0, [self::input('"hello"')]);

        self::assertSame('work', $result['outcome']);
        self::assertSame('ok', $result['effects'][0]['verdict']);
        // The output names no codec, so the string is sent as it stands.
        self::assertSame('hello', $result['effects'][0]['outputs'][0]['payload']);
        self::assertSame('plugin', $result['effects'][0]['outputs'][0]['target']);
        // The CHANNEL's name, which the fixture sets with `named('out')`, not
        // the output's — it is what rides the frame as `channel` metadata.
        self::assertSame('out', $result['effects'][0]['outputs'][0]['plugin_channel']);
    }

    public function testARecordedChangeIsJournaledAndFoldedLive(): void
    {
        $result = $this->work('counter_relay', 0, [self::input('"a"'), self::input('"b"')]);

        self::assertCount(2, $result['effects']);
        self::assertSame(['{"push":"a"}'], $result['effects'][0]['changes']);
        self::assertSame(
            ['count' => 2, 'seen' => ['a', 'b']],
            $this->stateOf('counter_relay'),
        );
    }

    /**
     * An entry naming no codec reads JSON, so plain text fails its decode: the
     * item is reported unreadable, the entrance's failure rather than the
     * handler's, and the core parks it under the entry channel.
     */
    public function testPlainTextIntoAnEntryNamingNoCodecNeverReachesTheHandler(): void
    {
        Delivered::$values = [];
        $result = $this->work('json_entry_relay', 0, [self::input('{"a":1}'), self::input('hello')]);

        self::assertSame(
            ['ok', 'unreadable'],
            array_map(static fn (array $e): string => $e['verdict'], $result['effects']),
        );
        self::assertStringStartsWith('JsonException: ', $result['effects'][1]['message']);
        self::assertSame([['a' => 1]], Delivered::$values);
    }

    /**
     * An entry edge's transform runs at the entrance too, so its failure is
     * the entrance's; a handler throwing on a value that did decode stays a
     * handler failure, which is what the retry ladder is for.
     */
    public function testAnEntryTransformFailureIsUnreadableWhileAHandlerFailureIsRetryable(): void
    {
        $mapped = $this->work('flaky_entry_relay', 0, [self::input('poison')]);
        self::assertSame('unreadable', $mapped['effects'][0]['verdict']);
        self::assertStringContainsString('the mapping rejected it', $mapped['effects'][0]['message']);

        $thrown = $this->work('flaky_entry_relay', 0, [self::input('boom')]);
        self::assertSame('retryable', $thrown['effects'][0]['verdict']);
        self::assertStringContainsString('the handler rejected it', $thrown['effects'][0]['message']);
    }

    public function testANonRetryableErrorParksImmediately(): void
    {
        $this->registry->add(PoisonRelay::spec());
        $result = $this->work('poison_relay', 0, [self::input('"poison"')]);

        self::assertSame('non_retryable', $result['effects'][0]['verdict']);
        self::assertStringContainsString('poisoned', $result['effects'][0]['message']);
    }

    /** Items after a failure are re-handed by the core, so the batch stops. */
    public function testTheBatchStopsAtTheFailedItem(): void
    {
        $this->registry->add(PoisonRelay::spec());
        $result = $this->work('poison_relay', 0, [
            self::input('"ok"'),
            self::input('"poison"'),
            self::input('"never reached"'),
        ]);

        self::assertCount(2, $result['effects']);
        self::assertSame('ok', $result['effects'][0]['verdict']);
        self::assertSame('non_retryable', $result['effects'][1]['verdict']);
    }

    public function testATimerFiringReachesOnTimerWithNowEqualToItsSchedule(): void
    {
        $result = $this->work('counter_relay', 0, [
            ['kind' => 'timer', 'timer_kind' => 'processing_time', 'scheduled_at' => 5_000],
        ]);

        self::assertSame(5_000, $result['effects'][0]['event_time']);
        self::assertSame(['{"push":"timer:5000"}'], $result['effects'][0]['changes']);
    }

    public function testARestoreFoldRebuildsStateBeforeInputsFlow(): void
    {
        $restored = $this->dispatcher->answer([
            'token' => 1,
            'factory' => 'counter_relay',
            'tasklet' => 0,
            'relay' => 'op',
            'cell_key' => null,
            'change_seq' => 0,
            'kind' => 'restore_fold',
            'snapshot' => null,
            'changes' => [
                ['bytes' => '{"push":"x"}', 'from_version' => null],
                ['bytes' => '{"push":"y"}', 'from_version' => null],
            ],
        ]);

        self::assertSame('restored', $restored['outcome']);
        self::assertSame(['count' => 2, 'seen' => ['x', 'y']], $this->stateOf('counter_relay'));
    }

    public function testASnapshotRoundTripsThroughALaterRestore(): void
    {
        $this->work('counter_relay', 0, [self::input('"a"')]);
        $snapshot = $this->command('counter_relay', 'snapshot_request')['state'];

        $fresh = new Dispatcher($this->registry, self::core());
        $fresh->answer([
            'token' => 2,
            'factory' => 'counter_relay',
            'tasklet' => 0,
            'relay' => 'op',
            'cell_key' => null,
            'change_seq' => 0,
            'kind' => 'restore_fold',
            'snapshot' => ['bytes' => $snapshot, 'from_version' => null],
            'changes' => [],
        ]);

        self::assertSame(['count' => 1, 'seen' => ['a']], $this->stateOf('counter_relay'));
    }

    public function testALiveStateReadAnswersTheStateAsJson(): void
    {
        $this->work('counter_relay', 0, [self::input('"a"')]);

        $live = $this->command('counter_relay', 'live_state_read')['json'];

        self::assertSame('{"count":1,"seen":["a"]}', $live);
    }

    public function testAnAppliedStateMaterializesTheMigratedValue(): void
    {
        $applied = $this->dispatcher->answer([
            'token' => 3,
            'factory' => 'counter_relay',
            'tasklet' => 0,
            'relay' => 'op',
            'cell_key' => null,
            'change_seq' => 0,
            'kind' => 'apply_state',
            'state_json' => '{"count":9,"seen":["z"]}',
        ]);

        self::assertSame('state_applied', $applied['outcome']);
        self::assertSame(['count' => 9, 'seen' => ['z']], $this->stateOf('counter_relay'));
    }

    public function testThePrepareCrossingAnswersOneKeyPerPayload(): void
    {
        $prepared = $this->prepare(['"a:1"', '"b:2"']);

        self::assertSame([['keys' => ['a'], 'event_time' => null], ['keys' => ['b'], 'event_time' => null]], $prepared);
    }

    /** A filtered payload answers no key: the core never mints a cell. */
    public function testAFilteredPayloadAnswersNoKey(): void
    {
        self::assertSame([['keys' => []]], $this->prepare(['"drop"']));
    }

    /** The keys answer the keyed consumer, whatever an unkeyed consumer declared before it filters. */
    public function testThePrepareCrossingAnswersTheKeyedConsumersKeys(): void
    {
        $prepared = $this->prepare(['"a:1"', '"drop"'], 'split_keyed_relay');

        self::assertSame([['keys' => ['a'], 'event_time' => null], ['keys' => []]], $prepared);
    }

    /** One payload's refusal is isolated to that payload. */
    public function testAFailingPayloadDoesNotAffectTheOthers(): void
    {
        $prepared = $this->prepare(['"a:1"', 'not json', '"b:2"']);

        self::assertSame(['a'], $prepared[0]['keys']);
        self::assertArrayHasKey('error', $prepared[1]);
        self::assertSame(['b'], $prepared[2]['keys']);
    }

    /**
     * The SDK's half of a steps chain: a stage runs like any other
     * tasklet, and the driver's barrier is answered by the completion. The
     * driver itself — loop modes, suspension, rewind, the task journal that
     * makes a resumed chain skip committed stages — is the core's, reached
     * through step activations, so this SDK implements none of it.
     */
    public function testAStepsStageRunsAsAnOrdinaryTasklet(): void
    {
        $this->registry->add(SagaRelay::spec());
        $result = $this->work('saga_relay', 0, [self::input('"reserve"')]);

        self::assertSame('ok', $result['effects'][0]['verdict']);
        $emitted = json_decode($result['effects'][0]['outputs'][0]['payload'], true);
        self::assertSame('reserve', $emitted['step']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $emitted['receipt']);
    }

    /** An aborted chain compensates the stages that committed, in reverse. */
    public function testCompensationRunsOverTheRecordedForwardOutputs(): void
    {
        $this->registry->add(SagaRelay::spec());
        $result = $this->dispatcher->answer([
            'token' => 4,
            'factory' => 'saga_relay',
            'tasklet' => 0,
            'relay' => 'op',
            'cell_key' => null,
            'change_seq' => 0,
            'kind' => 'compensate_run',
            'outputs' => ['{"step":"reserve","receipt":"abc"}'],
        ]);

        self::assertSame('work', $result['outcome']);
        self::assertSame(
            '{"undo":"abc"}',
            $result['effects'][0]['outputs'][0]['payload'],
        );
    }

    /** A class with no compensate hook says so rather than silently doing nothing. */
    public function testCompensationWithoutAHookIsRefused(): void
    {
        $result = $this->dispatcher->answer([
            'token' => 5,
            'factory' => 'echo_relay',
            'tasklet' => 0,
            'relay' => 'op',
            'cell_key' => null,
            'change_seq' => 0,
            'kind' => 'compensate_run',
            'outputs' => [],
        ]);

        self::assertSame('failed', $result['outcome']);
        self::assertTrue($result['non_retryable']);
        self::assertStringContainsString('declares no compensate hook', $result['error']);
    }

    /**
     * A steps tasklet's `complete` rides its item's effect as JSON, the
     * latest call winning, and the rest of the item still runs: the driver
     * ends the instance only after the iteration commits.
     */
    public function testAStepsTaskletCompletionRidesItsEffect(): void
    {
        $effect = $this->work('completing_steps', 0, [self::input('{"order":7}')])['effects'][0];

        self::assertSame('ok', $effect['verdict']);
        self::assertSame('{"seeded":{"order":7}}', $effect['complete']);
        self::assertSame('{"order":7}', $effect['outputs'][0]['payload']);
        self::assertNull($this->work('echo_relay', 0, [self::input('"x"')])['effects'][0]['complete']);
    }

    /**
     * A flow tasklet's request is carried the same way, and the core ends the
     * instance once its Cells are idle; a string result rides as it stands, as
     * an output sends one.
     */
    public function testAnFlowTaskletCompletionIsCarriedTheSameWay(): void
    {
        $effect = $this->work('completing_flow', 0, [self::input('"bye"')])['effects'][0];

        self::assertSame('ok', $effect['verdict']);
        self::assertSame('bye', $effect['complete']);
    }

    /** A failed item carries no completion, so a failed iteration cannot end the instance. */
    public function testAnItemThatFailsAfterCompletingCarriesNoCompletion(): void
    {
        $effect = $this->work('completing_steps', 0, [self::input('"fail"')])['effects'][0];

        self::assertSame('retryable', $effect['verdict']);
        self::assertArrayNotHasKey('complete', $effect);
    }

    /** A steps tasklet's timer calls ride its item's effect exactly as a flow tasklet's do. */
    public function testABoundedItemCarriesItsTimerOpsAsAnFlowItemDoes(): void
    {
        $steps = $this->work('timing_steps', 0, [self::input('7')])['effects'][0];
        $stream = $this->work('timing_flow', 0, [self::input('7')])['effects'][0];

        self::assertSame('ok', $steps['verdict']);
        self::assertSame([
            ['kind' => 'processing_time', 'scheduled_at' => 2_000, 'register' => true],
            ['kind' => 'event_time', 'scheduled_at' => 6_000, 'register' => true],
            ['kind' => 'processing_time', 'scheduled_at' => 2_000, 'register' => false],
        ], $steps['timers']);
        self::assertSame($stream['timers'], $steps['timers']);
    }

    public function testEveryTimerCallOfACompensateContextIsRefusedAndArmsNothing(): void
    {
        $registered = $this->registry->get('timing_steps');
        self::assertNotNull($registered);
        $calls = [
            'timerAfter' => static fn (Ctx $ctx): int => $ctx->timerAfter(60_000),
            'timerAtEventTime' => static fn (Ctx $ctx): int => $ctx->timerAtEventTime(60_000),
            'cancelTimer' => static fn (Ctx $ctx) => $ctx->cancelTimer('processing_time', 60_000),
        ];
        foreach ($calls as $name => $call) {
            $refused = null;
            $ctx = Dispatcher::invoke(
                ['token' => 1, 'relay' => 'op', 'cell_key' => null, 'change_seq' => 0, 'kind' => 'compensate_run'],
                $registered,
                $registered->spec->tasklets[0],
                self::core(),
                0,
                false,
                static function (Ctx $ctx) use ($call, &$refused): void {
                    try {
                        $call($ctx);
                    } catch (FlezhaException $e) {
                        $refused = $e->getCode();
                    }
                },
            );

            self::assertSame('runtime.timers_unavailable', $refused, "$name was not refused");
            self::assertSame([], $ctx->timers, "$name armed a timer");
        }
    }

    public function testAnUnregisteredFactoryFailsNonRetryably(): void
    {
        $result = $this->work('never_declared', 0, [self::input('"x"')]);

        self::assertSame('failed', $result['outcome']);
        self::assertTrue($result['non_retryable']);
        self::assertStringContainsString('no class registered for factory', $result['error']);
    }

    /**
     * An activation that returns unanswered retires its cell for the life of
     * the process, so every path out of `dispatch` ends in a completion or an
     * abandon — and when both refuse, it says so rather than throwing into the
     * loop.
     */
    public function testAnUnanswerableActivationIsAbandonedAndReported(): void
    {
        $said = [];
        Logger::use(new class ($said) extends Logger {
            /** @param array<int, string> $said */
            public function __construct(public array &$said)
            {
                parent::__construct('debug');
            }

            protected function write(string $level, string $message): void
            {
                $this->said[] = "$level: $message";
            }
        });

        try {
            // The pinned work-activation fixture: a token the core never
            // handed out, so both `complete` and `abandon` refuse it.
            $this->dispatcher->dispatch((string) hex2bin(
                '2a046f702d310301016b0002000102aabb010a666c7a2e6f66667365740137010701e8070100d00f090166',
            ));
        } finally {
            Logger::use(new Logger('warn'));
        }

        self::assertStringContainsString('(runtime.unknown_activation_token)', implode("\n", $said));
        self::assertStringContainsString('abandoning activation 42 failed', implode("\n", $said));
    }

    // --- a keyed plugin output --------------------------------------------
    //
    // The core stamps a keyed output's identity at settle; the host hands it
    // the key and the user's metadata only.

    public function testAKeyedPluginOutputCarriesItsKeyAndTheUsersMetadataOnly(): void
    {
        $outputs = $this->mixed('"mixed"')['effects'][0]['outputs'];
        $keyed = array_values(array_filter(
            $outputs,
            static fn (array $o): bool => $o['target'] === 'plugin',
        ))[0];

        self::assertSame('keyed_out', $keyed['plugin_channel']);
        self::assertSame('ek', $keyed['key']);
        self::assertSame(['user' => 'meta'], $keyed['metadata']);
    }

    public function testAFlatMapFansTheConsumerEdgeAndNotThePluginFrame(): void
    {
        $outputs = $this->mixed('"fanned"')['effects'][0]['outputs'];

        // Two edge frames from the `flatMap`, and ONE plugin frame: the
        // serializer runs once per emit, from the value as emitted.
        self::assertSame(
            ['edge', 'edge', 'plugin'],
            array_map(static fn (array $o): string => $o['target'], $outputs),
        );
    }

    public function testASpoofedStampIsRefusedBeforeAnyOutput(): void
    {
        $effect = $this->mixed('"spoof"')['effects'][0];

        self::assertSame('retryable', $effect['verdict']);
        self::assertArrayNotHasKey('outputs', $effect);
    }

    public function testAStructuredStepsReleasesOneUntransformedEdgeOutput(): void
    {
        $outputs = $this->work('steps_emit', 0, [self::input('10')])['effects'][0]['outputs'];

        self::assertSame(
            [['edge', 1, null, '10'], ['plugin', null, 'handoff', '10']],
            array_map(
                static fn (array $o): array => [
                    $o['target'],
                    $o['consumer'],
                    $o['plugin_channel'],
                    $o['payload'],
                ],
                $outputs,
            ),
        );
        self::assertNull($outputs[0]['key']);
        self::assertNull($outputs[0]['event_time']);
    }

    public function testAStepsTerminalNothingConsumesHandsItsValueToTheCoreAsTheCarry(): void
    {
        $outputs = $this->work('steps_carry', 0, [self::input('3')])['effects'][0]['outputs'];

        self::assertSame(
            [['edge', 1, 0, '3']],
            array_map(
                static fn (array $o): array => [$o['target'], $o['channel'], $o['consumer'], $o['payload']],
                $outputs,
            ),
        );
    }

    /**
     * `Json::encode` hands back the finished string, so the frame carries it
     * as it stands. A host that layers nothing on its encoder satisfies the
     * edge-output rule by doing nothing, and this is what says so.
     */
    public function testAnEdgePayloadIsTheEncodersOwnString(): void
    {
        $outputs = $this->work('steps_emit_flow', 0, [self::input('10')])['effects'][0]['outputs'];

        self::assertCount(3, $outputs);
        foreach ($outputs as $index => $output) {
            $value = Json::decode($output['payload']);
            self::assertSame(Json::encode($value), $output['payload'], "output {$index}");
        }
    }

    public function testTheSameGraphOnAnFlowClassReleasesOneOutputPerConsumer(): void
    {
        $outputs = $this->work('steps_emit_flow', 0, [self::input('10')])['effects'][0]['outputs'];

        self::assertSame(
            [['edge', 1, '11'], ['edge', 2, '10'], ['plugin', null, '10']],
            array_map(
                static fn (array $o): array => [$o['target'], $o['consumer'], $o['payload']],
                $outputs,
            ),
        );
    }

    // The other end of the same two arms: what the CONSUMER is handed. A stream
    // producer already applied the edge, so delivery applies nothing; a
    // steps stage released the value raw, so delivery is the one
    // application.

    public function testAnFlowInterTaskEdgeAppliesItsMapExactlyOnce(): void
    {
        $produced = $this->work('steps_emit_flow', 0, [self::input('10')])['effects'][0]['outputs'];

        self::assertSame([11], $this->delivered('steps_emit_flow', 1, $produced[0]['payload']));
    }

    public function testABoundedConsumerAppliesItsOwnEdgeExactlyOnceAtDelivery(): void
    {
        $released = $this->work('steps_emit', 0, [self::input('10')])['effects'][0]['outputs'][0]['payload'];

        // One released value reaches BOTH children — the driver's fan-out —
        // and each evaluates its own edge here.
        self::assertSame([11], $this->delivered('steps_emit', 1, $released));
        self::assertSame([10], $this->delivered('steps_emit', 2, $released));
    }

    /**
     * What `tasklet` was handed when `payload` arrived on `channel`, the
     * handoff channel unless named.
     *
     * @return list<mixed>
     */
    private function delivered(
        string $factory,
        int $tasklet,
        string $payload,
        int $channel = 1,
        ?string $cellKey = null,
    ): array {
        Delivered::$values = [];
        $effect = $this->work($factory, $tasklet, [self::input($payload, channel: $channel)], $cellKey)['effects'][0];
        self::assertSame('ok', $effect['verdict']);

        return Delivered::$values;
    }

    // --- merges, the router and fanned keys ---------------------------------

    /** Two sources into one node: each channel's edge carries its own mapping, in arrival order. */
    public function testMergedSourcesReachTheTaskletEachUnderItsOwnMapping(): void
    {
        Delivered::$values = [];
        $result = $this->work('merge_relay', 0, [
            self::input('"A:1"'),
            self::input('"A:2"'),
            self::input('"A:3"', channel: 1),
        ], 'A');

        self::assertSame(['ok', 'ok', 'ok'], array_column($result['effects'], 'verdict'));
        self::assertSame([
            ['A', ['tick' => 'A:1', 'symbol' => 'A']],
            ['A', ['tick' => 'A:2', 'symbol' => 'A']],
            ['A', ['quote' => 'A:3', 'symbol' => 'A']],
        ], Delivered::$values);
    }

    /** The merge's key reads a field only the mapping sets. */
    public function testTheMergeKeyIsComputedOnTheMappedValue(): void
    {
        self::assertSame(
            [['keys' => ['B'], 'event_time' => null]],
            $this->prepare(['"B:1"'], 'merge_relay', channel: 1),
        );
    }

    public function testRouteLatestHandsAKeyTheDocumentOnItsOwnTraffic(): void
    {
        $result = $this->work('route_latest_relay', 0, [
            self::input('{"limit":5}'),
            self::input('{"symbol":"A"}', channel: 1),
        ]);

        self::assertSame([[], ['["A",{"limit":5}]']], array_map(
            static fn (array $effect): array => array_column($effect['outputs'], 'payload'),
            $result['effects'],
        ));
    }

    public function testAFanOfThreeRunsTheHandlerOncePerValueUnderOneInput(): void
    {
        Delivered::$values = [];
        $result = $this->work('fanned_relay', 0, [self::input('"a:1,a:2,a:3"', offset: 7)], 'a');

        self::assertCount(1, $result['effects']);
        self::assertSame(7, $result['effects'][0]['consumed_offset']);
        self::assertSame([['a', 'a:1'], ['a', 'a:2'], ['a', 'a:3']], Delivered::$values);
    }

    public function testAFanOfNoneIsADrop(): void
    {
        self::assertSame([], $this->delivered('fanned_relay', 0, '"none"', channel: 0, cellKey: 'a'));
        self::assertSame([['keys' => []]], $this->prepare(['"none"'], 'fanned_relay'));
    }

    public function testAFanFollowedByAKeyAnswersEveryKeyAtTheCrossing(): void
    {
        self::assertSame(
            [['keys' => ['a', 'b', 'c'], 'event_time' => null]],
            $this->prepare(['"a:1,b:2,c:3"'], 'fanned_relay'),
        );
    }

    /**
     * The core admits a fanned entry payload whole to every cell its values
     * keyed, so each cell keeps only its own: by the edge's key, or by a
     * static tasklet's `cellsFor`.
     *
     * @return iterable<string, array{int, int}>
     */
    public static function fannedAcrossCells(): iterable
    {
        yield 'a keyed edge' => [0, 0];
        yield 'a static key set' => [1, 1];
    }

    /** @dataProvider fannedAcrossCells */
    public function testEachCellAFanReachesRunsOnlyTheValuesRoutedToIt(int $tasklet, int $channel): void
    {
        foreach (['a' => [['a', 'a:1'], ['a', 'a:3']], 'b' => [['b', 'b:2']]] as $cell => $routed) {
            self::assertSame($routed, $this->delivered('fanned_relay', $tasklet, '"a:1,b:2,a:3"', $channel, $cell));
        }
    }

    /** @return array<string, mixed> */
    private function mixed(string $payload): array
    {
        return $this->work('mixed_stamp_relay', 1, [self::input($payload)], 'ek', 5);
    }

    /** @param list<array<string, mixed>> $items @return array<string, mixed> */
    private function work(
        string $factory,
        int $tasklet,
        array $items,
        ?string $cellKey = null,
        int $changeSeq = 0,
    ): array {
        return $this->dispatcher->answer([
            'token' => 1,
            'factory' => $factory,
            'tasklet' => $tasklet,
            'relay' => 'op',
            'cell_key' => $cellKey,
            'change_seq' => $changeSeq,
            'kind' => 'work',
            'items' => $items,
        ]);
    }

    /** @return array<string, mixed> */
    private function command(string $factory, string $kind): array
    {
        return $this->dispatcher->answer([
            'token' => 1,
            'factory' => $factory,
            'tasklet' => 0,
            'relay' => 'op',
            'cell_key' => null,
            'change_seq' => 0,
            'kind' => $kind,
        ]);
    }

    /** @param list<string> $payloads @return list<array<string, mixed>> */
    private function prepare(array $payloads, string $factory = 'keyed_relay', int $channel = 0): array
    {
        return $this->dispatcher->answer([
            'token' => 1,
            'factory' => $factory,
            'tasklet' => 0,
            'relay' => 'op',
            'cell_key' => null,
            'change_seq' => 0,
            'kind' => 'key_request',
            'key_request' => [
                'channel' => $channel,
                'payloads' => $payloads,
                'want_keys' => true,
                'want_event_time' => false,
            ],
        ])['prepared'];
    }

    private function stateOf(string $factory): mixed
    {
        $registered = $this->registry->get($factory);
        self::assertNotNull($registered);

        return $registered->stateOf($registered->spec->tasklets[0], 'op', null);
    }

    private static function core(): Core
    {
        return self::$core ?? self::fail('the shared core context was not created');
    }
}

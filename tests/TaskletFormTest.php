<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\Attribute\Relay;
use Flezha\Attribute\Task;
use Flezha\ChannelCodec;
use Flezha\Ctx;
use Flezha\Definition;
use Flezha\Exception\FlezhaException;
use Flezha\Ffi\Core;
use Flezha\Graph\Builder;
use Flezha\Graph\HostClassDecl;
use Flezha\Graph\Validate;
use Flezha\Tasklet;
use Flezha\Testing\Harness;
use PHPUnit\Framework\TestCase;

#[Task(cellKeys: ['a'])]
final class Nameless extends Tasklet
{
    public function cellsFor(mixed $input): array
    {
        return ['a'];
    }

    public function run(mixed $input, Ctx $ctx): void
    {
    }
}

final class NoTaskAttribute extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
    }
}

#[Task(name: '')]
final class EmptyName extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
    }
}

/** A totals tasklet, so two classes can carry one body under one name. */
trait SumsEvents
{
    public function initState(?string $cellKey): mixed
    {
        return ['sum' => 0, 'events' => []];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['sum'] += $change['amount'];
        $state['events'][] = $change['kind'];
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->change(['kind' => 'event', 'amount' => (int) $input]);
    }
}

#[Task(name: 'totals')]
final class TotalsBeforeRename extends Tasklet
{
    use SumsEvents;
}

#[Task(name: 'totals')]
final class TotalsAfterRename extends Tasklet
{
    use SumsEvents;
}

#[Task(name: 'sized', mailboxCapacity: 8, idleTtlSeconds: 60)]
class SizedParent extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
    }
}

#[Task(maxCells: 4)]
final class UnnamedSizedChild extends SizedParent
{
}

#[Task(name: 'sized_child')]
final class NamedSizedChild extends SizedParent
{
}

#[Task(name: 'sessions', idleTtlSeconds: 3_600)]
final class Sessions extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
    }
}

#[Task(name: 'stamped')]
final class Stamped extends Tasklet
{
    public function eventTime(mixed $input): int
    {
        return (int) $input['at'];
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit($ctx->now());
    }
}

#[Task(name: 'ledger', logicalClock: true)]
final class LogicalLedger extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return 0;
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state += $change;
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->change(1);
    }
}

#[Task(name: 'two_clocks', logicalClock: true)]
final class TwoClocks extends Tasklet
{
    public function eventTime(mixed $input): int
    {
        return 0;
    }

    public function run(mixed $input, Ctx $ctx): void
    {
    }
}

#[Task(name: 'stateless_logical', logicalClock: true)]
final class StatelessLogical extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
    }
}

#[Task(name: 'x', dynamicCells: true)]
final class RetiredDynamicCells extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
    }
}

#[Task(name: 'x', clock: 'event_time')]
final class RetiredClock extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
    }
}

#[Task(name: 'x', idleTtlMs: 1000)]
final class RetiredIdleTtlMs extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
    }
}

#[Task(name: 'emits_or_skips')]
final class EmitsOrSkips extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        if ($input === 'both') {
            $ctx->emit('a');
            $ctx->emit('b', key: 'k');
        }
        if ($input === 'elsewhere') {
            $ctx->emit('c', channel: 'elsewhere');
        }
    }
}

#[Task(name: 'seeded')]
final class Seeded extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['cell' => $cellKey];
    }

    public function run(mixed $input, Ctx $ctx): void
    {
    }
}

#[Task(name: 'routed', cellKeys: ['a', 'b'])]
final class Routed extends Tasklet
{
    public function cellsFor(mixed $input): array
    {
        return ['a'];
    }

    public function run(mixed $input, Ctx $ctx): void
    {
    }
}

#[Relay(factoryId: 'form_place', run: 'steps', output: 'placed', input: 'order', codec: ChannelCodec::Text)]
final class ShortPlaceOrder extends \Flezha\Relay
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit("placed:$input");
    }
}

#[Relay(factoryId: 'form_emits')]
final class EmitsRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(EmitsOrSkips::class)->output('out');
    }
}

#[Relay(factoryId: 'form_sessions')]
final class SessionsRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('orders')->keyBy(static fn (array $o): string => $o['customer'])->into(Sessions::class);
    }
}

#[Relay(factoryId: 'form_clocks')]
final class ClocksRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $in = $c->input('in');
        $in->into(Stamped::class)->output('stamps');
        $in->into(LogicalLedger::class);
        $in->into(Seeded::class);
    }
}

#[Relay(factoryId: 'form_stateless_logical')]
final class StatelessLogicalRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(StatelessLogical::class);
    }
}

#[Relay(factoryId: 'form_keyed_static')]
final class KeyedStaticRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->keyBy(static fn (mixed $v): string => 'a')->into(Routed::class);
    }
}

/**
 * The PHP tasklet form: a required name, `void` `run`, `initState`, whole
 * seconds for the idle TTL, the clock read from what the class writes, and the
 * cell mode read from the wiring.
 */
final class TaskletFormTest extends TestCase
{
    use CapturesTheLog;
    use Dispatches;

    /** @return iterable<string, array{class-string}> */
    public static function nameless(): iterable
    {
        yield 'a #[Task] with no name' => [Nameless::class];
        yield 'no #[Task] at all' => [NoTaskAttribute::class];
        yield 'an empty name' => [EmptyName::class];
        yield 'a subclass relying on its parent' => [UnnamedSizedChild::class];
    }

    /**
     * @dataProvider nameless
     * @param class-string $class
     */
    public function testATaskletWithoutANameIsRefusedNamingTheClass(string $class): void
    {
        $this->expectException(FlezhaException::class);
        $this->expectExceptionCode('topology.tasklet_name_missing');
        $this->expectExceptionMessage("tasklet class '$class' declares no name");

        Definition::of($class);
    }

    public function testASubclassWritingItsOwnNameKeepsItsParentsDeclarations(): void
    {
        $definition = Definition::of(NamedSizedChild::class);

        self::assertSame('sized_child', $definition->name);
        self::assertSame(8, $definition->decl->mailboxCapacity);
        self::assertSame(60, $definition->decl->idleTtlSeconds);
    }

    /**
     * The name, not the class, is the durable key: state journaled by one class
     * folds back under the other, and both declare one node.
     */
    public function testARenamedClassKeepsItsState(): void
    {
        $before = new Harness(TotalsBeforeRename::class);
        $before->feed(3);
        $before->feed(4);

        $after = new Harness(TotalsAfterRename::class);
        $after->restore(changes: $before->log);

        self::assertSame('totals', Definition::of(TotalsBeforeRename::class)->name);
        self::assertSame('totals', Definition::of(TotalsAfterRename::class)->name);
        self::assertSame(['sum' => 7, 'events' => ['event', 'event']], $after->state());
        self::assertSame($before->state(), $after->state());
    }

    /** A change is plain data: journaled as JSON, folded live, and folded again on restore. */
    public function testAChangeNeedsNoClass(): void
    {
        $harness = new Harness(TotalsBeforeRename::class);
        $outcome = $harness->feed(5);

        self::assertSame(['{"kind":"event","amount":5}'], $harness->log);
        self::assertSame([['kind' => 'event', 'amount' => 5]], $outcome->changes);
        self::assertTrue(Harness::replayEquals(TotalsBeforeRename::class, [5, 6]));
    }

    public function testTheIdleTtlIsWholeSeconds(): void
    {
        self::assertSame(3_600_000, self::record(SessionsRelay::class, 'sessions')['idle_ttl_ms']);
    }

    public function testTheClockFollowsFromWhatTheClassWrites(): void
    {
        self::assertSame('event_time', self::record(ClocksRelay::class, 'stamped')['clock']);
        self::assertSame('logical', self::record(ClocksRelay::class, 'ledger')['clock']);
        self::assertSame('wall', self::record(ClocksRelay::class, 'seeded')['clock']);
        self::assertArrayNotHasKey('idle_ttl_ms', self::record(ClocksRelay::class, 'seeded'));
    }

    /** The prepare crossing answers what `eventTime()` extracted, which the core serves as `now()`. */
    public function testAnEventTimeTaskletAnswersItsExtractedTime(): void
    {
        [$dispatcher, $core] = self::dispatcherFor(ClocksRelay::class);
        try {
            $prepared = $dispatcher->answer(self::activation('form_clocks', 'key_request') + [
                'key_request' => [
                    'channel' => 0, 'payloads' => ['{"at":4200}'],
                    'want_keys' => false, 'want_event_time' => true,
                ],
            ])['prepared'];
        } finally {
            $core->free();
        }

        self::assertSame(4_200, $prepared[0]['event_time']);
    }

    public function testEventTimeAndTheLogicalClockTogetherAreRefused(): void
    {
        $this->expectException(FlezhaException::class);
        $this->expectExceptionCode('topology.two_clocks');
        $this->expectExceptionMessage("tasklet 'two_clocks'");

        Definition::of(TwoClocks::class);
    }

    public function testALogicalClockOnAStatelessTaskletIsTheCoresUnservableClock(): void
    {
        try {
            self::validate(StatelessLogicalRelay::class);
            self::fail('a logical clock over no state is refused');
        } catch (FlezhaException $e) {
            self::assertSame(['topology.unservable_clock'], array_column($e->details['issues'], 'code'));
        }
    }

    public function testAStaticKeySetFedByKeyByIsTheCoresKeyByOnUnkeyableTarget(): void
    {
        try {
            self::validate(KeyedStaticRelay::class);
            self::fail('a keyed edge into a static key set is refused');
        } catch (FlezhaException $e) {
            self::assertSame(['topology.key_by_on_unkeyable_target'], array_column($e->details['issues'], 'code'));
            self::assertStringContainsString('routed', $e->getMessage());
        }
    }

    /** @return iterable<string, array{class-string, string}> */
    public static function retired(): iterable
    {
        yield 'dynamicCells' => [RetiredDynamicCells::class, 'dynamicCells'];
        yield 'clock' => [RetiredClock::class, 'clock'];
        yield 'idleTtlMs' => [RetiredIdleTtlMs::class, 'idleTtlMs'];
    }

    /**
     * @dataProvider retired
     * @param class-string $class
     */
    public function testARetiredTaskletArgumentIsRefusedNamingIt(string $class, string $argument): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Unknown named parameter \$$argument");

        Definition::of($class);
    }

    public function testAValueLeavesOnlyThroughTheContextUnderTheHarness(): void
    {
        $harness = new Harness(EmitsOrSkips::class);

        self::assertSame(['a', 'b'], $harness->feed('both')->outputs);
        self::assertSame([], $harness->feed('neither')->outputs);
    }

    public function testAValueLeavesOnlyThroughTheContextUnderTheDispatcher(): void
    {
        [$dispatcher, $core] = self::dispatcherFor(EmitsRelay::class);
        try {
            $result = $dispatcher->answer(self::activation('form_emits', 'work') + [
                'items' => [self::input('"both"'), self::input('"neither"')],
            ]);
        } finally {
            $core->free();
        }

        self::assertSame(['a', 'b'], array_column($result['effects'][0]['outputs'], 'payload'));
        self::assertSame([], $result['effects'][1]['outputs']);
    }

    /** `channel` picks among the tasklet's own outgoing channels, so one it does not produce fails the item. */
    public function testAnEmitOnAChannelTheTaskletDoesNotProduceFailsTheItem(): void
    {
        [$dispatcher, $core] = self::dispatcherFor(EmitsRelay::class);
        try {
            $result = $dispatcher->answer(self::activation('form_emits', 'work') + [
                'items' => [self::input('"elsewhere"')],
            ]);
        } finally {
            $core->free();
        }

        self::assertNotSame('ok', $result['effects'][0]['verdict']);
        self::assertStringContainsString('elsewhere', $result['effects'][0]['message']);
    }

    /** A steps short form's `run` emits as a tasklet's does, and the value reaches its output. */
    public function testABoundedShortFormsEmittedValueReachesItsOutput(): void
    {
        [$dispatcher, $core] = self::dispatcherFor(ShortPlaceOrder::class);
        try {
            $result = $dispatcher->answer(self::activation('form_place', 'work') + [
                'items' => [self::input('order-7')],
            ]);
        } finally {
            $core->free();
        }

        self::assertSame('placed:order-7', $result['effects'][0]['outputs'][0]['payload']);
        self::assertSame('plugin', $result['effects'][0]['outputs'][0]['target']);
    }

    public function testTheInitialStateComesFromInitState(): void
    {
        self::assertSame(['cell' => 'A'], (new Harness(Seeded::class, 'A'))->state());
        self::assertSame(['cell' => null], (new Harness(Seeded::class))->state());
    }

    /** @return iterable<string, array{string, string}> */
    public static function valueReturningRuns(): iterable
    {
        yield 'a run declared : mixed' => [
            'public function run(mixed $input, \Flezha\Ctx $ctx): mixed { return $input; }',
            'must be compatible with Flezha\Tasklet::run(mixed $input, Flezha\Ctx $ctx): void',
        ];
        yield 'a void run returning a value' => [
            'public function run(mixed $input, \Flezha\Ctx $ctx): void { return $input; }',
            'A void method must not return a value',
        ];
    }

    /**
     * PHP holds every `run` to `: void`, so a body that returns a value does not
     * load, and no SDK check is needed. Run in a child PHP, since the failure
     * is fatal.
     *
     * @dataProvider valueReturningRuns
     */
    public function testARunReturningAValueFailsToLoad(string $run, string $error): void
    {
        $script = sprintf(
            '<?php require %s; #[\Flezha\Attribute\Task(name: "r")] final class R extends \Flezha\Tasklet { %s }',
            var_export(dirname(__DIR__) . '/vendor/autoload.php', true),
            $run,
        );
        $file = tempnam(sys_get_temp_dir(), 'flz_run_');
        file_put_contents($file, $script);
        try {
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $output, $status);
        } finally {
            unlink($file);
        }

        self::assertNotSame(0, $status);
        self::assertStringContainsString($error, implode("\n", $output));
    }

    /**
     * @param class-string<\Flezha\Relay> $relay
     * @return array<string, mixed>
     */
    private static function record(string $relay, string $tasklet): array
    {
        foreach (HostClassDecl::of($relay::spec())['tasklets'] as $record) {
            if ($record['name'] === $tasklet) {
                return $record;
            }
        }

        self::fail("$relay declares no tasklet `$tasklet`");
    }

    /** @param class-string<\Flezha\Relay> $relay */
    private static function validate(string $relay): void
    {
        $core = Core::create();
        try {
            Validate::spec($relay::spec(), $core);
        } finally {
            $core->free();
        }
    }
}

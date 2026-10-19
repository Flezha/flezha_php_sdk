<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\Dispatch\Dispatcher;
use Flezha\Exception\FlezhaException;
use Flezha\Executor\Executors;
use Flezha\Executor\LocalLoopState;
use Flezha\Executor\SyncExecutor;
use Flezha\Ffi\Core;
use PHPUnit\Framework\TestCase;

/** The concurrency seam, and the budget and metrics every loop shares. */
final class ExecutorTest extends TestCase
{
    use Dispatches;

    protected function tearDown(): void
    {
        putenv(Executors::ENV);
    }

    public function testSyncIsTheDefault(): void
    {
        self::assertInstanceOf(SyncExecutor::class, Executors::fromEnvironment());

        putenv(Executors::ENV . '=');
        self::assertInstanceOf(SyncExecutor::class, Executors::fromEnvironment());

        putenv(Executors::ENV . '=sync');
        self::assertInstanceOf(SyncExecutor::class, Executors::fromEnvironment());
    }

    public function testAnUnknownExecutorNamesTheOnesThatExist(): void
    {
        putenv(Executors::ENV . '=fibers');

        $this->expectException(FlezhaException::class);
        $this->expectExceptionCode('usage.unknown_word');
        $this->expectExceptionMessage("'fibers'; use one of sync, swoole");

        Executors::fromEnvironment();
    }

    /**
     * The wrong build is an instruction, not `Class "Swoole\Thread" not found`
     * deep in the boot path — and it is refused before the runner connects.
     */
    public function testSwooleOnAWrongBuildNamesWhatIsMissing(): void
    {
        if (PHP_ZTS && class_exists(\Swoole\Thread::class)) {
            self::markTestSkipped('this build satisfies the threaded executor; see SwooleExecutorTest');
        }

        $this->expectException(FlezhaException::class);
        $this->expectExceptionCode('runtime.executor_unavailable');
        $this->expectExceptionMessageMatches('/thread-safe \(ZTS\) PHP build|Swoole\\\\Thread/');

        Executors::swoole();
    }

    /**
     * Every arm of the threaded executor's requirement check, on one machine.
     *
     * `ffi.enable` is PHP_INI_SYSTEM, so a satisfying build cannot be made
     * unsatisfying at runtime — which is why the check is a pure function of
     * what it reads rather than of what it looks up.
     *
     * @return iterable<string, array{bool, bool, string, string|null}>
     */
    public static function requirements(): iterable
    {
        yield 'a non-thread-safe build' => [false, true, '1', 'thread-safe (ZTS) PHP build'];
        yield 'no thread API' => [true, false, '1', '--enable-swoole-thread'];
        yield 'FFI restricted to preload' => [true, true, 'preload', 'needs ffi.enable=true'];
        yield 'FFI off' => [true, true, '', 'needs ffi.enable=true'];
        yield 'everything present' => [true, true, 'true', null];
        yield 'everything present, numerically' => [true, true, '1', null];
    }

    /** @dataProvider requirements */
    public function testTheThreadedExecutorNamesWhatABuildIsMissing(
        bool $zts,
        bool $hasThread,
        string $ffiEnable,
        ?string $expected,
    ): void {
        $refusal = \Flezha\Executor\SwooleExecutor::requirementRefusal($zts, $hasThread, $ffiEnable);

        if ($expected === null) {
            self::assertNull($refusal);

            return;
        }
        self::assertNotNull($refusal);
        self::assertStringContainsString($expected, $refusal);
    }

    /** `unregisterFactory` is the inverse of `registerFactory`. */
    public function testARemovedTypeIsNotDeclared(): void
    {
        $runner = \Flezha\Flezha::createRunner('test')
            ->registerFactory(EchoRelay::class)
            ->registerFactory(CounterRelay::class);

        self::assertSame(
            ['echo_relay'],
            self::declaredFactories($runner->unregisterFactory(CounterRelay::class)),
        );
        self::assertSame([], self::declaredFactories($runner->unregisterFactory(EchoRelay::class)));
    }

    /**
     * A class registered after start is declared to the core and served by
     * this thread's dispatcher; once removed, its next activation is refused
     * as unregistered.
     */
    public function testAFactoryChangedAfterStartIsServedThenRefused(): void
    {
        // A runner holding a core is a started one; standing a core in lets
        // the post-start path run with no controller.
        $core = Core::create();
        $runner = \Flezha\Flezha::createRunner('test');
        (new \ReflectionProperty(\Flezha\Runner::class, 'core'))->setValue($runner, $core);
        $registry = (new \ReflectionProperty(\Flezha\Runner::class, 'registry'))->getValue($runner);
        $dispatcher = new Dispatcher($registry, $core);
        $work = self::activation('echo_relay', 'work') + ['items' => [self::input('"hi"')]];
        try {
            $runner->registerFactory(EchoRelay::class);
            $served = $dispatcher->answer($work);
            self::assertSame(['work', 'ok'], [$served['outcome'], $served['effects'][0]['verdict']]);

            $runner->unregisterFactory(EchoRelay::class);
            $refused = $dispatcher->answer($work);
            self::assertSame(
                ['failed', 'no class registered for factory `echo_relay`', true],
                [$refused['outcome'], $refused['error'], $refused['non_retryable']],
            );
            self::assertSame([], self::declaredFactories($runner));
        } finally {
            $core->free();
        }
    }

    /**
     * Under the Swoole executor each thread built its own registry at start,
     * so a change after start would reach the calling thread only: it is
     * refused, naming the executor, and nothing is declared or removed.
     */
    public function testUnderTheSwooleExecutorAFactoryChangeAfterStartIsRefused(): void
    {
        $core = Core::create();
        $runner = \Flezha\Flezha::createRunner('test')->registerFactory(EchoRelay::class);
        // A started runner on a build without Swoole: the executor stands in
        // without the constructor that checks for one.
        $swoole = (new \ReflectionClass(\Flezha\Executor\SwooleExecutor::class))->newInstanceWithoutConstructor();
        $runner->executor($swoole);
        (new \ReflectionProperty(\Flezha\Runner::class, 'core'))->setValue($runner, $core);
        try {
            self::assertChangesRefused($runner);
        } finally {
            $core->free();
        }
    }

    /** A worker thread's runner, attached to the pool's context, refuses alike. */
    public function testOnASwooleWorkerThreadAFactoryChangeIsRefused(): void
    {
        $core = Core::create();
        $runner = \Flezha\Flezha::createRunner('test')->registerFactory(EchoRelay::class);
        (new \ReflectionProperty(\Flezha\Runner::class, 'core'))->setValue($runner, $core);
        \Flezha\Runner::attachInThread(0, new LocalLoopState());
        try {
            self::assertChangesRefused($runner);
        } finally {
            (new \ReflectionProperty(\Flezha\Runner::class, 'attachment'))->setValue(null, null);
            $core->free();
        }
    }

    private static function assertChangesRefused(\Flezha\Runner $runner): void
    {
        foreach (['registerFactory' => CounterRelay::class, 'unregisterFactory' => EchoRelay::class] as $call => $class) {
            try {
                $runner->{$call}($class);
                self::fail("{$call} after start was not refused");
            } catch (FlezhaException $e) {
                self::assertSame('runtime.executor_unavailable', $e->getCode());
                self::assertStringStartsWith('the swoole executor cannot run here: ', $e->getMessage());
                self::assertStringContainsString('only the calling thread', $e->getMessage());
            }
        }
        self::assertSame(['echo_relay'], self::declaredFactories($runner));
    }

    /** @return list<string> */
    private static function declaredFactories(\Flezha\Runner $runner): array
    {
        $classes = (new \ReflectionProperty(\Flezha\Runner::class, 'classes'))->getValue($runner);

        return array_map(static fn (string $class): string => $class::spec()->factoryId, $classes);
    }

    public function testAnUnlimitedBudgetNeverRefusesAClaim(): void
    {
        $state = new LocalLoopState();

        for ($i = 0; $i < 1_000; $i++) {
            self::assertTrue($state->claim());
        }
    }

    /**
     * The budget is what lets an external supervisor recycle a long-lived
     * process: the loop stops claiming, the runner shuts down cleanly, and the
     * activation it did not claim is handed back untouched.
     */
    public function testABudgetIsSpentExactlyOnce(): void
    {
        $state = new LocalLoopState(2);

        self::assertTrue($state->claim());
        self::assertTrue($state->claim());
        self::assertFalse($state->claim());
        self::assertFalse($state->claim());
    }

    public function testStoppingIsObservedByEveryLoop(): void
    {
        $state = new LocalLoopState();

        self::assertFalse($state->stopping());
        $state->stop();
        self::assertTrue($state->stopping());
    }

    /**
     * The sizing arithmetic is measured rather than assumed: throughput per
     * loop is roughly the inverse of body latency, so the runner reports the
     * mean it actually saw.
     */
    public function testTheReportIsTheMeanBodyTime(): void
    {
        $state = new LocalLoopState();

        self::assertSame([0, 0], $state->report());

        $state->observe(1_000_000);
        $state->observe(3_000_000);

        self::assertSame([2, 2_000_000], $state->report());
    }
}

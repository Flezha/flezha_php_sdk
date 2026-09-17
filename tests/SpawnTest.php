<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\Attribute\Operative;
use Flezha\Attribute\Proc;
use Flezha\ChannelCodec;
use Flezha\Codec\ActivationCodec;
use Flezha\Codec\Json;
use Flezha\Ctx;
use Flezha\Dispatch\Dispatcher;
use Flezha\Dispatch\RegisteredClass;
use Flezha\Dispatch\Registry;
use Flezha\Exception\FlezhaException;
use Flezha\Exception\SpawnAlreadyRunningException;
use Flezha\Exception\SpawnException;
use Flezha\Exception\SpawnFactoryUnknownException;
use Flezha\Exception\SpawnRefusedException;
use Flezha\Exception\SpawnTimeoutException;
use Flezha\Exception\SpawnUnavailableException;
use Flezha\Ffi\Binding;
use Flezha\Ffi\Core;
use Flezha\Graph\Builder;
use Flezha\Graph\HostClassDecl;
use Flezha\Graph\Validate;
use Flezha\Procedure;
use Flezha\SpawnBridge;
use Flezha\SpawnHandle;
use Flezha\SpawnOutcome;
use Flezha\SpawnResult;
use Flezha\StreamOperative;
use PHPUnit\Framework\TestCase;

/** Spawns once with no id and once with the id `w1`, reads both, then emits its input. */
#[Proc]
final class Spawner extends Procedure
{
    /** @var list<SpawnResult|SpawnException> what each read answered, in order */
    public static array $read = [];

    public function run(mixed $input, Ctx $ctx): mixed
    {
        $handles = [
            $ctx->spawnWorkflow('worker', (string) $input),
            $ctx->spawnWorkflow('worker', (string) $input, id: 'w1'),
        ];
        foreach ($handles as $handle) {
            try {
                self::$read[] = $handle->wait();
            } catch (SpawnException $e) {
                self::$read[] = $e;
            }
        }

        return $input;
    }
}

#[Operative(factoryId: 'spawner')]
final class SpawnerOperative extends StreamOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)->into(Spawner::class)->sink('out');
    }
}

/**
 * A controller that answers each read with the next scripted answer, null
 * meaning pending. As in the core, a resolved handle answers every later read
 * the same.
 */
final class ScriptedSpawns implements SpawnBridge
{
    /** @var list<array{int, string, string, string, string, bool}> */
    public array $requests = [];

    /** @var list<int> the timeout each read was given */
    public array $reads = [];

    /** @var array<int, string|null> */
    private array $resolved = [];

    /** @param list<string|null> $answers */
    public function __construct(private array $answers)
    {
    }

    public function spawnRequest(
        int $token,
        string $factoryId,
        string $id,
        string $payload,
        string $ifRunning,
        bool $replay,
    ): int {
        $this->requests[] = [$token, $factoryId, $id, $payload, $ifRunning, $replay];

        return count($this->requests);
    }

    public function spawnResult(int $handle, int $timeoutMs): ?string
    {
        $this->reads[] = $timeoutMs;

        return $this->resolved[$handle] ??= array_shift($this->answers);
    }
}

/**
 * A procedure's spawn: the call returns a handle at once, and only a read of
 * that handle reports what the controller decided.
 */
final class SpawnTest extends TestCase
{
    private const TOKEN = 7;

    public function testTheCallReturnsAHandleThatAnswersPendingUntilTheAnswerArrives(): void
    {
        $bridge = new ScriptedSpawns([null, '{"id":"worker-k7m2qzxw4pd3fn2a","outcome":"spawned"}']);
        $handle = self::spawn($bridge, static fn (Ctx $ctx): SpawnHandle => $ctx->spawnWorkflow('worker', 'p1'));

        self::assertSame(
            [[self::TOKEN, 'worker', '', '"p1"', 'deliver', false]],
            $bridge->requests,
            'no id asks the controller to generate one, from the activation the call ran under',
        );
        self::assertNull($handle->tryResult());
        $result = new SpawnResult('worker-k7m2qzxw4pd3fn2a', SpawnOutcome::Spawned);
        self::assertEquals($result, $handle->wait());
        self::assertEquals($result, $handle->tryResult(), 'a resolved handle answers every read the same');
        self::assertSame(0, $bridge->reads[0], 'the non-waiting read never waits');
        self::assertGreaterThan(0, $bridge->reads[1]);
        self::assertSame(0, $bridge->reads[2]);
        self::assertCount(3, $bridge->reads, 'every read asks the core');
    }

    public function testASpawnWithAnIdDeliversByDefaultAndAnswersThatId(): void
    {
        $bridge = new ScriptedSpawns(['{"id":"w1","outcome":"delivered"}']);
        $handle = self::spawn($bridge, static fn (Ctx $ctx): SpawnHandle => $ctx->spawnWorkflow('worker', 'p2', id: 'w1'));

        self::assertSame([[self::TOKEN, 'worker', 'w1', '"p2"', 'deliver', false]], $bridge->requests);
        self::assertEquals(new SpawnResult('w1', SpawnOutcome::Delivered), $handle->wait());
    }

    public function testIfRunningWithoutAnIdIsRefusedAtTheCallAndSendsNothing(): void
    {
        $bridge = new ScriptedSpawns([]);

        try {
            self::spawn($bridge, static fn (Ctx $ctx): SpawnHandle => $ctx->spawnWorkflow('worker', 'p', ifRunning: 'error'));
            self::fail('a spawn with ifRunning and no id was accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('ifRunning without an id', $e->getMessage());
        }
        self::assertSame([], $bridge->requests);
    }

    /** @return iterable<string, array{string, class-string<SpawnException>}> */
    public static function failures(): iterable
    {
        yield 'the error policy' => ['already_running', SpawnAlreadyRunningException::class];
        yield 'an unknown factory' => ['factory_unknown', SpawnFactoryUnknownException::class];
        yield 'a refusal' => ['refused', SpawnRefusedException::class];
        yield 'a timeout' => ['timeout', SpawnTimeoutException::class];
        yield 'no control plane' => ['unavailable', SpawnUnavailableException::class];
    }

    /**
     * @dataProvider failures
     *
     * @param class-string<SpawnException> $class
     */
    public function testAFailureIsThrownOnlyByAReadAsTheClassOfItsKind(string $error, string $class): void
    {
        $message = "the core's sentence for $error";
        $bridge = new ScriptedSpawns([Json::encode(['id' => 'w1', 'error' => $error, 'message' => $message])]);
        $handle = self::spawn(
            $bridge,
            static fn (Ctx $ctx): SpawnHandle => $ctx->spawnWorkflow('worker', 'p', id: 'w1', ifRunning: 'error'),
        );
        self::assertSame([[self::TOKEN, 'worker', 'w1', '"p"', 'error', false]], $bridge->requests);

        try {
            $handle->wait();
            self::fail("a read of a spawn that failed with $error answered a result");
        } catch (SpawnException $failure) {
            self::assertInstanceOf($class, $failure);
            self::assertSame('w1', $failure->id);
            self::assertSame($message, $failure->getMessage());
        }

        try {
            $handle->tryResult();
            self::fail('a later read of a failed spawn answered a result');
        } catch (SpawnException $again) {
            self::assertSame([$class, 'w1', $message], [$again::class, $again->id, $again->getMessage()]);
        }
    }

    /**
     * Through the real core, with no control plane: the procedure's run
     * completes and ships its emit, and the spawns report only through reads.
     */
    public function testWithNoControlPlaneTheRunCompletesAndEachReadThrowsUnavailable(): void
    {
        self::withActivation(static function (array $activation, Dispatcher $dispatcher): void {
            [$effect] = $dispatcher->answer($activation)['effects'];

            self::assertSame('ok', $effect['verdict']);
            self::assertSame(['x'], array_column($effect['outputs'], 'payload'));
        });

        self::assertCount(2, Spawner::$read);
        self::assertInstanceOf(SpawnUnavailableException::class, Spawner::$read[0]);
        self::assertSame('', Spawner::$read[0]->id);
        self::assertInstanceOf(SpawnUnavailableException::class, Spawner::$read[1]);
        self::assertSame('w1', Spawner::$read[1]->id);
    }

    /**
     * The same run replayed answers `skipped_on_replay` rather than
     * `unavailable`, so no request was attempted. The generated id exists only
     * in the live run's answer, so the unnamed spawn answers an empty one.
     */
    public function testAReplayedSpawnSendsNothingAndAnswersTheIdItWasGiven(): void
    {
        self::withActivation(static function (array $activation, Dispatcher $dispatcher): void {
            $activation['items'] = array_map(
                static fn (array $item): array => ['replay' => true] + $item,
                $activation['items'],
            );
            $dispatcher->answer($activation);
        });

        self::assertEquals(
            [new SpawnResult('', SpawnOutcome::SkippedOnReplay), new SpawnResult('w1', SpawnOutcome::SkippedOnReplay)],
            Spawner::$read,
        );
    }

    /**
     * Through the real core: every read inside the activation answers the same
     * failure, and a read once the activation has ended throws released.
     */
    public function testAResolvedHandleAnswersEveryReadTheSameUntilItsActivationEnds(): void
    {
        $handle = null;
        self::withActivation(
            static function (array $activation, Dispatcher $dispatcher, Core $core) use (&$handle): void {
                $handle = new SpawnHandle($core, $core->spawnRequest($activation['token'], 'worker', 'w1', '"p"', 'deliver', false));
                $reads = [];
                foreach ([$handle->tryResult(...), $handle->wait(...)] as $read) {
                    try {
                        $read();
                        self::fail('a spawn with no control plane answered a result');
                    } catch (SpawnUnavailableException $e) {
                        $reads[] = [$e->id, $e->getMessage()];
                    }
                }
                self::assertSame('w1', $reads[0][0]);
                self::assertSame($reads[0], $reads[1]);
            },
            static function () use (&$handle): void {
                try {
                    $handle->tryResult();
                    self::fail('a handle answered after its activation ended');
                } catch (FlezhaException $e) {
                    self::assertStringContainsString('was released', $e->getMessage());
                }
            },
        );
    }

    /** Run `$call` against a real `Ctx` over `$bridge`, the way the dispatcher runs one item. */
    private static function spawn(SpawnBridge $bridge, callable $call): SpawnHandle
    {
        $spec = SpawnerOperative::spec();
        $handle = null;
        Dispatcher::invoke(
            ['token' => self::TOKEN, 'operative' => 'op', 'entity_key' => null, 'change_seq' => 0],
            new RegisteredClass($spec),
            $spec->procedures[0],
            $bridge,
            0,
            false,
            static function (Ctx $ctx) use ($call, &$handle): void {
                $handle = $call($ctx);
            },
        );

        return $handle;
    }

    /**
     * Hand `$body` the work activation a local run of the real core polls for
     * the input `x` to `spawner`, while its token is outstanding, then call
     * `$afterwards` once that activation is abandoned.
     *
     * @param callable(array<string, mixed>, Dispatcher, Core): void $body
     * @param (callable(): void)|null                               $afterwards
     */
    private static function withActivation(callable $body, ?callable $afterwards = null): void
    {
        Spawner::$read = [];
        $core = Core::create();

        try {
            $spec = SpawnerOperative::spec();
            Validate::spec($spec, $core);
            $registry = new Registry();
            $registry->add($spec);
            $core->declareClass(Json::encode(HostClassDecl::of($spec)));
            $core->localStart($spec->factoryId, 'spawner-local');
            $core->localFeed('in', 'x');

            $bytes = null;
            self::assertSame(Binding::POLL_ACTIVATION, $core->poll(5_000, $bytes));
            $activation = ActivationCodec::decodeActivation((string) $bytes);
            self::assertSame('work', $activation['kind']);

            try {
                try {
                    $body($activation, new Dispatcher($registry, $core), $core);
                } finally {
                    $core->abandon($activation['token']);
                }
                if ($afterwards !== null) {
                    $afterwards();
                }
            } finally {
                $core->localStop();
            }
        } finally {
            $core->free();
        }
    }
}

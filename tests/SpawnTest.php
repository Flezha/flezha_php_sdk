<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\Attribute\Relay;
use Flezha\Attribute\Task;
use Flezha\ChannelCodec;
use Flezha\Codec\ActivationCodec;
use Flezha\Codec\Json;
use Flezha\Ctx;
use Flezha\Dispatch\Dispatcher;
use Flezha\Dispatch\RegisteredClass;
use Flezha\Dispatch\Registry;
use Flezha\Exception\FlezhaException;
use Flezha\Ffi\Binding;
use Flezha\Ffi\Core;
use Flezha\Graph\Builder;
use Flezha\Graph\HostClassDecl;
use Flezha\Graph\Validate;
use Flezha\Tasklet;
use Flezha\SpawnBridge;
use Flezha\SpawnHandle;
use Flezha\SpawnOutcome;
use Flezha\SpawnResult;
use PHPUnit\Framework\TestCase;

/** Spawns once with no id and once with the id `w1`, reads both, then emits its input. */
#[Task(name: 'spawner')]
final class Spawner extends Tasklet
{
    /** @var list<SpawnResult|FlezhaException> what each read answered, in order */
    public static array $read = [];

    public function run(mixed $input, Ctx $ctx): void
    {
        $handles = [
            $ctx->spawnSteps('worker', (string) $input),
            $ctx->spawnSteps('worker', (string) $input, id: 'w1'),
        ];
        foreach ($handles as $handle) {
            try {
                self::$read[] = $handle->wait();
            } catch (FlezhaException $e) {
                self::$read[] = $e;
            }
        }

        $ctx->emit($input);
    }
}

#[Relay(factoryId: 'spawner')]
final class SpawnerRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)->into(Spawner::class)->output('out');
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

    /** @var array<int, string|FlezhaException|null> */
    private array $resolved = [];

    /** @param list<string|FlezhaException|null> $answers a refused read throws its FlezhaException */
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
        ?int $offset,
    ): int {
        $this->requests[] = [$token, $factoryId, $id, $payload, $ifRunning, $replay, $offset];

        return count($this->requests);
    }

    public function spawnResult(int $handle, int $timeoutMs): ?string
    {
        $this->reads[] = $timeoutMs;
        $answer = $this->resolved[$handle] ??= array_shift($this->answers);

        return $answer instanceof FlezhaException ? throw $answer : $answer;
    }

    /** A spawn test sends no part. */
    public function part(int $token, int $item, string $payload): void
    {
        throw new \LogicException('a spawn test sent a part');
    }

    /** A spawn test keeps no effect. */
    public function effectBegin(int $token, int $item, string $name): array
    {
        throw new \LogicException('a spawn test began an effect');
    }

    public function effectEnd(int $token, int $item, string $payload): ?string
    {
        throw new \LogicException('a spawn test ended an effect');
    }

    public function effectRelease(int $token, int $item): void
    {
        throw new \LogicException('a spawn test released an effect');
    }
}

/**
 * A tasklet's spawn: the call returns a handle at once, and only a read of
 * that handle reports what the controller decided.
 */
final class SpawnTest extends TestCase
{
    private const TOKEN = 7;
    private const OFFSET = 11;

    public function testTheCallReturnsAHandleThatAnswersPendingUntilTheAnswerArrives(): void
    {
        $bridge = new ScriptedSpawns([null, '{"id":"worker-k7m2qzxw4pd3fn2a","outcome":"spawned"}']);
        $handle = self::spawn($bridge, static fn (Ctx $ctx): SpawnHandle => $ctx->spawnSteps('worker', 'p1'));

        self::assertSame(
            [[self::TOKEN, 'worker', '', 'p1', 'deliver', false, self::OFFSET]],
            $bridge->requests,
            'no id asks the controller to generate one, from the activation and input offset the call ran under',
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
        $handle = self::spawn($bridge, static fn (Ctx $ctx): SpawnHandle => $ctx->spawnSteps('worker', 'p2', id: 'w1'));

        self::assertSame([[self::TOKEN, 'worker', 'w1', 'p2', 'deliver', false, self::OFFSET]], $bridge->requests);
        self::assertEquals(new SpawnResult('w1', SpawnOutcome::Delivered), $handle->wait());
    }

    /**
     * The payload is the spawned instance's first message, sent as an output
     * naming no codec sends a value: a string as it stands, so a `Text` entry
     * reads it, and anything else as JSON, so a `Json` entry reads it.
     */
    public function testThePayloadIsWhatATextOrJsonEntryReads(): void
    {
        $bridge = new ScriptedSpawns(['{"id":"a","outcome":"spawned"}', '{"id":"b","outcome":"spawned"}']);
        self::spawn($bridge, static function (Ctx $ctx): SpawnHandle {
            $ctx->spawnSteps('worker', 'ticket-1');

            return $ctx->spawnSteps('worker', ['symbol' => 'AAPL', 'quantity' => 10]);
        });

        self::assertSame('ticket-1', $bridge->requests[0][3], 'a string travels as it stands');
        self::assertSame(['symbol' => 'AAPL', 'quantity' => 10], Json::decode($bridge->requests[1][3]));
    }

    public function testIfRunningWithoutAnIdIsRefusedAtTheCallAndSendsNothing(): void
    {
        $bridge = new ScriptedSpawns([]);

        try {
            self::spawn($bridge, static fn (Ctx $ctx): SpawnHandle => $ctx->spawnSteps('worker', 'p', ifRunning: 'error'));
            self::fail('a spawn with ifRunning and no id was accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('ifRunning without an id', $e->getMessage());
        }
        self::assertSame([], $bridge->requests);
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function failures(): iterable
    {
        yield 'the error policy' => ['relay.already_running', ['relay' => 'w1']];
        yield 'an unknown factory' => ['relay.factory_unknown', ['namespace' => 'default', 'factory' => 'worker']];
        yield 'a flow factory' => ['relay.factory_not_steps', ['factory' => 'worker', 'namespace' => 'default']];
        yield 'a refusal' => ['authz.no_role_binding', []];
        yield 'a timeout' => ['spawn.timeout', ['seconds' => 30]];
        yield 'no control plane' => ['spawn.unavailable', []];
    }

    /**
     * @dataProvider failures
     *
     * @param array<string, mixed> $details
     */
    public function testAFailureIsThrownOnlyByAReadAsTheOneErrorWithItsCode(string $code, array $details): void
    {
        $refusal = FlezhaException::fromDocument(Json::encode(['error' => [
            'code' => $code,
            'class' => 'invalid',
            'message' => "the core's sentence for $code",
            'details' => $details,
            'hint' => null,
        ]]));
        $bridge = new ScriptedSpawns([$refusal]);
        $handle = self::spawn(
            $bridge,
            static fn (Ctx $ctx): SpawnHandle => $ctx->spawnSteps('worker', 'p', id: 'w1', ifRunning: 'error'),
        );
        self::assertSame([[self::TOKEN, 'worker', 'w1', 'p', 'error', false, self::OFFSET]], $bridge->requests);

        try {
            $handle->wait();
            self::fail("a read of a spawn that failed with $code answered a result");
        } catch (FlezhaException $failure) {
            self::assertSame([$code, $details], [$failure->getCode(), $failure->details]);
        }

        try {
            $handle->tryResult();
            self::fail('a later read of a failed spawn answered a result');
        } catch (FlezhaException $again) {
            self::assertSame($code, $again->getCode());
        }
    }

    /**
     * Through the real core, with no control plane: the tasklet's run
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
        foreach (Spawner::$read as $read) {
            self::assertInstanceOf(FlezhaException::class, $read);
            self::assertSame(['spawn.unavailable', 'unavailable'], [$read->getCode(), $read->errorClass]);
        }
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
                $handle = new SpawnHandle($core, $core->spawnRequest(
                    $activation['token'],
                    'worker',
                    'w1',
                    '"p"',
                    'deliver',
                    false,
                    $activation['items'][0]['consumed_offset'] ?? null,
                ));
                $reads = [];
                foreach ([$handle->tryResult(...), $handle->wait(...)] as $read) {
                    try {
                        $read();
                        self::fail('a spawn with no control plane answered a result');
                    } catch (FlezhaException $e) {
                        $reads[] = [$e->getCode(), $e->getMessage()];
                    }
                }
                self::assertSame('spawn.unavailable', $reads[0][0]);
                self::assertSame($reads[0], $reads[1]);
            },
            static function () use (&$handle): void {
                try {
                    $handle->tryResult();
                    self::fail('a handle answered after its activation ended');
                } catch (FlezhaException $e) {
                    self::assertSame('runtime.spawn_handle_released', $e->getCode());
                }
            },
        );
    }

    /** Run `$call` against a real `Ctx` over `$bridge`, the way the dispatcher runs one item. */
    private static function spawn(SpawnBridge $bridge, callable $call): SpawnHandle
    {
        $spec = SpawnerRelay::spec();
        $handle = null;
        Dispatcher::invoke(
            ['token' => self::TOKEN, 'relay' => 'op', 'cell_key' => null, 'change_seq' => 0],
            new RegisteredClass($spec),
            $spec->tasklets[0],
            $bridge,
            0,
            false,
            static function (Ctx $ctx) use ($call, &$handle): void {
                $handle = $call($ctx);
            },
            self::OFFSET,
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
            $spec = SpawnerRelay::spec();
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

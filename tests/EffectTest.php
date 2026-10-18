<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\Attribute\Relay;
use Flezha\Attribute\Task;
use Flezha\Codec\ValueCodec;
use Flezha\Ctx;
use Flezha\Dispatch\Dispatcher;
use Flezha\Dispatch\RegisteredClass;
use Flezha\Exception\FlezhaException;
use Flezha\Graph\Builder;
use Flezha\LocalOutput;
use Flezha\LocalRelay;
use Flezha\Tasklet;
use Flezha\Testing\EffectReplayMismatch;
use Flezha\Testing\Harness;
use PHPUnit\Framework\TestCase;

/** How often each outside call ran, by name. */
final class EffectCalls
{
    /** @var array<string, int> */
    public static array $calls = [];

    /** @return array{answer: string, call: int} */
    public static function model(string $name): array
    {
        self::$calls[$name] = (self::$calls[$name] ?? 0) + 1;

        return ['answer' => $name, 'call' => self::$calls[$name]];
    }
}

#[Task(name: 'effect_asks')]
final class EffectAsks extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit($ctx->effect('model', static fn (): array => EffectCalls::model($input)));
    }
}

/** A codec: the result is kept as upper-case text and read back lower. */
final class UpperCodec implements ValueCodec
{
    public function encode(mixed $value): string
    {
        return strtoupper((string) $value);
    }

    public function decode(string $bytes): mixed
    {
        return strtolower($bytes);
    }
}

#[Task(name: 'effect_codec')]
final class EffectWithCodec extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit($ctx->effect('tool', static fn (): string => $input, new UpperCodec()));
    }
}

#[Task(name: 'effect_one_more')]
final class EffectOneMore extends Tasklet
{
    private int $calls = 0;

    public function run(mixed $input, Ctx $ctx): void
    {
        $this->calls++;
        for ($call = 0; $call < $this->calls; $call++) {
            $ctx->effect('model', static fn (): string => 'same');
        }
    }
}

#[Task(name: 'effect_around')]
final class EffectAround extends Tasklet
{
    private bool $live = true;

    public function run(mixed $input, Ctx $ctx): void
    {
        if ($this->live) {
            $this->live = false;
            $ctx->emit($ctx->effect('model', static fn (): array => EffectCalls::model('around')));
        } else {
            $ctx->emit(EffectCalls::model('around'));
        }
    }
}

#[Task(name: 'effect_bad_name')]
final class EffectBadName extends Tasklet
{
    public static string $name = '';

    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->effect(self::$name, static fn () => TestCase::fail('a refused call runs nothing'));
    }
}

#[Task(name: 'effect_nested')]
final class EffectNested extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->effect('outer', static fn (): mixed => $ctx->effect('inner', static fn (): int => 1));
    }
}

#[Task(name: 'effect_fails')]
final class EffectFails extends Tasklet
{
    private int $attempts = 0;

    public function run(mixed $input, Ctx $ctx): void
    {
        if (++$this->attempts === 1) {
            $ctx->effect('model', static fn () => throw new \RuntimeException('down'));
        }
        $ctx->emit($ctx->effect('model', static fn (): string => 'second'));
    }
}

#[Relay(factoryId: 'effect_local_php')]
final class EffectLocal extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: \Flezha\ChannelCodec::Text)->into(EffectAsks::class)
            ->output('out', codec: \Flezha\ChannelCodec::Json);
    }
}

/** A controller that kept another result for the call first. */
final class FirstKept extends RefusingSpawnBridge
{
    /** @var list<string> */
    public array $calls = [];

    public function effectBegin(int $token, int $item, string $name): array
    {
        $this->calls[] = "begin $token/$item/$name";

        return ['run', null];
    }

    public function effectEnd(int $token, int $item, string $payload): ?string
    {
        $this->calls[] = "end $payload";

        return '{"answer":"first"}';
    }

    public function effectRelease(int $token, int $item): void
    {
        $this->calls[] = 'release';
    }
}

/**
 * `Ctx::effect` (`recorded-effects`): a live input keeps an outside call's
 * result, a replay is served it without running the function, a replay that
 * reaches outside some other way fails naming the effect, and the refusals.
 */
final class EffectTest extends TestCase
{
    public function testALiveInputRunsTheFunctionAndAReplayIsServedItsResult(): void
    {
        $harness = new Harness(EffectAsks::class);
        $live = $harness->feed('live-q');
        $replayed = $harness->feed('live-q', replay: true);

        self::assertSame(1, EffectCalls::$calls['live-q'], 'the replay did not run the function');
        self::assertSame([['answer' => 'live-q', 'call' => 1]], $live->outputs);
        self::assertSame($live->outputs, $replayed->outputs);
    }

    public function testACodecEncodesAndDecodesTheResult(): void
    {
        self::assertSame(['hammer'], (new Harness(EffectWithCodec::class))->feed('Hammer')->outputs);
    }

    public function testAReplayThatRunsAFunctionFailsNamingTheEffect(): void
    {
        $harness = new Harness(EffectOneMore::class);
        $harness->feed('q');

        $this->expectException(EffectReplayMismatch::class);
        $this->expectExceptionMessage("effect 'model' ran its function");
        $harness->feed('q', replay: true);
    }

    public function testACallMadeAroundTheEffectOnReplayFailsNamingIt(): void
    {
        $harness = new Harness(EffectAround::class);
        $harness->feed('q');

        $this->expectException(EffectReplayMismatch::class);
        $this->expectExceptionMessage("effect 'model' was never asked for");
        $harness->feed('q', replay: true);
    }

    public function testABadNameIsRefusedWithoutARetry(): void
    {
        foreach (['', str_repeat('m', 256)] as $name) {
            EffectBadName::$name = $name;
            try {
                (new Harness(EffectBadName::class))->feed('q');
                self::fail('a bad name was not refused');
            } catch (FlezhaException $e) {
                self::assertSame('effect.bad_name', $e->getCode());
                self::assertTrue($e->isNonRetryable());
            }
        }
    }

    public function testAnEffectInsideAnEffectIsRefused(): void
    {
        try {
            (new Harness(EffectNested::class))->feed('q');
            self::fail('a nested effect was not refused');
        } catch (FlezhaException $e) {
            self::assertSame('effect.nested', $e->getCode());
        }
    }

    public function testAFailingFunctionKeepsNothingAndItsErrorPropagates(): void
    {
        $harness = new Harness(EffectFails::class);
        try {
            $harness->feed('q');
            self::fail('the function error did not propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('down', $e->getMessage());
        }

        self::assertSame(['second'], $harness->feed('q')->outputs, 'the failed call kept nothing');
    }

    public function testAResultTheControllerKeptFirstIsWhatTheCallReturns(): void
    {
        $bridge = new FirstKept();
        $spec = EffectLocal::spec();
        $returned = null;
        Dispatcher::invoke(
            ['token' => 9, 'relay' => 'op', 'cell_key' => null, 'change_seq' => 0],
            new RegisteredClass($spec),
            $spec->tasklets[0],
            $bridge,
            0,
            false,
            static function (Ctx $ctx) use (&$returned): void {
                $returned = $ctx->effect('model', static fn (): array => ['answer' => 'second']);
            },
            null,
            0,
        );

        self::assertSame(['answer' => 'first'], $returned);
        self::assertSame(['begin 9/0/model', 'end {"answer":"second"}', 'release'], $bridge->calls);
    }

    public function testLocalModeRunsTheFunctionEachTimeThroughTheCore(): void
    {
        $released = [];
        $run = LocalRelay::start(EffectLocal::class);
        try {
            $run->onOutput('out', static function (LocalOutput $out) use (&$released): void {
                $released[] = json_decode($out->payload, true);
            });
            $run->feed('in', 'local-q');
            $run->feed('in', 'local-q');
            $run->runUntilIdle(20_000);
        } finally {
            $run->close();
        }

        self::assertSame(2, EffectCalls::$calls['local-q'], 'nothing re-runs a local input, so nothing is kept');
        self::assertSame(
            [['answer' => 'local-q', 'call' => 1], ['answer' => 'local-q', 'call' => 2]],
            $released,
        );
    }
}

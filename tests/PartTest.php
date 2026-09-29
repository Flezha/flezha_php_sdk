<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\Attribute\Operator;
use Flezha\Attribute\Task;
use Flezha\ChannelCodec;
use Flezha\Ctx;
use Flezha\Dispatch\Dispatcher;
use Flezha\Dispatch\RegisteredClass;
use Flezha\Exception\FlezhaException;
use Flezha\Graph\Builder;
use Flezha\LocalOperator;
use Flezha\SinkOutput;
use Flezha\SpawnBridge;
use Flezha\Tasklet;
use Flezha\Testing\Harness;
use Flezha\TestApp\PartEcho;
use Flezha\UnboundedOperator;
use PHPUnit\Framework\TestCase;

#[Task(name: 'previewer')]
final class Previewer extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->part(['draft' => $input]);
        $ctx->part("$input!");
        $ctx->emit($input);
    }
}

#[Operator(factoryId: 'preview_op')]
final class PreviewOperator extends UnboundedOperator
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)->into(Previewer::class)
            ->sink('answer', encode: static fn (mixed $v): string => '<' . json_encode($v) . '>');
    }
}

/** Records every part refusal the no-sink tasklets met, then emits its input. */
#[Task(name: 'part_feeder')]
final class PartFeeder extends Tasklet
{
    /** @var list<string> */
    public static array $refusals = [];

    public function run(mixed $input, Ctx $ctx): void
    {
        try {
            $ctx->part($input);
        } catch (FlezhaException $e) {
            self::$refusals[] = (string) $e->getCode();
        }
        $ctx->emit($input);
    }
}

#[Task(name: 'part_relay')]
final class PartRelay extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit($input);
    }
}

#[Operator(factoryId: 'part_feeder_op')]
final class PartFeederOperator extends UnboundedOperator
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)->into(PartFeeder::class)
            ->into(PartRelay::class)->sink('answer');
    }
}

#[Operator(factoryId: 'part_answerer_op')]
final class PartAnswererOperator extends UnboundedOperator
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)->into(PartFeeder::class)->response();
    }
}

/**
 * A tasklet's part: the runtime hands the core the activation's token, the
 * index of the item the handler runs and the value encoded as the sink would
 * encode it, and a refusal throws inside the handler like any throw. The core
 * numbers, stamps and drops parts; its own tests and the fake controller cover
 * that side.
 */
final class PartTest extends TestCase
{
    private const BUDGET_MS = 20_000;

    public static function setUpBeforeClass(): void
    {
        TestApp::load('fixtures_parts.php');
    }

    public function testEachPartNamesItsTokenAndItemAndIsEncodedAsTheSinkEncodes(): void
    {
        $bridge = self::recording();
        self::invoke($bridge, 'a', item: 3);

        self::assertSame([[9, 3, '<{"draft":"a"}>'], [9, 3, '<"a!">']], $bridge->parts);
    }

    public function testARefusedPartThrowsOutOfTheHandler(): void
    {
        $bridge = self::recording(new FlezhaException('part.no_sink', ['tasklet' => 'previewer']));

        try {
            self::invoke($bridge, 'a', item: 0);
            self::fail('a refused part returned');
        } catch (FlezhaException $e) {
            self::assertSame('part.no_sink', $e->getCode());
        }
    }

    public function testTheTestingHarnessSendsAPartNowhere(): void
    {
        self::assertSame(['a'], (new Harness(Previewer::class))->feed('a')->outputs);
    }

    /** Through the real core: a local run has no controller, so no listener, and drops every part. */
    public function testALocalRunDropsThePartsAndReleasesTheOutput(): void
    {
        self::assertSame(['done:3', 'done:1'], self::released(PartEcho::class, ['3', '1']));
    }

    public function testAChannelWithoutASinkRefusesThePart(): void
    {
        PartFeeder::$refusals = [];
        self::assertSame(['x'], self::released(PartFeederOperator::class, ['x']));
        self::assertSame(['y'], self::released(PartAnswererOperator::class, ['y'], 'part_feeder'));

        self::assertSame(
            ['part.no_sink', 'part.no_sink'],
            PartFeeder::$refusals,
            'a channel feeding another tasklet and a response end preview nothing',
        );
    }

    /** Run `Previewer` on `$input` as the dispatcher runs item `$item` of activation 9. */
    private static function invoke(SpawnBridge $bridge, string $input, int $item): void
    {
        $spec = PreviewOperator::spec();
        Dispatcher::invoke(
            ['token' => 9, 'operator' => 'op', 'cell_key' => null, 'change_seq' => 0],
            new RegisteredClass($spec),
            $spec->tasklets[0],
            $bridge,
            0,
            false,
            static fn (Ctx $ctx) => $spec->tasklets[0]->definition->instance->run($input, $ctx),
            null,
            $item,
        );
    }

    /** A bridge that records each part, or throws `$refusal` for every one. */
    private static function recording(?FlezhaException $refusal = null): SpawnBridge
    {
        return new class ($refusal) implements SpawnBridge {
            /** @var list<array{int, int, string}> */
            public array $parts = [];

            public function __construct(private readonly ?FlezhaException $refusal)
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
                throw new \LogicException('a part test sent a spawn');
            }

            public function spawnResult(int $handle, int $timeoutMs): ?string
            {
                throw new \LogicException('a part test read a spawn');
            }

            public function part(int $token, int $item, string $payload): void
            {
                $this->parts[] = [$token, $item, $payload];
                if ($this->refusal !== null) {
                    throw $this->refusal;
                }
            }
        };
    }

    /**
     * What `$sink` released for `$inputs` fed on `in`.
     *
     * @param class-string<\Flezha\Operator> $class
     * @param list<string>                   $inputs
     * @return list<string>
     */
    private static function released(string $class, array $inputs, string $sink = 'answer'): array
    {
        $released = [];
        $run = LocalOperator::start($class);

        try {
            $run->onSink($sink, static function (SinkOutput $out) use (&$released): void {
                $released[] = $out->payload;
            });
            foreach ($inputs as $input) {
                $run->feed('in', $input);
            }
            $run->runUntilIdle(self::BUDGET_MS);
        } finally {
            $run->close();
        }

        return $released;
    }
}

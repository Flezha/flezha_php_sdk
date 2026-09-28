<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\Attribute\Operator;
use Flezha\Attribute\Task;
use Flezha\Ctx;
use Flezha\Dispatch\Dispatcher;
use Flezha\Graph\Builder;
use Flezha\Tasklet;
use Flezha\Testing\Harness;
use Flezha\UnboundedOperator;
use PHPUnit\Framework\TestCase;

/** Keeps what it was sent, and undoes by emitting what its own cell holds. */
#[Task(name: 'kept')]
final class Kept extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['seen' => []];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['seen'][] = $change;
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->change($input);
    }

    public function compensate(array $outputs, Ctx $ctx): void
    {
        $ctx->emit($ctx->state['seen']);
    }
}

#[Operator(factoryId: 'kept_operator')]
final class KeptOperator extends UnboundedOperator
{
    public static function channels(Builder $c): void
    {
        $c->input('input')->into(Kept::class)->sink('out');
    }
}

/**
 * A cell belongs to one operator: two operators of one factory on one runner,
 * such as two instances of a bounded operator, each keep their own state for
 * the same tasklet and cell key, on every path that reads or writes it.
 */
final class CellStateTest extends TestCase
{
    use Dispatches;

    public function testTwoOperatorsOfOneFactoryKeepTheirOwnCell(): void
    {
        [$dispatcher, $core] = self::dispatcherFor(KeptOperator::class);
        try {
            self::work($dispatcher, 'order_a', '"a"');
            self::work($dispatcher, 'order_b', '"b"');
            self::work($dispatcher, 'order_a', '"c"');

            self::assertSame('{"seen":["a","c"]}', self::read($dispatcher, 'order_a'));
            self::assertSame('{"seen":["b"]}', self::read($dispatcher, 'order_b'));
        } finally {
            $core->free();
        }
    }

    public function testARestoreAndAnAppliedStateReachOnlyTheirOwnOperator(): void
    {
        [$dispatcher, $core] = self::dispatcherFor(KeptOperator::class);
        try {
            $restored = $dispatcher->answer(self::activation('kept_operator', 'restore_fold', 'order_a') + [
                'snapshot' => null,
                'changes' => [['bytes' => '"r"', 'from_version' => null]],
            ]);
            $applied = $dispatcher->answer(self::activation('kept_operator', 'apply_state', 'order_b') + [
                'state_json' => '{"seen":["m"]}',
            ]);

            self::assertSame('restored', $restored['outcome']);
            self::assertSame('state_applied', $applied['outcome']);
            self::assertSame('{"seen":["r"]}', self::read($dispatcher, 'order_a'));
            self::assertSame('{"seen":["m"]}', self::read($dispatcher, 'order_b'));
            self::assertSame('{"seen":[]}', self::read($dispatcher, 'order_c'));
        } finally {
            $core->free();
        }
    }

    public function testACompensationReadsItsOwnOperatorsCell(): void
    {
        [$dispatcher, $core] = self::dispatcherFor(KeptOperator::class);
        try {
            self::work($dispatcher, 'order_a', '"a"');
            self::work($dispatcher, 'order_b', '"b"');

            $undo = $dispatcher->answer(
                self::activation('kept_operator', 'compensate_run', 'order_b') + ['outputs' => []],
            );

            self::assertSame('["b"]', $undo['effects'][0]['outputs'][0]['payload']);
        } finally {
            $core->free();
        }
    }

    public function testAHarnessRestoreFillsTheCellItReads(): void
    {
        $harness = new Harness(Kept::class, operator: 'order_a');
        $harness->restore(changes: ['"r"']);

        self::assertSame(['seen' => ['r']], $harness->state());
    }

    private static function work(Dispatcher $dispatcher, string $operator, string $payload): void
    {
        $answer = $dispatcher->answer(
            self::activation('kept_operator', 'work', $operator) + ['items' => [self::input($payload)]],
        );
        self::assertSame('ok', $answer['effects'][0]['verdict']);
    }

    private static function read(Dispatcher $dispatcher, string $operator): string
    {
        return $dispatcher->answer(self::activation('kept_operator', 'live_state_read', $operator))['json'];
    }
}

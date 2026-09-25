<?php

declare(strict_types=1);

/**
 * Durable state, and the one rule that goes with it: state is mutated only
 * through a recorded change, because the change log IS how the state is
 * rebuilt after a restart.
 *
 * `Harness::assertReplayEquals()` in your own suite is what makes that rule
 * safe — it catches a handler that mutated the state without recording a
 * change, and an `applyChange` that is not a pure fold.
 */

namespace Flezha\Examples\E02;

require_once __DIR__ . '/../vendor/autoload.php';

use Flezha\Attribute\Operator;
use Flezha\Attribute\Task;
use Flezha\Codable;
use Flezha\Ctx;
use Flezha\Flezha;
use Flezha\Graph\Builder;
use Flezha\Tasklet;
use Flezha\UnboundedOperator;

/** The durable bytes carry this array, never the class name — so a rename is safe. */
final class Totals implements Codable
{
    public function __construct(public int $count = 0, public int $amount = 0)
    {
    }

    public function toArray(): array
    {
        return ['count' => $this->count, 'amount' => $this->amount];
    }

    public static function fromArray(array $data): static
    {
        return new self((int) $data['count'], (int) $data['amount']);
    }
}

#[Task(dynamicCells: true, stateType: Totals::class)]
final class Counter extends Tasklet
{
    public function state(?string $cellKey): mixed
    {
        return new Totals();
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state->count++;
        $state->amount += (int) $change;
    }

    public function run(mixed $input, Ctx $ctx): mixed
    {
        $ctx->change((int) $input['amount']);

        return ['customer' => $ctx->cellKey, 'total' => $ctx->state->amount];
    }
}

#[Operator(factoryId: 'order_totals')]
final class OrderTotalsOperator extends UnboundedOperator
{
    public static function channels(Builder $c): void
    {
        $c->input('orders')
            ->keyBy(static fn (array $order): string => (string) $order['customer'])
            ->into(Counter::class)->keyed()
            ->sink('totals');
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    Flezha::createRunner($argv[1] ?? 'default')->registerFactory(OrderTotalsOperator::class)->run();
}

<?php

declare(strict_types=1);

/**
 * A structured bounded chain, and the compensation an abort runs.
 *
 * Each stage is a tasklet; the core's driver hands out one step activation
 * per stage and answers its barrier on completion. If a later stage fails, the
 * driver hands out `CompensateRun` for the stages that already committed, in
 * reverse — the SDK implements no driver of its own.
 */

namespace Flezha\Examples\E05;

require_once __DIR__ . '/../vendor/autoload.php';

use Flezha\Attribute\Operator;
use Flezha\Attribute\Task;
use Flezha\Attribute\Bounded;
use Flezha\Ctx;
use Flezha\Flezha;
use Flezha\Graph\Builder;
use Flezha\Tasklet;
use Flezha\RollbackMode;
use Flezha\BoundedOperator;

#[Task]
final class ReserveStock extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        return ['order' => $input['order'], 'reservation' => $ctx->stableId()];
    }

    public function compensate(array $outputs, Ctx $ctx): void
    {
        foreach ($outputs as $output) {
            $ctx->emit(['undo' => 'reservation', 'id' => $output['reservation']]);
        }
    }
}

#[Task]
final class ChargeCard extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        return ['order' => $input['order'], 'charge' => $ctx->stableId()];
    }

    public function compensate(array $outputs, Ctx $ctx): void
    {
        foreach ($outputs as $output) {
            $ctx->emit(['undo' => 'charge', 'id' => $output['charge']]);
        }
    }
}

#[Operator(factoryId: 'checkout')]
#[Bounded(rollbackMode: RollbackMode::AllOrNothing)]
final class CheckoutBounded extends BoundedOperator
{
    public static function channels(Builder $c): void
    {
        $c->input('orders')
            ->into(ReserveStock::class)->named('reserved')
            ->into(ChargeCard::class)
            ->sink('charged');
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    Flezha::createRunner($argv[1] ?? 'default')->registerFactory(CheckoutBounded::class)->run();
}

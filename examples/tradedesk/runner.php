<?php

declare(strict_types=1);

/**
 * Starts the desk's runner: the process that connects to the controller and serves tradedesk's
 * operators.
 *
 * The namespace is the first argument and defaults to `trading`. Under an enforced controller the
 * runner proves its namespace with that namespace's key, read from `FLEZHA_NAMESPACE_SECRET`.
 *
 * Registering a factory starts nothing: it offers the controller operators of that type, which are
 * started from it later. `registerFactory` is the same call for both kinds, the stream that tracks
 * prices and the steps operator that places one order.
 */

require __DIR__ . '/vendor/autoload.php';

use Flezha\Examples\Tradedesk\OrderExecutorOperator;
use Flezha\Examples\Tradedesk\TradeWatcherOperator;
use Flezha\Flezha;

$runner = Flezha::createRunner($argv[1] ?? 'trading')
    ->registerFactory(TradeWatcherOperator::class)
    ->registerFactory(OrderExecutorOperator::class)
    ->start();

$runner->awaitTermination();

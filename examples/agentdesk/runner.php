<?php

declare(strict_types=1);

/**
 * Starts the desk's runner: the process that connects to the controller and serves agentdesk's
 * operators, in the `agents` namespace.
 */

require __DIR__ . '/vendor/autoload.php';

use Flezha\Examples\Agentdesk\AgentOperator;
use Flezha\Examples\Agentdesk\GatewayOperator;
use Flezha\Flezha;

$runner = Flezha::createRunner('agents')
    ->registerFactory(AgentOperator::class)
    ->registerFactory(GatewayOperator::class)
    ->start();

$runner->awaitTermination();

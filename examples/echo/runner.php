<?php

declare(strict_types=1);

/**
 * Starts a runner: a process that connects to the controller and serves your
 * operators.
 *
 * Declare every operator you want served before `start()`. One runner can
 * serve several, and the same call takes a steps operator.
 */

require __DIR__ . '/vendor/autoload.php';

use Flezha\Examples\Echo\EchoOperator;
use Flezha\Flezha;

// `run()` is `start()` followed by `awaitTermination()`. Splitting them leaves
// room for the admin calls below, and still blocks until shutdown.
$runner = Flezha::createRunner($argv[1] ?? 'default')
    ->registerFactory(EchoOperator::class)
    ->start();

// Everything `flz operator` and `flz plugin` do is also a method here.
// Uncomment to drive this namespace from the application instead of from a
// terminal:
//
// $admin = $runner->admin();
// $admin->startOperator('echo', id: 'my_echo');
// $admin->enablePlugin('writer', [
//     'id' => 'writer',
//     'provider' => 'operator_file_sink',
//     'operator_id' => 'my_echo',
//     'opts' => ['path' => 'echo-out.txt'],
//     'hooks' => [['on_output', new stdClass()]],
// ]);
// $admin->send('my_echo', 'hello world', 'in');
//
// PHP has no threads: the message is processed once `awaitTermination()` below
// starts serving.
//
// docs/sdk/admin.md has the whole surface. An enforced controller refuses all
// of it until `flz role bind runner@default app default`.

$runner->awaitTermination();

<?php

declare(strict_types=1);

/**
 * The PHP SDK's e2e test app.
 *
 * Registers the reference fixture twins and runs until shutdown. The namespace
 * is $argv[1] (default "default"), matching rust_runner / java_runner /
 * node_runner / ruby_runner / elixir_runner. The executor is an axis of the
 * run rather than a property of this file: FLEZHA_PHP_EXECUTOR selects the
 * synchronous loop (the default) or the Swoole thread pool, and the fixtures
 * are identical under both.
 *
 * The script must be re-enterable: a `SwooleExecutor` worker thread bootstraps
 * it to rebuild the PHP-side registry, and `Runner::run()` attaches to the
 * pool's context rather than creating a second one.
 *
 * Which is also why it carries no `#!` line. A shebang is stripped only from
 * the script PHP was invoked with; a worker `require`s this file, where the
 * line is raw output before `<?php` and `declare(strict_types=1)` is then no
 * longer the first statement — a fatal error, in a fresh interpreter, that
 * kills every worker before it polls once.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/fixtures.php';
require __DIR__ . '/fixtures_state.php';
require __DIR__ . '/fixtures_probe.php';
require __DIR__ . '/fixtures_steps.php';
require __DIR__ . '/fixtures_steps_loop.php';
require __DIR__ . '/fixtures_parts.php';
require __DIR__ . '/fixtures_call.php';
require __DIR__ . '/conformance.php';

use Flezha\Exception\FlezhaException;
use Flezha\Flezha;
use Flezha\Logger;
use Flezha\TestApp\Conformance;
use Flezha\TestApp\Fixtures;
use Flezha\TestApp\StepsFixtures;
use Flezha\TestApp\PartFixtures;
use Flezha\TestApp\CallFixtures;

// The conformance run answers a file and exits: no namespace, no controller.
$report = getenv('FLEZHA_CONFORMANCE_REPORT');
if ($report !== false && $report !== '') {
    Conformance::writeReport($report);
    exit(0);
}

$namespace = $argv[1] ?? 'default';

/**
 * The call half of the local-send drill (`FLEZHA_LOCAL_SEND_SCRIPT`, entries
 * with `"call": true`; this SDK defers the local sends). The synchronous loop
 * runs nothing beside itself, so the calls are made by a forked process with
 * a runner of its own in the same namespace, which declares no factory and
 * claims no runner id of the app's. Each prints `call <relay> ok <answer JSON>`
 * or `call <relay> err <code>` once the file its `when` names exists.
 */
function callDrill(string $namespace, string $script): void
{
    $calls = array_filter(
        json_decode($script, true, 512, JSON_THROW_ON_ERROR),
        static fn (array $entry): bool => ($entry['call'] ?? false) === true,
    );
    // Cleared before any worker thread re-enters this file, so only one drill runs.
    putenv('FLEZHA_LOCAL_SEND_SCRIPT');
    if ($calls === [] || pcntl_fork() !== 0) {
        return;
    }
    putenv('FLEZHA_RUNNER_ID_PATH');
    $runner = null;
    foreach ($calls as $call) {
        for ($waited = 0; !file_exists($call['when']) && $waited < 2400; $waited++) {
            usleep(50_000);
        }
        // Connected only now, so the app's own runner is the one its test waited for.
        $runner ??= Flezha::createRunner($namespace)->start();
        try {
            $answer = $runner->call($call['relay'], $call['channel'], $call['value']);
            echo "call {$call['relay']} ok " . json_encode($answer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        } catch (FlezhaException $refusal) {
            echo "call {$call['relay']} err {$refusal->getCode()}\n";
        }
    }
    exit(0);
}

$drill = getenv('FLEZHA_LOCAL_SEND_SCRIPT');
if ($drill !== false && $drill !== '') {
    callDrill($namespace, $drill);
}

$runner = Flezha::createRunner($namespace)
    ->logger(new Logger((string) (getenv('FLEZHA_LOG_LEVEL') ?: 'info')));

try {
    $topology = getenv('FLEZHA_REFUSAL_FIXTURE');
    if ($topology !== false && $topology !== '') {
        // One row of the refusal table instead of the fixture set: a refused
        // topology dies at declaration, before the core is started.
        Conformance::registerOnly($runner, $topology);
    } else {
        foreach (Fixtures::all() as $relay) {
            $runner->registerFactory($relay);
        }
        foreach (StepsFixtures::all() as $relay) {
            $runner->registerFactory($relay);
        }
        foreach (PartFixtures::all() as $relay) {
            $runner->registerFactory($relay);
        }
        foreach ([...CallFixtures::all(), ...CallFixtures::api()] as $relay) {
            $runner->registerFactory($relay);
        }
    }

    // The configuration this run is under, before it starts: readiness is the
    // SDK's own startup line, which reports the executor.
    fwrite(STDERR, sprintf(
        "[php_runner] namespace=%s transport=%s executor=%s\n",
        $namespace,
        getenv('FLEZHA_TRANSPORT') ?: 'uds',
        getenv('FLEZHA_PHP_EXECUTOR') ?: 'sync',
    ));

    $runner->run();
} catch (FlezhaException $refusal) {
    $code = (string) $refusal->getCode();
    if (!str_starts_with($code, 'topology.')) {
        throw $refusal;
    }
    // A refused declaration names itself: its codes ride the error as data,
    // and the drill reads them off this process's output.
    fwrite(STDERR, sprintf(
        "[php_runner] declaration refused: %s\n%s\n",
        implode(', ', Conformance::codesOf($refusal)),
        $refusal->getMessage(),
    ));
    exit(1);
}

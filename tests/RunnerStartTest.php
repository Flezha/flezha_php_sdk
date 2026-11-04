<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\Exception\FlezhaException;
use PHPUnit\Framework\TestCase;

/** A runner's start against the core, with no controller to answer it. */
final class RunnerStartTest extends TestCase
{
    use CapturesTheLog;

    private const SETTINGS = ['FLEZHA_SOCKET_FILES_PATH', 'FLEZHA_RUNNER_START_WAIT_MS', 'FLEZHA_RUNNER_ID_PATH'];
    private const DIALLING = '/^(warn: control socket absent at |warn: Connection attempt |info: Control channel attempt )/';

    public function testAFailedStartWritesWhatTheCoreSaidWhileConnecting(): void
    {
        $dir = sys_get_temp_dir() . '/flz-php-start-' . getmypid();
        @mkdir($dir);
        putenv("FLEZHA_SOCKET_FILES_PATH={$dir}");
        putenv('FLEZHA_RUNNER_START_WAIT_MS=300');
        putenv("FLEZHA_RUNNER_ID_PATH={$dir}/runner.id");

        try {
            \Flezha\Flezha::createRunner('test')->registerFactory(EchoRelay::class)->start();
            self::fail('a start with no controller succeeded');
        } catch (FlezhaException $e) {
            self::assertSame('runtime.connect_unconfirmed', $e->getCode());
        } finally {
            foreach (self::SETTINGS as $setting) {
                putenv($setting);
            }
            @unlink("{$dir}/runner.id");
            @rmdir($dir);
        }

        $logged = $this->logged->getArrayCopy();
        self::assertNotEmpty(preg_grep('/^warn: Connection attempt 1 failed: /', $logged));
        // What the dial says is asserted; the capture's own check holds the rest.
        $this->logged->exchangeArray(array_values(preg_grep(self::DIALLING, $logged, PREG_GREP_INVERT)));
    }

    /**
     * The core installs its termination handler at the first start, and the
     * handler installed before it still runs. In a separate process, because a
     * start leaves that handler installed in the process that made it.
     */
    public function testSigtermAfterAStartIsTheCoresToDrain(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows has no SIGTERM');
        }
        $dir = sys_get_temp_dir() . '/flz-php-sigterm-' . getmypid();
        @mkdir($dir);
        // Two starts: an application may retry one that found no controller.
        $start = 'try { \Flezha\Flezha::createRunner("test")->registerFactory(\Flezha\Tests\EchoRelay::class)'
            . '->start(); } catch (\Flezha\Exception\FlezhaException) {}';
        $child = "require 'vendor/autoload.php'; {$start} {$start} echo \"started\\n\"; fgets(STDIN);";
        $environment = [
            ...getenv(),
            'FLEZHA_SOCKET_FILES_PATH' => $dir,
            'FLEZHA_RUNNER_START_WAIT_MS' => '300',
            'FLEZHA_RUNNER_ID_PATH' => "{$dir}/runner.id",
        ];
        $pipeSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([PHP_BINARY, '-r', $child], $pipeSpec, $pipes, dirname(__DIR__), $environment);
        self::assertIsResource($process);

        try {
            self::assertSame("started\n", fgets($pipes[1]));
            proc_terminate($process, 15);
            $status = self::awaitExit($process);
            $stderr = (string) stream_get_contents($pipes[2]);
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            array_map(fclose(...), $pipes);
            proc_close($process);
            @unlink("{$dir}/runner.id");
            @rmdir($dir);
        }

        $endedBy = "ended by signal {$status['termsig']} before the core drained:\n{$stderr}";
        self::assertFalse($status['signaled'], $endedBy);
        self::assertSame(0, $status['exitcode'], $stderr);
        self::assertStringContainsString("[flezha] termination signal received; draining\n", $stderr);
    }

    /**
     * @param resource $process
     *
     * @return array<string, mixed> the final `proc_get_status`
     */
    private static function awaitExit($process): array
    {
        $deadline = microtime(true) + 10;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return $status;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        self::fail('the runner was still running 10 s after SIGTERM');
    }
}

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
}

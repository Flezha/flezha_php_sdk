<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\Logger;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * The SDK's log held for the life of each test, as `<level>: <message>`. A
 * local run logs the core's local-mode banner; any other line a test provokes
 * is asserted and taken off `$logged` by that test, and the check after it
 * fails on whatever is left.
 */
trait CapturesTheLog
{
    /** @var \ArrayObject<int, string> */
    private \ArrayObject $logged;

    #[Before]
    protected function captureTheLog(): void
    {
        $this->logged = new \ArrayObject();
        Logger::use(new class ($this->logged) extends Logger {
            /** @param \ArrayObject<int, string> $lines */
            public function __construct(private readonly \ArrayObject $lines)
            {
                parent::__construct('debug');
            }

            protected function write(string $level, string $message): void
            {
                $this->lines[] = "$level: $message";
            }
        });
    }

    #[After]
    protected function onlyTheBannerWasLeft(): void
    {
        Logger::use(new Logger((string) (getenv('FLEZHA_LOG_LEVEL') ?: 'warn')));
        foreach ($this->logged as $line) {
            self::assertStringStartsWith('error: LOCAL MODE: ', $line);
        }
    }
}

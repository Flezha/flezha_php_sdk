<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\SpawnBridge;

/**
 * A controller a test does not expect to be asked: every call fails the test.
 * A fake extends it and answers only the calls its test makes.
 */
abstract class RefusingSpawnBridge implements SpawnBridge
{
    public function spawnRequest(
        int $token,
        string $factoryId,
        string $id,
        string $payload,
        string $ifRunning,
        bool $replay,
        ?int $offset,
    ): int {
        throw self::unexpected(__FUNCTION__);
    }

    public function spawnResult(int $handle, int $timeoutMs): ?string
    {
        throw self::unexpected(__FUNCTION__);
    }

    public function part(int $token, int $item, string $payload): void
    {
        throw self::unexpected(__FUNCTION__);
    }

    public function effectBegin(int $token, int $item, string $name): array
    {
        throw self::unexpected(__FUNCTION__);
    }

    public function effectEnd(int $token, int $item, string $payload): ?string
    {
        throw self::unexpected(__FUNCTION__);
    }

    public function effectRelease(int $token, int $item): void
    {
        throw self::unexpected(__FUNCTION__);
    }

    private static function unexpected(string $call): \LogicException
    {
        return new \LogicException("this test does not expect $call");
    }
}

<?php

declare(strict_types=1);

namespace Flezha;

/**
 * The one effect a `Ctx` cannot compute: asking the controller to spawn or
 * deliver to a workflow instance.
 *
 * An interface rather than the core itself, so the invocation context depends
 * on what it needs rather than on the FFI layer — which is also what lets the
 * test harness drive the real `Ctx` and the real dispatch path without a
 * controller behind them.
 */
interface SpawnBridge
{
    /**
     * Send one request from the activation `$token` and answer its handle
     * number at once. An empty `$id` asks the controller to generate one; a
     * replay sends nothing.
     */
    public function spawnRequest(
        int $token,
        string $factoryId,
        string $id,
        string $payload,
        string $ifRunning,
        bool $replay,
    ): int;

    /**
     * Read a handle, waiting up to `$timeoutMs`: null while no answer has
     * arrived, else the answer as JSON, `{"id","outcome"}` or
     * `{"id","error","message"}`.
     */
    public function spawnResult(int $handle, int $timeoutMs): ?string;
}

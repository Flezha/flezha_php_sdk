<?php

declare(strict_types=1);

namespace Flezha;

/**
 * The effects a `Ctx` cannot compute: asking the controller to spawn or
 * deliver to a steps instance, and sending a part.
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
     * replay sends nothing. `$offset` is the input-log offset of the item the
     * request runs under, if it has one.
     */
    public function spawnRequest(
        int $token,
        string $factoryId,
        string $id,
        string $payload,
        string $ifRunning,
        bool $replay,
        ?int $offset,
    ): int;

    /**
     * Read a handle, waiting up to `$timeoutMs`: null while no answer has
     * arrived, else the answer as JSON, `{"id","outcome"}`. A spawn that
     * reached no instance throws its {@see Exception\FlezhaException}.
     */
    public function spawnResult(int $handle, int $timeoutMs): ?string;

    /**
     * Send a part of the activation `$token`'s work item `$item` (its index
     * within the activation) and return at once. A part the core refuses
     * throws its {@see Exception\FlezhaException}.
     */
    public function part(int $token, int $item, string $payload): void;
}

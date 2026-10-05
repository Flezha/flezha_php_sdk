<?php

declare(strict_types=1);

namespace Flezha;

/**
 * The effects a `Ctx` cannot compute: asking the controller to spawn or
 * deliver to a steps instance, sending a part, and keeping an outside call's
 * result.
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

    /**
     * Begin `Ctx::effect($name)` in the activation `$token`'s work item
     * `$item`, and return at once: `['kept', $result]`, whose function does
     * not run, or `['run', null]` or `['unrecorded', null]`, whose function
     * runs. A refusal throws its {@see Exception\FlezhaException}.
     *
     * @return array{0: 'kept'|'run'|'unrecorded', 1: string|null}
     */
    public function effectBegin(int $token, int $item, string $name): array;

    /**
     * Keep the encoded result of the function a `run` begin ran, waiting for
     * the controller: null to return the call's own result, else the result
     * kept first. A refusal throws its {@see Exception\FlezhaException}.
     */
    public function effectEnd(int $token, int $item, string $payload): ?string;

    /** End a begun effect without keeping anything; a no-op when none is begun. */
    public function effectRelease(int $token, int $item): void;
}

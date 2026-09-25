<?php

declare(strict_types=1);

namespace Flezha;

use Flezha\Codec\Json;
use Flezha\Exception\SpawnAlreadyRunningException;
use Flezha\Exception\SpawnException;
use Flezha\Exception\SpawnFactoryNotBoundedException;
use Flezha\Exception\SpawnFactoryUnknownException;
use Flezha\Exception\SpawnRefusedException;
use Flezha\Exception\SpawnTimeoutException;
use Flezha\Exception\SpawnUnavailableException;

/**
 * The answer to one {@see Ctx::spawnBounded()} request.
 *
 * Read it inside the invocation that made it, where a resolved handle answers
 * every read the same: once that invocation completes the core releases the
 * handle, a failure nobody read is logged once instead, and a later read
 * throws. Reading is optional.
 */
final class SpawnHandle
{
    public function __construct(
        private readonly SpawnBridge $bridge,
        private readonly int $handle,
    ) {
    }

    /**
     * Wait for the controller's answer.
     *
     * @throws SpawnException the failure, as the class of its kind
     */
    public function wait(): SpawnResult
    {
        // Unbounded here because the core bounds it: at the request's deadline
        // it resolves a timeout.
        return $this->read(PHP_INT_MAX)
            ?? throw new \LogicException("spawn handle {$this->handle} answered pending to a read with no bound");
    }

    /**
     * The answer if it has arrived, or null while it is pending. Never waits.
     *
     * @throws SpawnException the failure, as the class of its kind
     */
    public function tryResult(): ?SpawnResult
    {
        return $this->read(0);
    }

    private function read(int $timeoutMs): ?SpawnResult
    {
        $answer = $this->bridge->spawnResult($this->handle, $timeoutMs);

        return $answer === null ? null : self::resolve(Json::decode($answer));
    }

    /**
     * @param array{id: string, outcome?: string, error?: string, message?: string} $answer
     *
     * @throws SpawnException the failure, as the class of its kind
     */
    private static function resolve(array $answer): SpawnResult
    {
        if (!isset($answer['error'])) {
            return new SpawnResult($answer['id'], SpawnOutcome::from((string) $answer['outcome']));
        }

        throw new (match ($answer['error']) {
            'already_running' => SpawnAlreadyRunningException::class,
            'factory_unknown' => SpawnFactoryUnknownException::class,
            'factory_not_bounded' => SpawnFactoryNotBoundedException::class,
            'refused' => SpawnRefusedException::class,
            'timeout' => SpawnTimeoutException::class,
            'unavailable' => SpawnUnavailableException::class,
        })($answer['id'], (string) $answer['message']);
    }
}

<?php

declare(strict_types=1);

namespace Flezha;

use Flezha\Codec\Json;
use Flezha\Exception\FlezhaException;

/**
 * The answer to one {@see Ctx::spawnSteps()} request.
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
     * @throws FlezhaException the failure, its code the controller's (such as
     *                         `operator.already_running`) or the core's
     *                         (`spawn.timeout`, `spawn.unavailable`)
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
     * @throws FlezhaException the failure, its code the controller's (such as
     *                         `operator.already_running`) or the core's
     *                         (`spawn.timeout`, `spawn.unavailable`)
     */
    public function tryResult(): ?SpawnResult
    {
        return $this->read(0);
    }

    private function read(int $timeoutMs): ?SpawnResult
    {
        $answer = $this->bridge->spawnResult($this->handle, $timeoutMs);
        if ($answer === null) {
            return null;
        }
        /** @var array{id: string, outcome: string} $fields */
        $fields = Json::decode($answer);

        return new SpawnResult($fields['id'], SpawnOutcome::from($fields['outcome']));
    }
}

<?php

declare(strict_types=1);

namespace Flezha\Exception;

/**
 * Why a spawn request reached no instance, thrown by a read of its handle and
 * never by the spawn call itself.
 */
abstract class SpawnException extends FlezhaException
{
    /** @param string $id the id the request named; empty when it named none */
    public function __construct(public readonly string $id, string $message)
    {
        parent::__construct($message);
    }
}

<?php

declare(strict_types=1);

namespace Flezha;

/**
 * A spawn that reached an instance: the id it resolved to, the caller's or the
 * one the controller generated, and how it got there. A replayed spawn without
 * an id answers an empty id, because the generated one exists only in the live
 * run's answer.
 */
final class SpawnResult
{
    public function __construct(
        public readonly string $id,
        public readonly SpawnOutcome $outcome,
    ) {
    }
}

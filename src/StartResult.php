<?php

declare(strict_types=1);

namespace Flezha;

/** What a start answered: the id the operator runs under, and what the start did. */
final class StartResult
{
    public function __construct(
        public readonly string $id,
        public readonly StartOutcome $outcome,
    ) {
    }
}

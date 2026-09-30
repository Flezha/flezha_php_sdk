<?php

declare(strict_types=1);

namespace Flezha\Graph;

/**
 * A channel's call end: the transforms and key of the chain that closed on
 * it, applied to a value before it becomes a request, and the request's
 * encoder.
 */
final class CallEnd
{
    /**
     * @param list<callable(mixed):mixed>   $transforms
     * @param (callable(mixed):string)|null $keyBy
     * @param callable(mixed):string        $encode
     */
    public function __construct(
        public readonly array $transforms,
        public readonly mixed $keyBy,
        public readonly mixed $encode,
    ) {
    }
}

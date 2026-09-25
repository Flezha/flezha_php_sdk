<?php

declare(strict_types=1);

namespace Flezha\Attribute;

use Attribute;
use Flezha\LoopMode;
use Flezha\RollbackMode;

/**
 * The bounded driver's settings for one class. Read only from a
 * {@see \Flezha\BoundedOperator}; an unbounded class that carries it is refused.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Bounded
{
    public function __construct(
        public readonly LoopMode $loopMode = LoopMode::Once,
        public readonly RollbackMode $rollbackMode = RollbackMode::AllOrNothing,
        public readonly ?int $maxDurationMs = null,
        public readonly bool $syncDurability = false,
    ) {
    }
}

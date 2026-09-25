<?php

declare(strict_types=1);

namespace Flezha\Attribute;

use Attribute;
use Flezha\Clock;

/**
 * Everything a tasklet declares that is not behaviour.
 *
 * One attribute rather than one per field: these are read together, once, at
 * registration, and a reader that has to find nine attributes to know what a
 * class declared is a reader that will miss one. Every argument is optional
 * and named, so a class declares only what it departs from.
 *
 *     #[Task(cellKeys: ['a', 'b'], clock: Clock::EventTime)]
 *     final class Totals extends Tasklet { ... }
 *
 * A §5 short form, an operator class defining `run`, carries its tasklet's
 * settings here too, all but `name`: that tasklet is named its factory id.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Task
{
    /**
     * @param list<string>|null    $cellKeys  a static key set; exclusive with $dynamicCells
     * @param class-string|null    $stateType   the class a cell's State is
     * @param class-string|null    $changeType  the class a recorded Change is
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?array $cellKeys = null,
        public readonly bool $dynamicCells = false,
        public readonly ?int $idleTtlMs = null,
        public readonly ?int $maxCells = null,
        public readonly Clock $clock = Clock::Wall,
        public readonly ?int $mailboxCapacity = null,
        public readonly int $schemaVersion = 1,
        public readonly ?int $minReadableSchemaVersion = null,
        public readonly ?string $stateType = null,
        public readonly ?string $changeType = null,
    ) {
    }
}

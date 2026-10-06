<?php

declare(strict_types=1);

namespace Flezha\Attribute;

use Attribute;

/**
 * Everything a tasklet declares that is not behaviour.
 *
 * One attribute rather than one per field: these are read together, once, at
 * registration, and a reader that has to find nine attributes to know what a
 * class declared is a reader that will miss one. Every argument is named,
 * and all but `name` are optional, so a class declares only what it departs
 * from. `name` is required because it is part of every cell's durable path:
 * renaming the class never moves saved state.
 *
 *     #[Task(name: 'totals', cellKeys: ['a', 'b'], logicalClock: true)]
 *     final class Totals extends Tasklet { ... }
 *
 * The cell mode is not declared here: a tasklet fed through `keyBy` is
 * dynamic, one with `cellKeys` static, and any other single.
 *
 * A §5 short form, a relay class defining `run`, carries its tasklet's
 * settings here too, all but `name`: that tasklet is named its factory id.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Task
{
    /**
     * @param list<string>|null    $cellKeys  a static key set
     * @param int|null             $idleTtlSeconds  evict an idle dynamic cell after this long; null never
     * @param bool                 $logicalClock  serve the cell's change sequence as `now()`
     * @param class-string|null    $stateType   the class a cell's State is
     * @param class-string|null    $changeType  the class a recorded Change is
     * @param class-string|null    $inputType   the class whose typed constructor-promoted
     *                                          properties give the JSON Schema of the entry
     *                                          channel feeding this tasklet, in the relay's API
     * @param class-string|null    $outputType  the same for the output this tasklet answers on
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?array $cellKeys = null,
        public readonly ?int $idleTtlSeconds = null,
        public readonly ?int $maxCells = null,
        public readonly bool $logicalClock = false,
        public readonly ?int $mailboxCapacity = null,
        public readonly int $schemaVersion = 1,
        public readonly ?int $minReadableSchemaVersion = null,
        public readonly ?string $stateType = null,
        public readonly ?string $changeType = null,
        public readonly ?string $inputType = null,
        public readonly ?string $outputType = null,
    ) {
    }
}

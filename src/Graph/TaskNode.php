<?php

declare(strict_types=1);

namespace Flezha\Graph;

use Flezha\Definition;

/** A tasklet as the graph holds it, with its position in the declaration. */
final class TaskNode
{
    /** @param class-string $class */
    public function __construct(
        public readonly int $index,
        public readonly string $name,
        public readonly string $class,
        public readonly Definition $definition,
        /** The one channel this node produces on, created on first wiring. */
        public ?ChannelSpec $output = null,
        /**
         * The steps operator class this node runs one stepped instance of per
         * edge key, holding no cells of its own; null for a tasklet.
         *
         * @var class-string<\Flezha\Operator>|null
         */
        public ?string $embeds = null,
        /** The embedded class's entry channel the edge feeds, when it declares several. */
        public ?string $input = null,
        /** The most stepped instances a keyed edge into the embedded class keeps at once. */
        public ?int $maxCells = null,
        /**
         * How a value on an edge into this node is written: the embedded
         * class's entry reads it, so its codec; null for a tasklet, which
         * reads the class codec.
         *
         * @var (callable(mixed):string)|null
         */
        public $encode = null,
    ) {
    }
}

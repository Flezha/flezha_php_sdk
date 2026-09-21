<?php

declare(strict_types=1);

namespace Flezha\Dispatch;

use Flezha\Graph\ChannelSpec;
use Flezha\Graph\ClassSpec;
use Flezha\Graph\TaskNode;

/**
 * A declared class, with the lookups dispatch needs on the hot path, and the
 * live state of every cell it owns.
 */
final class RegisteredClass
{
    /** @var array<int, list<ChannelSpec>> the channels each tasklet produces on */
    public readonly array $outgoing;

    /** @var array<string, mixed> */
    private array $cells = [];

    public function __construct(public readonly ClassSpec $spec)
    {
        $outgoing = [];
        foreach ($spec->tasklets as $node) {
            $outgoing[$node->index] = array_values(array_filter(
                $spec->channels,
                static fn (ChannelSpec $c): bool => $c->producer === $node->index,
            ));
        }
        $this->outgoing = $outgoing;
    }

    /**
     * The cell's live state, created from the declaration on first touch.
     *
     * No lock, and none is missing: a `Swoole\Thread` is a separate
     * interpreter with its own object graph, so two threads never share this
     * map — and per-cell exclusivity, which is the core's rather than this
     * map's, is what makes the cell a thread holds the only one in play
     * while it holds it.
     */
    public function stateOf(TaskNode $node, ?string $cellKey): mixed
    {
        $key = $node->index . "\0" . $cellKey;
        if (!array_key_exists($key, $this->cells)) {
            $this->cells[$key] = $node->definition->initialState($cellKey);
        }

        return $this->cells[$key];
    }

    public function setState(TaskNode $node, ?string $cellKey, mixed $state): void
    {
        $this->cells[$node->index . "\0" . $cellKey] = $state;
    }
}

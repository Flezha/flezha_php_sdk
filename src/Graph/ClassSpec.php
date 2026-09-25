<?php

declare(strict_types=1);

namespace Flezha\Graph;

use Flezha\Attribute\Bounded;
use Flezha\BeyondWindow;
use Flezha\CheckpointOutputs;

/** Everything the graph knows about one declared class. */
final class ClassSpec
{
    /**
     * @param list<TaskNode>    $tasklets
     * @param list<ChannelSpec> $channels
     */
    public function __construct(
        public readonly string $factoryId,
        /** `unbounded` | `bounded` */
        public readonly string $kind,
        public readonly bool $persistent,
        public readonly bool $effectivelyOnce,
        /** The author's explicit `checkpointAck()`; the granularity is derived. */
        public readonly bool $checkpointAck,
        public readonly ?int $checkpointEvery,
        public readonly CheckpointOutputs $checkpointOutputs,
        public readonly ?int $checkpointMaxDelayMs,
        public readonly ?int $correctionWindowMs,
        public readonly BeyondWindow $beyondWindowPolicy,
        public readonly ?int $compactAfterBytes,
        public readonly array $tasklets,
        public readonly array $channels,
        public readonly ?Bounded $bounded = null,
        public readonly bool $sleepWhenIdle = false,
    ) {
    }

    /**
     * Whether this class's producers release their values untransformed.
     *
     * A structured bounded operator's stage releases ONE value per emit and the driver
     * fans it out to every consumer itself, so each consumer edge — its
     * transforms and its key — belongs to the consumer. The producer and the
     * consumer read this one predicate, so they cannot disagree about which
     * side applies the edge.
     */
    public function structuredBounded(): bool
    {
        return $this->kind === 'bounded';
    }

    /**
     * Whether each invocation's output rides one effect record the controller
     * stamps at flush: under the effectively-once tier, and for a durable
     * bounded operator, whose stage commit names the record.
     */
    public function recordsEffects(): bool
    {
        return $this->effectivelyOnce || ($this->kind === 'bounded' && $this->persistent);
    }
}

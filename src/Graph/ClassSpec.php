<?php

declare(strict_types=1);

namespace Flezha\Graph;

/** Everything the graph knows about one declared class. */
final class ClassSpec
{
    /**
     * `$run` and `$durable` are the settings tree as written, `null` when
     * unwritten.
     *
     * @param string|array<string, mixed>|null      $run
     * @param bool|string|array<string, mixed>|null $durable
     * @param list<TaskNode>                        $tasklets
     * @param list<ChannelSpec>                     $channels
     */
    public function __construct(
        public readonly string $factoryId,
        public readonly ?string $description,
        public readonly ?int $maxDurationMs,
        public readonly string|array|null $run,
        public readonly bool|string|array|null $durable,
        public readonly ?int $compactAfterBytes,
        public readonly array $tasklets,
        public readonly array $channels,
    ) {
    }

    /**
     * Whether this class runs in steps, whose producers release their values
     * untransformed.
     *
     * A steps stage releases ONE value per emit and the driver fans it out to
     * every consumer itself, so each consumer edge (its transforms and its
     * key) belongs to the consumer. The producer and the consumer read this
     * one predicate, so they cannot disagree about which side applies the
     * edge.
     */
    public function runsInSteps(): bool
    {
        return self::branch($this->run) === 'steps';
    }

    /** Whether an instance keeps its work anywhere: `durable` is not `false`. */
    public function keepsWork(): bool
    {
        return self::branch($this->durable) !== 'false';
    }

    /**
     * Whether each invocation's output rides one effect record the controller
     * stamps at flush: under the effectively-once tier, and for a durable
     * steps relay, whose stage commit names the record.
     */
    public function recordsEffects(): bool
    {
        $flow = is_array($this->run) ? ($this->run['flow'] ?? null) : null;
        $exactly = is_array($flow) ? ($flow['exactly'] ?? null) : null;

        return self::branch($exactly) === 'effectively_once' || ($this->runsInSteps() && $this->keepsWork());
    }

    /** The branch a tree setting names: its bare name, or its one key. */
    private static function branch(mixed $tree): ?string
    {
        return match (true) {
            $tree === false => 'false',
            is_string($tree) => $tree,
            is_array($tree) && count($tree) === 1 => (string) array_key_first($tree),
            default => null,
        };
    }
}

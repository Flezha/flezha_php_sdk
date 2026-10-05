<?php

declare(strict_types=1);

namespace Flezha\Graph;

use Flezha\Relay;
use Flezha\Tasklet;

/**
 * Several channels merged into one tasklet, each lifted into the merged value
 * by its own mapping.
 *
 *     $c->merge()
 *         ->from($ticks, fn (array $t): array => ['symbol' => $t['s'], 'tick' => $t])
 *         ->from($quotes, fn (array $q): array => ['symbol' => $q['s'], 'quote' => $q])
 *         ->keyBy(fn (array $v): string => $v['symbol'])
 *         ->into(Detector::class);
 *
 * A mapping is an ordinary `map` on the source's own chain, so it runs where
 * every edge transform runs, live and on replay alike. Nothing is judged here:
 * a merge of one source or of one source twice is the core's refusal.
 */
final class Merge
{
    /** @var list<Edge> each source with its mapping already on its chain */
    private array $sources = [];

    /** @var list<callable(mixed):string> */
    private array $keys = [];

    public function __construct(private readonly Builder $builder)
    {
    }

    /**
     * One more source. The mapping composes after any transform declared on
     * the handle, so a key declared there is the transform-after-key refusal:
     * a merge is keyed once, on the merge.
     */
    public function from(Edge $source, callable $map): self
    {
        $this->sources[] = $source->map($map);

        return $this;
    }

    /** Route every source by a key computed on the merged value. */
    public function keyBy(callable $key): self
    {
        // Each declaration is one more `key` link per source, so a second one
        // reaches the core as the duplicate it is.
        $this->keys[] = $key;

        return $this;
    }

    /**
     * Close every source on one tasklet node, and answer that node's edge.
     *
     * @param class-string<Tasklet>|class-string<Relay> $class
     */
    public function into(string $class, ?string $nodeName = null): Edge
    {
        $node = $this->builder->nodeFor($class, $nodeName);
        $link = ['link' => 'merge', 'tasklet' => $node->index, 'group' => $this->builder->nextMergeGroup()];
        foreach ($this->sources as $source) {
            foreach ($this->keys as $key) {
                $source = $source->keyBy($key);
            }
            $source->wire($node, $link);
        }

        return new Edge($this->builder, ['tasklet', $node], []);
    }
}

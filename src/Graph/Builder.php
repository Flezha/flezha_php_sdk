<?php

declare(strict_types=1);

namespace Flezha\Graph;

use Flezha\ChannelCodec;
use Flezha\Definition;
use Flezha\Exception\FlezhaException;
use Flezha\Operator;
use Flezha\Overflow;
use Flezha\Tasklet;

/**
 * The fluent channel builder an operator's `channels()` receives.
 *
 * Compaction is a method on the builder rather than an attribute argument,
 * because it is what the graph is being declared with: an author writes it in
 * the same block as the wiring it qualifies.
 */
final class Builder
{
    /** @var list<TaskNode> */
    public array $tasklets = [];
    /** @var list<ChannelSpec> */
    public array $channels = [];
    /**
     * The links each fluent sequence off a channel handle was declared with,
     * in declaration order — the record's `chains`, recorded and never judged.
     *
     * Every `map` / `filter` / `flatMap` / `keyBy` / `named` answers a new
     * sequence over the previous, which is what lets a shared prefix branch
     * without the arms colliding; `superseded` marks the prefix a later link
     * built on, so only a tip reaches the record.
     *
     * @var list<array{source: array{string, ChannelSpec|TaskNode}, links: list<array<string, mixed>>, superseded: bool, edge: ?ConsumerEdge}>
     */
    private array $chains = [];

    private bool $sealed = false;

    private int $mergeGroups = 0;

    public ?int $compactAfterBytes = null;

    /**
     * The flow classes being inlined, the innermost last: the edge that feeds
     * each, the prefix its nodes carry and the entry channels it declared.
     *
     * @var list<array{prefix: string, feed: Edge, entries: list<string>}>
     */
    private array $inlining = [];

    public function __construct(public readonly string $factoryId)
    {
    }

    public function compactAfterBytes(int $bytes): self
    {
        $this->compactAfterBytes = $bytes;

        return $this;
    }

    /**
     * Declare a controller-fed entry channel. `$decode` turns the wire bytes
     * into the value a handler sees, `$codec` names a format instead, and with
     * neither the bytes are JSON.
     *
     * @param (callable(string):mixed)|null $decode
     */
    public function input(string $name, ?callable $decode = null, ?ChannelCodec $codec = null): Edge
    {
        // Inside a flow class being inlined, the entry is the edge that feeds it.
        $inline = array_key_last($this->inlining);
        if ($inline !== null) {
            $this->inlining[$inline]['entries'][] = $name;

            return $this->inlining[$inline]['feed'];
        }
        $channel = new ChannelSpec(index: count($this->channels), name: $name, kind: 'entry');
        $this->refuseCodecBesideCallable($channel, $codec, $decode);
        $channel->decode = $decode ?? ($codec ?? ChannelCodec::Json)->decoder($channel);
        $channel->entryEncode = ($codec ?? ChannelCodec::Json)->encoder($channel);
        $this->channels[] = $channel;

        return new Edge($this, ['entry', $channel], []);
    }

    /** Merge several channels into one tasklet; see {@see Merge}. */
    public function merge(): Merge
    {
        return new Merge($this);
    }

    /**
     * The group id one closed merge ties its sources together with, across the
     * channels they are declared on.
     *
     * @internal
     */
    public function nextMergeGroup(): int
    {
        return $this->mergeGroups++;
    }

    /**
     * Turn an operator-wide document stream into per-key deliveries: every key
     * seen on `$traffic` receives each distinct document once, on that key's
     * own next traffic item, as a `[$key, $document]` pair.
     *
     * Delivering on the key's own traffic, never over a remembered key set, is
     * what keeps every emitted key one this member owns after a rescale. The
     * price is one tick of latency per key, and a key with no traffic receives
     * nothing. A document whose JSON is identical to the retained one is inert.
     *
     * The node is `route_latest` like any tasklet node, so a second router in
     * one operator needs {@see routeLatestNamed()}.
     *
     * @param callable(mixed):string $keyFn
     */
    public function routeLatest(Edge $documents, Edge $traffic, callable $keyFn): Edge
    {
        return $this->wireRouteLatest($documents, $traffic, $keyFn, null);
    }

    /** {@see routeLatest()} under an explicit node identity. */
    public function routeLatestNamed(Edge $documents, Edge $traffic, callable $keyFn, string $nodeName): Edge
    {
        return $this->wireRouteLatest($documents, $traffic, $keyFn, $nodeName);
    }

    /**
     * The expansion both spellings share: the key function is a transform on
     * the traffic edge, so the router holds keys rather than traffic values,
     * and the two edges are one merge into the packaged tasklet.
     */
    private function wireRouteLatest(Edge $documents, Edge $traffic, callable $keyFn, ?string $nodeName): Edge
    {
        return $this->merge()
            ->from($documents, LatestDocumentRouter::document(...))
            ->from($traffic->map($keyFn), LatestDocumentRouter::traffic(...))
            ->into(LatestDocumentRouter::class, $nodeName);
    }

    /** A codec named beside the channel's own decoder or encoder is refused. */
    public function refuseCodecBesideCallable(ChannelSpec $channel, ?ChannelCodec $codec, ?callable $callable): void
    {
        if ($codec !== null && $callable !== null) {
            throw new FlezhaException('topology.codec_with_callable', ['channel' => $channel->name]);
        }
    }

    /**
     * The node for a tasklet. Two unnamed mentions of one tasklet are one
     * node — the naming convention every SDK mirrors — so a diamond that fans
     * out and back in has one instance, not two.
     *
     * @param class-string<Tasklet>|class-string<Operator> $class
     */
    public function nodeFor(string $class, ?string $name = null): TaskNode
    {
        $definition = Definition::of($class);
        $identity = $this->prefixed($name ?? $definition->name);

        foreach ($this->tasklets as $node) {
            if ($node->name !== $identity) {
                continue;
            }
            if ($node->class === $class) {
                return $node;
            }
            // The node table is keyed by identity and physically cannot hold
            // two, so the record the core would judge is unwritable. The
            // refusal answers under the core's own code all the same.
            throw new FlezhaException('topology.node_identity_collision', ['name' => $identity]);
        }

        $node = new TaskNode(count($this->tasklets), $identity, $class, $definition);
        $this->tasklets[] = $node;

        return $node;
    }

    /**
     * `$feed` into an operator class: a steps class becomes a node that runs
     * one stepped instance of it per key of the edge, its exit output
     * continuing on the edge answered; a flow class is inlined.
     *
     * @internal
     * @param class-string<Operator> $operator
     */
    public function embed(Edge $feed, string $operator, ?string $name, ?string $input, ?int $maxCells): Edge
    {
        if (!$operator::spec()->runsInSteps()) {
            return $this->inline($feed, $operator, $name, $input);
        }
        $node = $this->nodeFor(EmbeddingNode::class, $name ?? $operator::declaration()->factoryId);
        if ($node->embeds !== null && $node->embeds !== $operator) {
            throw new FlezhaException('topology.node_identity_collision', ['name' => $node->name]);
        }
        $node->embeds = $operator;
        $node->input = $input;
        $node->maxCells = $maxCells;
        $entries = array_values(array_filter(
            $operator::spec()->channels,
            static fn (ChannelSpec $channel): bool => $channel->kind === 'entry',
        ));
        $named = array_values(array_filter($entries, static fn (ChannelSpec $channel): bool => $channel->name === $input));
        $node->encode = ($named[0] ?? $entries[0] ?? null)?->entryEncode;

        return $feed->wire($node, ['link' => 'into', 'tasklet' => $node->index]);
    }

    /**
     * Run a flow class's `channels()` on this builder: its entry is `$feed`
     * and every node it names carries its prefix. The edge answered is its
     * exit's, the one node it added that nothing consumes (its last when
     * every node feeds another or a sink).
     *
     * @param class-string<Operator> $operator
     */
    private function inline(Edge $feed, string $operator, ?string $name, ?string $input): Edge
    {
        $factoryId = $operator::declaration()->factoryId;
        $first = count($this->tasklets);
        $this->inlining[] = ['prefix' => $this->prefixed($name ?? $factoryId), 'feed' => $feed, 'entries' => []];
        try {
            $operator::channels($this);
        } finally {
            $frame = array_pop($this->inlining);
        }
        $entries = array_values(array_unique($frame['entries']));
        sort($entries);
        if ($input === null ? count($entries) !== 1 : $entries !== [$input]) {
            throw new FlezhaException('topology.embedded_entry_ambiguous', [
                'inner' => $factoryId,
                'channels' => implode(', ', $entries),
            ]);
        }
        $added = array_slice($this->tasklets, $first);
        $exits = array_values(array_filter($added, static fn (TaskNode $node): bool => $node->output === null));
        if (count($exits) > 1) {
            $names = array_map(static fn (TaskNode $node): string => $node->name, $exits);
            sort($names);
            throw new FlezhaException('topology.embedded_exit_ambiguous', [
                'inner' => $factoryId,
                'channels' => implode(', ', $names),
            ]);
        }
        $exit = $exits[0] ?? ($added === [] ? null : $added[count($added) - 1]);

        return $exit === null ? $feed : new Edge($this, ['tasklet', $exit], []);
    }

    /** A node's identity inside the class being inlined: `<prefix>.<name>`. */
    private function prefixed(string $name): string
    {
        $inline = array_key_last($this->inlining);

        return $inline === null ? $name : $this->inlining[$inline]['prefix'] . '.' . $name;
    }

    /**
     * The channel this node produces on, created on first use.
     *
     * Lazily, so a leaf node never carries a phantom channel. Named by the
     * node's identity until a `named(...)` override, and reused by every later
     * call on an edge whose source is this node — which is what makes a fan-out
     * one channel with two consumers rather than two channels claiming one
     * producer.
     */
    public function outputChannelFor(TaskNode $node): ChannelSpec
    {
        if ($node->output !== null) {
            return $node->output;
        }

        $channel = new ChannelSpec(
            index: count($this->channels),
            name: $node->name,
            kind: 'inter_task',
            producer: $node->index,
        );
        $this->channels[] = $channel;
        $node->output = $channel;

        return $channel;
    }

    /**
     * Record one more link on the sequence at `$base`, or open one on
     * `$source`, and answer the new sequence's id.
     *
     * The links are copied rather than appended in place: a handle held over a
     * branch point extends the prefix twice, and two sequences sharing one
     * list would each see the other's links.
     *
     * `$edge` is the consumer an `into` or a merge source closes on, held aside
     * for the core to name back on the edge it derives from this chain.
     *
     * @param array{string, ChannelSpec|TaskNode} $source
     * @param array<string, mixed>                $link
     */
    public function linkChain(array $source, ?int $base, array $link, ?ConsumerEdge $edge = null): int
    {
        $links = $base === null ? [] : $this->chains[$base]['links'];
        if ($base !== null) {
            $this->chains[$base]['superseded'] = true;
        }
        $links[] = $link;
        $this->chains[] = ['source' => $source, 'links' => $links, 'superseded' => false, 'edge' => $edge];

        return count($this->chains) - 1;
    }

    /**
     * File every sequence on the channel it was declared off, in declaration
     * order — the `chains` the record carries.
     *
     * A channel named only by a sequence no terminator closed is created here,
     * so a dangling chain is submitted rather than dropped on the way to the
     * core. Idempotent: a spec is assembled once, but a builder is reachable.
     */
    public function seal(): self
    {
        if ($this->sealed) {
            return $this;
        }
        $this->sealed = true;
        foreach ($this->chains as $chain) {
            if ($chain['superseded']) {
                continue;
            }
            $channel = $this->channelOf($chain['source']);
            $channel->chains[] = $chain['links'];
            $channel->chainEdges[] = $chain['edge'];
        }

        return $this;
    }

    /**
     * The channel an edge's values leave on: the entry's own, or the producing
     * node's, created on first use.
     *
     * @param array{string, ChannelSpec|TaskNode} $source
     */
    private function channelOf(array $source): ChannelSpec
    {
        $from = $source[1];

        return $from instanceof TaskNode ? $this->outputChannelFor($from) : $from;
    }
}

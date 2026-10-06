<?php

declare(strict_types=1);

namespace Flezha\Graph;

use Flezha\ChannelCodec;
use Flezha\Definition;
use Flezha\Exception\FlezhaException;
use Flezha\Relay;
use Flezha\Overflow;
use Flezha\Tasklet;

/**
 * The fluent channel builder a relay's `channels()` receives.
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
     * `$description` says what the channel is for, in the relay's API. A JSON
     * channel's schema is derived from the `inputType` the tasklet it feeds
     * declares in its `#[Task]`; `$schema` declares one instead.
     *
     * @param (callable(string):mixed)|null $decode
     * @param mixed $schema a JSON Schema, as the array `json_encode` writes as an object
     */
    public function input(
        string $name,
        ?callable $decode = null,
        ?ChannelCodec $codec = null,
        string $description = '',
        mixed $schema = null,
    ): Edge {
        $channel = new ChannelSpec(index: count($this->channels), name: $name, kind: 'entry');
        $this->refuseCodecBesideCallable($channel, $codec, $decode);
        $channel->decode = $decode ?? ($codec ?? ChannelCodec::Json)->decoder($channel);
        $channel->codec = $decode !== null ? 'custom' : ($codec ?? ChannelCodec::Json)->value;
        $channel->description = $description;
        $channel->schema = $schema;
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
     * Turn a relay-wide document stream into per-key deliveries: every key
     * seen on `$traffic` receives each distinct document once, on that key's
     * own next traffic item, as a `[$key, $document]` pair.
     *
     * Delivering on the key's own traffic, never over a remembered key set, is
     * what keeps every emitted key one this member owns after a rescale. The
     * price is one tick of latency per key, and a key with no traffic receives
     * nothing. A document whose JSON is identical to the retained one is inert.
     *
     * The node is `route_latest` like any tasklet node, so a second router in
     * one relay needs {@see routeLatestNamed()}.
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
     * @param class-string<Tasklet>|class-string<Relay> $class
     */
    public function nodeFor(string $class, ?string $name = null): TaskNode
    {
        $definition = Definition::of($class);
        $identity = $name ?? $definition->name;

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

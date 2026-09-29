<?php

declare(strict_types=1);

namespace Flezha\Graph;

use Flezha\ChannelCodec;
use Flezha\Dropped;
use Flezha\Exception\FlezhaException;
use Flezha\Fanned;
use Flezha\Overflow;
use Flezha\Operator;
use Flezha\Tasklet;

/**
 * One edge under construction.
 *
 * Immutable: every fluent call answers a new edge, so a shared prefix can be
 * branched without the arms colliding — which is what makes `branch(...)`
 * build exactly the topology the separate `into(...)` statements would.
 */
final class Edge
{
    /** The two links a fluent call adds; every `map` records the same one. */
    private const TRANSFORM = ['link' => 'transform'];
    private const KEY = ['link' => 'key'];

    /**
     * @param array{string, ChannelSpec|TaskNode} $from
     * @param array<string, mixed>                $pending
     */
    public function __construct(
        private readonly Builder $builder,
        private readonly array $from,
        private readonly array $pending,
    ) {
    }

    /** Override the channel name this edge creates. */
    public function named(string $name): self
    {
        return $this->with(['channelName' => $name]);
    }

    /** Transform every value on this edge. Evaluated producer-side. */
    public function map(callable $transform): self
    {
        return $this->relink(self::TRANSFORM, ['transforms' => [...$this->transforms(), $transform]]);
    }

    /**
     * Drop the values a predicate rejects, before this edge's key is computed.
     * A dropped value is consumed and reaches no handler.
     */
    public function filter(callable $predicate): self
    {
        return $this->map(
            static fn (mixed $value): mixed => $predicate($value) ? $value : Dropped::get(),
        );
    }

    /** Transform one value into many; each reaches the consumer on its own. */
    public function flatMap(callable $transform): self
    {
        return $this->map(
            static fn (mixed $value): Fanned => new Fanned(array_values([...$transform($value)])),
        );
    }

    /** Route by a computed key: the consumer becomes a dynamic keyed tasklet. */
    public function keyBy(callable $key): self
    {
        return $this->relink(self::KEY, ['keyBy' => $key]);
    }

    public function onFull(Overflow $policy): self
    {
        return $this->with(['overflow' => $policy]);
    }

    public function retryMax(int $attempts): self
    {
        return $this->with(['retryMax' => $attempts]);
    }

    public function retryBackoffMs(int $milliseconds): self
    {
        return $this->with(['retryBackoffMs' => $milliseconds]);
    }

    /** Stamp `flz.cell` / `flz.seq` / `flz.key` onto this channel's plugin frames. */
    public function keyed(): self
    {
        return $this->with(['keyed' => true]);
    }

    /**
     * Fan out: each arm receives this same edge, so `branch` builds exactly
     * the topology the separate `into(...)` statements would.
     *
     * The arity is recorded as a sequence of its own, so the arms extend the
     * prefix this edge already carries rather than the branch's own record.
     *
     * @param callable(self):mixed ...$arms
     */
    public function branch(callable ...$arms): self
    {
        $this->builder->linkChain($this->from, null, ['link' => 'branch', 'arms' => count($arms)]);
        foreach ($arms as $arm) {
            $arm($this);
        }

        return $this;
    }

    /**
     * Wire this edge into a tasklet: a `Tasklet` subclass, or a short-form
     * operator class, which is its own.
     *
     * @param class-string<Tasklet>|class-string<Operator> $class
     */
    public function into(string $class, ?string $nodeName = null): self
    {
        $node = $this->builder->nodeFor($class, $nodeName);

        return $this->wire($node, ['link' => 'into', 'tasklet' => $node->index]);
    }

    /**
     * Close this edge's chain on `$node` with `$link`: an `into`, or one source
     * of a merge. Not an authoring call; `into` and `Merge::into` are.
     *
     * @internal
     * @param array<string, mixed> $link
     */
    public function wire(TaskNode $node, array $link): self
    {
        // Creates this edge's channel and applies its pending options, before
        // the link that closes on it is recorded.
        $this->channel();
        // The transforms and the key belong to this edge, not to the channel:
        // one channel may feed several tasklets, and a chain declared before
        // one `into` must not reach another. They ride the chain that closes on
        // them, and the core names that chain back on the edge it derived — so
        // whether a re-declaration is the legal fan-in no-op or
        // `EdgeAlreadyWired` is answered there and nowhere here.
        $this->builder->linkChain(
            $this->from,
            $this->pending['chain'] ?? null,
            $link,
            new ConsumerEdge($node->index, $this->transforms(), $this->pending['keyBy'] ?? null),
        );

        return new self($this->builder, ['tasklet', $node], []);
    }

    /**
     * Terminate this edge's channel on the plugin layer, and answer the edge.
     * A name written here IS the channel's name, everywhere one appears;
     * `sink()` leaves it derived as it would be without a sink at all.
     *
     * `sink(...)->into(...)` wires a consumer behind the sink, so one emit
     * reaches both. A channel with no consumer behind it is the plugin-bound
     * terminal — terminality is the absence of further wiring, not a different
     * method.
     *
     * The one refusal left here: a channel with no producer has no value to
     * serialize, and the core reads a sink as the terminal of the channel it
     * sits on — it cannot see that this one is fed by the controller.
     *
     * `$encode` turns a value into the plugin frame's bytes, and `$codec` names
     * a format instead. With neither, a string is sent as it stands and any
     * other value as JSON, so a sink needs neither for a string or a JSON value.
     *
     * @param string|(callable(mixed):string)|null $nameOrEncode
     * @param (callable(mixed):string)|null         $encode
     */
    public function sink(
        string|callable|null $nameOrEncode = null,
        ?callable $encode = null,
        ?ChannelCodec $codec = null,
    ): self {
        // One method, two positions: a string names the channel, a callable is
        // the serializer. PHP has no overloading, so telling them apart here is
        // what keeps the serializer-only form from spelling `sink(null, $enc)`.
        $name = is_string($nameOrEncode) ? $nameOrEncode : null;
        $encode = is_callable($nameOrEncode) ? $nameOrEncode : $encode;
        $link = ['link' => 'sink'];
        if ($name !== null) {
            $link['name'] = $name;
        }

        return $this->plugEnd($link, $name, $encode, $codec);
    }

    /**
     * Terminate this edge's channel on the answer to the outside call its
     * input carried, and answer the edge. Each value emitted on it answers
     * that caller and reaches no plugin. It takes no name, so the channel
     * keeps the one it would have without it, and consumers may follow it
     * exactly as after `sink()`. A channel ends in one sink or one response.
     * `$encode` and `$codec` are `sink()`'s.
     *
     * @param (callable(mixed):string)|null $encode
     */
    public function response(?callable $encode = null, ?ChannelCodec $codec = null): self
    {
        return $this->plugEnd(['link' => 'response'], null, $encode, $codec);
    }

    /**
     * Attach a plugin end, a sink or a response: both leave as a plugin
     * output encoded the same way, and the core refuses a second end.
     *
     * @param array{link: string, name?: string} $link
     * @param (callable(mixed):string)|null      $encode
     */
    private function plugEnd(array $link, ?string $name, ?callable $encode, ?ChannelCodec $codec): self
    {
        if ($this->from[0] !== 'tasklet') {
            throw new FlezhaException('topology.sink_on_entry', ['channel' => $name ?? '<derived>']);
        }

        $channel = $this->channel();
        // The name written here IS the channel's name; `sink()` with none
        // leaves it derived as it would be without a sink at all.
        if ($name !== null) {
            $channel->name = $name;
        }
        $this->builder->refuseCodecBesideCallable($channel, $codec, $encode);
        $this->builder->linkChain($this->from, $this->pending['chain'] ?? null, $link);
        // First call wins on the builder's own side, as the reference does; a
        // second end is what the core refuses the class for.
        if ($channel->sink === null) {
            $channel->sink = $channel->name;
            $channel->sinkEncode = $encode ?? $codec?->encoder($channel) ?? ChannelCodec::defaultEncoder($channel);
        }

        return $this;
    }

    /**
     * The channel this edge's values leave on, with the pending options
     * applied. An entry edge already has one; a tasklet edge's is created on
     * first use and reused after.
     */
    private function channel(): ChannelSpec
    {
        if ($this->from[0] === 'entry') {
            $channel = $this->from[1];
        } else {
            $channel = $this->builder->outputChannelFor($this->from[1]);
            $rename = $this->pending['channelName'] ?? null;
            // Recorded where it is APPLIED, so the core reads the overrides in
            // the order this builder took them and the two cannot disagree
            // about which name a channel ends up with.
            if ($rename !== null && $channel->name !== $rename) {
                $channel->name = $rename;
                $this->builder->linkChain($this->from, null, ['link' => 'named', 'name' => $rename]);
            }
        }
        $this->applyPending($channel);

        return $channel;
    }

    /** @return list<callable(mixed):mixed> */
    private function transforms(): array
    {
        return $this->pending['transforms'] ?? [];
    }

    /** @param array<string, mixed> $next */
    private function with(array $next): self
    {
        $edge = new self($this->builder, $this->from, [...$this->pending, ...$next]);
        // Applied now, as the reference applies it: an option no terminator
        // follows would otherwise be dropped.
        $edge->channel();

        return $edge;
    }

    /**
     * This edge with one more chain link — the one place a transform's or a
     * key's result edge is built, so every link reaches the record and a chain
     * no `into(...)` closes is submitted rather than silently discarded.
     *
     * @param array<string, mixed> $link
     * @param array<string, mixed> $next
     */
    private function relink(array $link, array $next): self
    {
        return new self($this->builder, $this->from, [
            ...$this->pending,
            ...$next,
            'chain' => $this->builder->linkChain($this->from, $this->pending['chain'] ?? null, $link),
        ]);
    }

    private function applyPending(ChannelSpec $channel): void
    {
        $channel->overflow = $this->pending['overflow'] ?? $channel->overflow;
        $channel->retryMax = $this->pending['retryMax'] ?? $channel->retryMax;
        $channel->retryBackoffMs = $this->pending['retryBackoffMs'] ?? $channel->retryBackoffMs;
        $channel->keyed = $channel->keyed || ($this->pending['keyed'] ?? false);
    }
}

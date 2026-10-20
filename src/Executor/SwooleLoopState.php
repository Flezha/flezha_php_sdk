<?php

declare(strict_types=1);

namespace Flezha\Executor;

use Flezha\Runner;
use Swoole\Thread\Atomic;

/**
 * {@see LoopState} for the threaded executor: the same three integers, in
 * atomics every thread of the pool shares.
 *
 * `Swoole\Thread\Atomic` is one of the few values that crosses a thread
 * boundary — a fresh interpreter cannot receive an ordinary object — which is
 * why the budget is counted here rather than per loop: one runner has one
 * budget, and N threads each spending their own would recycle N times too
 * late.
 *
 * Part of the Swoole adapter, so it names Swoole types; nothing outside the
 * adapter's own files does.
 */
final class SwooleLoopState implements LoopState
{
    private readonly Atomic $stopping;
    private readonly Atomic $claimed;
    private readonly Atomic $observed;
    private readonly Atomic $bodyNanos;
    /** This thread's polls without work since its last activation, in milliseconds. */
    private int $idleMs = 0;

    /**
     * The atomics are constructor arguments so a worker thread rebuilds this
     * state over the ones the pool already shares: the thread receives them as
     * bootstrap arguments and passes them straight back in. `retireMs` is
     * this thread's own: the governor's retire time for a grown one, 0 for
     * one that never leaves.
     */
    public function __construct(
        private readonly ?int $budget = null,
        private readonly int $retireMs = 0,
        ?Atomic $stopping = null,
        ?Atomic $claimed = null,
        ?Atomic $observed = null,
        ?Atomic $bodyNanos = null,
    ) {
        $this->stopping = $stopping ?? new Atomic(0);
        $this->claimed = $claimed ?? new Atomic(0);
        $this->observed = $observed ?? new Atomic(0);
        $this->bodyNanos = $bodyNanos ?? new Atomic(0);
    }

    /**
     * The atomics, in the order the constructor takes them — the shape a
     * thread bootstrap argument list carries.
     *
     * @return list<Atomic>
     */
    public function atomics(): array
    {
        return [$this->stopping, $this->claimed, $this->observed, $this->bodyNanos];
    }

    public function stopping(): bool
    {
        return $this->stopping->get() === 1;
    }

    public function stop(): void
    {
        $this->stopping->set(1);
    }

    public function claim(): bool
    {
        if ($this->budget === null) {
            return true;
        }

        return $this->claimed->add(1) <= $this->budget;
    }

    public function observe(int $bodyNanos): void
    {
        $this->observed->add(1);
        $this->bodyNanos->add($bodyNanos);
        $this->idleMs = 0;
    }

    public function idle(): bool
    {
        $this->idleMs += Runner::POLL_TIMEOUT_MS;

        return $this->retireMs > 0 && $this->idleMs >= $this->retireMs;
    }

    public function report(): array
    {
        $observed = $this->observed->get();

        return [$observed, $observed === 0 ? 0 : intdiv($this->bodyNanos->get(), $observed)];
    }
}

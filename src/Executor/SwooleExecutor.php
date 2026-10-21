<?php

declare(strict_types=1);

namespace Flezha\Executor;

use Flezha\Exception\FlezhaException;
use Flezha\Ffi\Binding;
use Flezha\Logger;
use Flezha\Runner;
use Swoole\Thread;
use Throwable;

/**
 * The poll loop on a pool of `Swoole\Thread`s over one shared `FlzContext`, so
 * a single runner — and therefore a single relay's tasklet class — uses
 * more than one core.
 *
 * Each thread bootstraps the runner's entry script, rebuilds the PHP-side
 * tasklet registry and *attaches* to the existing context by its address,
 * without re-declaring classes to the core: `flz_core_declare_class` is per
 * context, not per thread. Ownership is asymmetric — the creating thread frees
 * the context, and only after every worker has been joined.
 *
 * How many threads is the core's pool governor's to say: the supervisor
 * blocks in `flz_core_pool_supervise`, which waits on the core's starvation
 * signal (for "hosts whose fetch threads can be occupied by user code"), and
 * starts the thread each answer asks for, a grown one leaving after the
 * governor's retire time idle. `FLEZHA_WORKER_POOL_SIZE` (default 1) and
 * `FLEZHA_WORKER_POOL_MAX` (default ten times it) size it.
 *
 * Selected explicitly and never the default: it needs a thread-safe (ZTS) PHP
 * build and an extension most distributions do not ship.
 */
final class SwooleExecutor implements Executor
{
    /** @var list<Thread> */
    private array $threads = [];
    private ?SwooleLoopState $state = null;
    private ?Runner $runner = null;
    /** How long a grown thread polls without work before it leaves. */
    private int $retireMs = 0;
    /** How long `stop()` waits for the pool to leave user code. */
    private float $drainS = 5.0;

    public function __construct(private readonly ?int $maxActivations = null)
    {
        self::assertRuntime();
    }

    public function name(): string
    {
        return 'swoole';
    }

    /**
     * Refuse a build that cannot run this adapter, naming what is missing and
     * how to get it — an instruction rather than `Class "Swoole\Thread" not
     * found` deep in the boot path, and before the runner connects.
     */
    public static function assertRuntime(): void
    {
        $refusal = self::requirementRefusal(
            (bool) PHP_ZTS,
            class_exists(Thread::class),
            (string) ini_get('ffi.enable'),
        );

        if ($refusal !== null) {
            throw new FlezhaException('runtime.executor_unavailable', ['executor' => 'swoole', 'reason' => $refusal]);
        }
    }

    /**
     * What this build is missing, or null when it is missing nothing.
     *
     * Pure, and taking what it reads, so every arm is provable on one machine:
     * `ffi.enable` is PHP_INI_SYSTEM, and a test cannot make a satisfying build
     * unsatisfying at runtime.
     */
    public static function requirementRefusal(bool $zts, bool $hasThread, string $ffiEnable): ?string
    {
        if (!$zts) {
            return 'SwooleExecutor needs a thread-safe (ZTS) PHP build; this one is '
                . 'non-thread-safe. Install a ZTS PHP (the official image is `php:8.4-zts`, and '
                . '`deploy/docker/dev/Dockerfile.php --target zts` builds one with Swoole already in '
                . 'it), or use the default SyncExecutor and scale by processes.';
        }
        if (!$hasThread) {
            return 'SwooleExecutor needs Swoole\Thread, which only a Swoole built against a ZTS '
                . 'PHP with --enable-swoole-thread provides. Install it with: pecl download '
                . 'swoole && tar -xf swoole-*.tgz && cd swoole-* && phpize && ./configure '
                . '--enable-swoole-thread && make && make install, then enable the extension.';
        }
        // Measured, not assumed: `ffi.enable=preload` — the shipped default —
        // permits FFI from the CLI SAPI, and a `Swoole\Thread` is not it. A
        // worker's very first core call is then refused by the FFI extension,
        // in a fresh interpreter, where the failure reads as a broken install.
        if (strtolower($ffiEnable) !== 'true' && $ffiEnable !== '1') {
            return sprintf(
                'SwooleExecutor needs ffi.enable=true, and this build has ffi.enable=%s. The '
                . 'shipped default `preload` permits FFI from the CLI SAPI only, and a worker '
                . 'thread is a fresh interpreter that is not it — so the pool would start and '
                . 'every thread would be refused its first core call. Set ffi.enable=true in '
                . 'php.ini, or run with -d ffi.enable=1.',
                $ffiEnable === '' ? "''" : $ffiEnable,
            );
        }

        return null;
    }

    public function run(Runner $runner): void
    {
        $this->runner = $runner;
        $this->state = new SwooleLoopState($this->maxActivations);

        // One thread to begin with unless told otherwise. The pool finds its
        // own width from the core's starvation signal, so an idle runner costs
        // one thread and a busy one grows to the ceiling.
        [$base, , , $this->retireMs, $drainMs] = $runner->core()->poolOpen(1);
        $this->drainS = $drainMs / 1000;
        for ($i = 0; $i < $base; $i++) {
            $this->spawn(false);
        }

        // The supervisor is this thread, not a fourth mechanism: `run` blocks
        // until the pool is done anyway, the main thread is otherwise idle,
        // and it is the thread that owns the pool it grows. Its bounded wait
        // also gives the runner a place to observe a stop request.
        $this->supervise($runner);
        $this->joinAll();
    }

    public function stop(): void
    {
        $this->state?->stop();
        $this->joinAll();
    }

    /** How many workers are alive now. */
    private function size(): int
    {
        return count(array_filter($this->threads, static fn (Thread $t): bool => $t->isAlive()));
    }

    /**
     * Forget the workers that have left and give their seats back, so the
     * governor can replace them.
     *
     * Here and not in the worker, because a fatal error ends a thread with no
     * `finally` run: a dead thread still counted against the ceiling is how a
     * pool of eight corpses reports starvation forever and processes nothing.
     * Measured, not hypothetical — a fatal error in the entry script killed
     * every worker on bootstrap and the ceiling warning was the only thing
     * that said so.
     *
     * A non-zero exit is reported once, because it is a defect in user code or
     * in the entry script and nothing else will name it.
     */
    private function prune(): void
    {
        $live = [];
        foreach ($this->threads as $thread) {
            if ($thread->isAlive()) {
                $live[] = $thread;
                continue;
            }
            $this->runner?->core()->poolLeft();
            $status = $thread->getExitStatus();
            if ($status !== 0 && !($this->state?->stopping() ?? false)) {
                Logger::current()->error(sprintf(
                    'a worker thread ended with status %s before shutdown; the pool will replace '
                    . 'it. A fatal error in the entry script kills every worker the same way — '
                    . 'the script is re-entered by each one, so it must carry no `#!` line and '
                    . 'must be safe to require.',
                    var_export($status, true),
                ));
            }
        }
        $this->threads = $live;
    }

    /** One worker into a seat the governor reserved; one that cannot start gives it back. */
    private function spawn(bool $grown): void
    {
        $runner = $this->runner ?? throw new \LogicException('the pool has no runner to serve');
        try {
            $this->threads[] = new Thread(
                __DIR__ . '/swoole_worker.php',
                $runner->autoloadPath(),
                $runner->entryScript(),
                $runner->coreAddress(),
                $this->maxActivations ?? 0,
                $grown ? $this->retireMs : 0,
                ...($this->state?->atomics() ?? []),
            );
        } catch (Throwable $e) {
            $runner->core()->poolLeft();
            Logger::current()->warn('could not start a worker thread: ' . $e->getMessage());
        }
    }

    /**
     * Answer the governor until the runner stops: one decision per wait, at
     * most a second apart, and the core's notices written on each.
     *
     * The governor waits on `flz_core_pool_supervise`'s starvation signal,
     * which answers when work is queued and no thread is inside `poll` —
     * precisely a PHP thread sitting inside a user's PDO call — so the pool
     * finds the width the workload needs rather than the one someone guessed.
     */
    private function supervise(Runner $runner): void
    {
        while (!($this->state?->stopping() ?? true)) {
            $this->prune();
            $answer = $runner->core()->poolSupervise();
            $runner->core()->drainNotices();
            if ($answer === Binding::POOL_STOP) {
                $this->state?->stop();
                break;
            }
            if ($answer === Binding::POOL_SPAWN_BASE || $answer === Binding::POOL_SPAWN_GROWN) {
                $this->spawn($answer === Binding::POOL_SPAWN_GROWN);
            }
        }
    }

    /**
     * Join every thread, and only then let the caller free the context.
     *
     * The drain is bounded for the reason every drain in this project is: a handler that
     * never returns holds its thread forever, and a join that waited on it
     * would make a runner stopped by closing its pipes leak a process rather
     * than exit.
     */
    private function joinAll(): void
    {
        $deadline = microtime(true) + $this->drainS;
        foreach ($this->threads as $thread) {
            try {
                while ($thread->isAlive() && microtime(true) < $deadline) {
                    usleep(5_000);
                }
                if ($thread->isAlive()) {
                    continue;
                }
                $thread->join();
            } catch (Throwable $e) {
                Logger::current()->warn('joining a worker thread failed: ' . $e->getMessage());
            }
        }

        $alive = $this->size();
        if ($alive > 0) {
            // Silence here is the failure the bound exists to avoid
            // reintroducing: a runner that leaves without reporting what it
            // abandoned looks identical to one that drained.
            Logger::current()->warn(sprintf(
                '%d worker thread(s) were still inside user code after the %.0fs drain; the '
                . 'runner is leaving anyway, and the context is NOT freed because they may yet '
                . 'call into it. The core re-hands unanswered work on the next bind — find the '
                . 'handler that does not return.',
                $alive,
                $this->drainS,
            ));
            $this->runner?->abandonContext();
        }

        $this->prune();
    }

    /** The pool's own numbers, for the runner's shutdown line. */
    public function state(): ?LoopState
    {
        return $this->state;
    }
}

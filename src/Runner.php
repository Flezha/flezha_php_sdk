<?php

declare(strict_types=1);

namespace Flezha;

use Flezha\Codec\ActivationCodec;
use Flezha\Codec\Json;
use Flezha\Dispatch\Dispatcher;
use Flezha\Dispatch\Registry;
use Flezha\Exception\FlezhaException;
use Flezha\Executor\Executor;
use Flezha\Executor\Executors;
use Flezha\Executor\LoopState;
use Flezha\Ffi\Binding;
use Flezha\Ffi\Core;
use Flezha\Graph\ClassSpec;
use Flezha\Graph\HostClassDecl;
use Flezha\Graph\Validate;

/**
 * The runner: what an author starts, and everything it owns while running.
 *
 * Classes are registered explicitly, by naming them. There is no autoload
 * scan and no package walk: what runs is what the author said runs, and a
 * class that is defined but not registered never reaches the core.
 *
 * ## One runner per process
 *
 * The SDK never forks, spawns or supervises. N processes are N runners, which
 * is what the controller's placement already spreads relays across, and it
 * keeps this layer agnostic between Kubernetes, systemd and a supervisor's own
 * process management. Threads inside one process are the executor's business,
 * not this class's.
 *
 * ## Re-entry from a worker thread
 *
 * A `SwooleExecutor` worker bootstraps this same entry script, so `run()` has
 * two paths: the first caller creates the context, declares the classes and
 * connects; a worker finds the attachment its bootstrap left, rebuilds only
 * the PHP-side registry and serves. `flz_core_declare_class` is per context,
 * not per thread, and must not run twice.
 */
final class Runner
{
    /** How long a loop parks in one `poll`. */
    public const POLL_TIMEOUT_MS = 250;

    /**
     * The context and loop state a worker thread was handed, or null on the
     * thread that creates them.
     *
     * A static, which on a thread-safe build is already per-thread: each
     * `Swoole\Thread` is a separate interpreter with its own statics, so this
     * is invisible to every thread but the one whose bootstrap wrote it.
     *
     * @var array{int, LoopState}|null
     */
    private static ?array $attachment = null;

    /** @var list<class-string<Relay>> */
    private array $classes = [];
    private readonly Registry $registry;
    private ?Core $core = null;
    private ?Executor $executor = null;

    private ?Dispatcher $dispatcher = null;
    private ?int $maxActivations = null;

    public function __construct(public readonly string $namespace)
    {
        $this->registry = new Registry();
        $this->maxActivations = Env::positive('FLEZHA_MAX_ACTIVATIONS');
    }

    /**
     * Leave the context and loop state a worker thread should attach to.
     *
     * Called by the Swoole adapter's thread bootstrap before it re-enters the
     * entry script; nothing else has any reason to.
     */
    public static function attachInThread(int $address, LoopState $state): void
    {
        self::$attachment = [$address, $state];
    }

    /**
     * Register a relay class, a full form or a §5 short form alike. After
     * start the class is declared at once: this thread's executor serves it
     * and the controller learns of it. Under the Swoole executor that is
     * refused, because each thread serves the classes registered before start.
     *
     * @param class-string<Relay> $class
     */
    public function registerFactory(string $class): self
    {
        // Resolved here so a malformed declaration is refused at the call the
        // author wrote, not at the first connection.
        $spec = Relay::specFor($class);
        if ($this->core !== null) {
            $this->refuseUnderThreads();
            $this->admit($spec);
            $this->core->declareClass(Json::encode(HostClassDecl::of($spec)));
        }
        $this->classes[] = $class;

        return $this;
    }

    /**
     * Undo a registration — the inverse of {@see registerFactory()}, taking
     * what it took. After start the core refuses a class with live instances
     * on this runner; otherwise this thread's executor stops serving it and
     * the controller learns of it. Under the Swoole executor it is refused,
     * as {@see registerFactory()} is.
     *
     * @param class-string<Relay> $class
     */
    public function unregisterFactory(string $class): self
    {
        $factoryId = $class::declaration()->factoryId;
        if (!in_array($class, $this->classes, true)) {
            throw new FlezhaException('runtime.class_not_registered', ['factory' => $factoryId]);
        }
        if ($this->core !== null) {
            $this->refuseUnderThreads();
            $this->core->undeclareClass($factoryId);
            $this->registry->remove($factoryId);
        }
        $this->classes = array_values(array_filter(
            $this->classes,
            static fn (string $registered): bool => $registered !== $class,
        ));

        return $this;
    }

    /** Which executor drives the poll loop. `SyncExecutor` when never called. */
    public function executor(Executor $executor): self
    {
        $this->executor = $executor;

        return $this;
    }

    public function logger(Logger $logger): self
    {
        Logger::use($logger);

        return $this;
    }

    /**
     * Administer this namespace from ordinary application code: start and
     * stop relays, enable and disable plugins, read what is running.
     *
     * The controller judges every call as the identity this connection proved,
     * so no credential is held here and nothing expires. A user grants a
     * namespace's applications the authority once, with
     * `flz role bind runner@<namespace> app <namespace>`; until then every
     * call is refused.
     */
    public function control(): Control
    {
        return new Control($this->core()->adminCall(...));
    }

    /**
     * Send `$message` to the entry channel `$channel` of `$relayId`, through
     * the controller under the same role check as {@see control()}, answering
     * its verdict: `['to' => relay, 'verdict' => 'delivered' | 'absorbed' |
     * 'suspended']`. `$msgId` is stamped as `flz.msg_id`, so a relay whose
     * input log still holds that id absorbs the repeat; `$key` reaches the
     * partition group member whose range owns it.
     *
     * `$local`: in process, without the controller: the message is on no disk,
     * is lost if the runner stops before handling it, and a relay's input
     * log does not cover it. It answers null.
     *
     * @throws FlezhaException
     */
    public function send(
        string $relayId,
        string $message,
        string $channel,
        ?string $msgId = null,
        ?string $key = null,
        bool $local = false,
    ): mixed {
        $answer = $this->core()->sendMessage($relayId, $channel, $message, $msgId, $key, $local);

        return json_decode($answer, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Call the entry channel `$channel` of `$relayId` with `$value`, through
     * the controller under the same role check as {@see send()}, answering what
     * the relay answers through its outputs. `$value` is encoded as an output
     * naming no codec encodes it: a string as it is, anything else as JSON. A
     * JSON answer comes back decoded as arrays, a text or binary one as a
     * string; a channel that does not answer gives `['accepted' => true]`.
     *
     * @throws FlezhaException the controller's code when it refuses the call
     */
    public function call(string $relayId, string $channel, mixed $value): mixed
    {
        $payload = base64_encode(ChannelCodec::plain($value, "call of '{$channel}'"));

        return self::answerOf(
            $this->control()->call('Relay.call_channel', $relayId, $channel, $payload, ['payload' => 'base64']),
        );
    }

    /**
     * A call's answer by its format: JSON and text as they decoded, a binary
     * format's base64 as its bytes, and a channel that does not answer as the
     * reply itself.
     *
     * @param array<string, mixed> $reply
     */
    private static function answerOf(array $reply): mixed
    {
        if (!isset($reply['answer_codec'])) {
            return $reply;
        }

        return in_array($reply['answer_codec'], ['json', 'text'], true)
            ? $reply['answer']
            : base64_decode($reply['answer'], true);
    }

    /**
     * The ids of the relay instances bound on this runner now, read without
     * the controller.
     *
     * @return list<string>
     * @throws FlezhaException `runtime.runner_not_started` before it starts
     */
    public function relays(): array
    {
        return ($this->core ?? throw new FlezhaException('runtime.runner_not_started'))->relays();
    }

    /**
     * Shut down cleanly after this many activations, so an external
     * supervisor can recycle a long-lived process without data loss.
     *
     * Unlimited by default. A clean shutdown leaves unpolled work journaled
     * controller-side, to be re-forwarded on the next bind.
     */
    public function maxActivations(?int $count): self
    {
        $this->maxActivations = $count;

        return $this;
    }

    /**
     * Declare, connect and serve until shutdown.
     *
     * On a worker thread this attaches to the context the pool shares and
     * serves against it, which is why the entry script must be re-enterable.
     */
    public function run(): void
    {
        $attachment = self::$attachment;
        if ($attachment !== null) {
            [$address, $state] = $attachment;
            $this->core = Core::attach($address);
            $this->buildRegistry();
            $this->dispatcher = new Dispatcher($this->registry, $this->core);
            $this->serve($state);

            return;
        }

        $this->start();
        $this->awaitTermination();
    }

    /**
     * Declare, connect and start serving — everything up to and including the
     * first poll, and nothing that blocks.
     *
     * Separate from `run` so a host with its own supervision can own the
     * waiting, and so the startup order is something a test can observe.
     */
    public function start(): self
    {
        // Before anything else: a shim built against a different activation
        // schema is refused here, with no controller connection attempted.
        $this->core = Core::create();
        $this->buildRegistry();
        $this->declareAll();
        $this->dispatcher = new Dispatcher($this->registry, $this->core);
        $this->executor ??= Executors::fromEnvironment($this->maxActivations);

        $this->core->start($this->namespace);
        // Shutdown is observed three ways, because the harness, a user and
        // the controller each have their own: end of file on standard input, a
        // signal, and the core's own stop. The first two are the core's, on a
        // thread of its own — which is why they are observed even from inside
        // an activation body, where a non-thread-safe PHP has nothing that
        // could look. The harness stops a runner by closing its standard input.
        if (!stream_isatty(STDIN)) {
            $this->core->watchStdinEof();
        }

        Logger::current()->info(sprintf(
            'runner up on namespace %s: %s executor, %d relay class(es)',
            $this->namespace,
            $this->executor->name(),
            count($this->classes),
        ));

        return $this;
    }

    /** Serve until shutdown, then drain and release the core. */
    public function awaitTermination(): void
    {
        $executor = $this->executor ?? throw new FlezhaException('runtime.runner_not_started');
        try {
            $executor->run($this);
        } finally {
            $this->drain();
        }
    }

    /** Stop fetching. What is in flight finishes; nothing new is polled. */
    public function stop(): void
    {
        // The core says when: every loop keeps polling until its poll reports
        // the core shutting down, which the core reports only once the
        // shutdown sequence has shipped every Cell's state through these
        // same loops. The executor's own stop is the bound `drain` applies.
        $this->core?->shutdown();
    }

    /**
     * The poll loop, and the one place the three answers `poll` can give are
     * interpreted.
     *
     * Every executor runs this, so what a timeout, an activation and a
     * shutdown each mean live here rather than in one correct copy per
     * executor. Each thread polls, dispatches and completes within itself, so
     * no activation crosses a thread boundary and there is no queue, no
     * saturation and no backpressure question — a busy thread simply does not
     * poll.
     */
    public function serve(LoopState $state): void
    {
        $core = $this->core ?? throw new \LogicException('the runner has no core to poll');
        $dispatcher = $this->dispatcher ?? throw new \LogicException('the runner has no dispatcher');

        while (!$state->stopping()) {
            $bytes = null;
            $status = $core->poll(self::POLL_TIMEOUT_MS, $bytes);

            if ($status === Binding::POLL_SHUTTING_DOWN) {
                $state->stop();

                return;
            }
            if ($status === Binding::POLL_TIMEOUT) {
                $core->drainNotices();
            }
            if ($status !== Binding::POLL_ACTIVATION || $bytes === null) {
                continue;
            }
            if (!$state->claim()) {
                // The budget is spent: this activation is not ours to run, so
                // hand it back untouched and let the runner exit cleanly. The
                // core re-forwards it on the next bind.
                $state->stop();
                $core->abandon(ActivationCodec::tokenOf($bytes));

                return;
            }

            $started = hrtime(true);
            $dispatcher->dispatch($bytes);
            $state->observe(hrtime(true) - $started);
        }
    }

    public function core(): Core
    {
        return $this->core ?? throw new \LogicException('the runner has no core');
    }

    /** The context address a worker thread attaches to. */
    public function coreAddress(): int
    {
        return $this->core()->address();
    }

    /** The autoloader a worker thread's fresh interpreter has to load first. */
    public function autoloadPath(): string
    {
        // Installed, the package sits at `vendor/flezha/sdk/src`; in a
        // checkout of this repository it is `sdk/php/src`.
        $candidates = [
            dirname(__DIR__, 3) . '/autoload.php',
            dirname(__DIR__) . '/vendor/autoload.php',
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new FlezhaException('runtime.executor_unavailable', [
            'executor' => 'swoole',
            'reason' => 'it bootstraps each worker from Composer\'s autoloader and this process was '
                . 'not started through one; install the package with composer, or use the default '
                . 'SyncExecutor',
        ]);
    }

    /** The entry script a worker thread re-enters to rebuild the registry. */
    public function entryScript(): string
    {
        $script = $_SERVER['SCRIPT_FILENAME'] ?? '';

        return is_string($script) && $script !== ''
            ? $script
            : throw new FlezhaException(
                'runtime.executor_unavailable',
                ['executor' => 'swoole', 'reason' => 'the runner cannot find its own entry script'],
            );
    }

    /**
     * Give up the context without freeing it.
     *
     * A worker the bounded drain gave up on is inside user code and may yet
     * call the core; freeing the context under it would be a use-after-free in
     * native code. The process is on its way out either way, so the kernel
     * reclaims what this skips.
     */
    public function abandonContext(): void
    {
        $this->core = null;
    }

    /** Stop fetching, drain what is in flight, and release the core. */
    private function drain(): void
    {
        Logger::current()->info('draining');

        // By now every loop has left on the core's word, or is inside a body
        // that never returned — the executor's stop bounds the latter. A loop
        // that left on its own activation budget never asked the core, so it
        // is asked here.
        $this->executor?->stop();
        $this->core?->shutdown();

        $state = $this->executor?->state();
        if ($state !== null) {
            self::reportSizing($state);
        }

        // The context is released only when nothing is still running against
        // it; `abandonContext` is what a bounded drain that gave up leaves.
        $this->core?->drainNotices();
        $this->core?->free();
        $this->core = null;
    }

    /** @param class-string<Relay> $class */
    private function buildRegistry(): void
    {
        foreach ($this->classes as $class) {
            $this->admit(Relay::specFor($class));
        }
    }

    private function refuseUnderThreads(): void
    {
        // Each Swoole thread built its own registry at start; a change now
        // would reach this thread alone. By name, so the adapter stays unloaded.
        if ($this->executor?->name() === 'swoole') {
            throw new FlezhaException('runtime.executor_unavailable', [
                'executor' => 'swoole',
                'reason' => 'a class registered or unregistered after start reaches only the calling thread; '
                    . 'register every class before start, or use the default SyncExecutor',
            ]);
        }
    }

    private function admit(ClassSpec $spec): void
    {
        // The verdict before the registration: the core judges the record and
        // this package renders it, so an author meets a declaration refusal
        // rather than a native failure at the first connection.
        Validate::spec($spec, $this->core());
        $this->registry->add($spec);
    }

    private function declareAll(): void
    {
        $core = $this->core();
        foreach ($this->registry->all() as $registered) {
            $core->declareClass(Json::encode(HostClassDecl::of($registered->spec)));
        }
    }

    private static function reportSizing(LoopState $state): void
    {
        [$activations, $meanBodyNanos] = $state->report();
        if ($activations === 0) {
            return;
        }

        Logger::current()->info(sprintf(
            'served %d activation(s), mean tasklet body %.3f ms; one loop sustains about %.0f/s '
            . 'at that latency, and in-flight concurrency is the loop count',
            $activations,
            $meanBodyNanos / 1e6,
            $meanBodyNanos === 0 ? 0.0 : 1e9 / $meanBodyNanos,
        ));
    }
}

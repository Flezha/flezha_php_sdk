<?php

declare(strict_types=1);

namespace Flezha\Ffi;

use FFI;
use FFI\CData;
use Flezha\Exception\FlezhaException;
use Flezha\Logger;
use Flezha\SpawnBridge;

/**
 * One `FlzContext`, and every ABI call a running host makes against it.
 *
 * The veneer adds no ABI surface: each method below forwards to exactly one
 * exported function. What it adds is the ownership rule, which is a host rule
 * rather than a core one — **the thread that created the context frees it, and
 * only after every worker has been joined; a thread that attached never
 * frees.** {@see attach()} is the only way to get an un-owning handle, and
 * {@see free()} is a no-op on one.
 */
final class Core implements SpawnBridge
{
    /**
     * The activation schema this package was built against, passed to
     * `flz_core_context_new` so a shim paired with a mismatched core is
     * refused before any controller connection.
     *
     * Moves in the same commit as `flz_core/src/schema.rs`; the fixture suite
     * is what makes forgetting it a red build.
     */
    public const SCHEMA_VERSION = 8;

    /**
     * The context's address, or null once it has been released.
     *
     * An integer rather than a `CData` pointer: that is what a thread
     * bootstrap argument can carry, and it is also the only shape with no
     * lifetime of its own — see the note on {@see Binding}'s declarations.
     */
    private ?int $ctx;

    /** The binding `responseDecode`'s reused buffers belong to, and the buffers. */
    private static ?FFI $decodedBy = null;
    private static CData $decoded;
    private static CData $decodedParts;

    private function __construct(
        private readonly FFI $ffi,
        ?int $ctx,
        private readonly bool $owned,
    ) {
        $this->ctx = $ctx;
    }

    /**
     * A handle with no context, for the entry points that take none: the
     * monotonic clock reads a process-global, so a caller can read it before
     * `start()` has made its context. Every context-bound call refuses on it.
     */
    public static function contextFree(): self
    {
        return new self(Binding::current(), null, false);
    }

    /**
     * Create the process's context, negotiating the activation schema version
     * before any controller connection is attempted.
     */
    public static function create(): self
    {
        $ffi = Binding::current();
        $ctx = (int) $ffi->flz_core_context_new(self::SCHEMA_VERSION);
        if ($ctx === 0) {
            throw self::initRefusal($ffi);
        }

        $core = new self($ffi, $ctx, true);
        // The first take starts holding the core's notices for this host's
        // drains; anything an earlier context left is written now.
        $core->drainNotices();

        return $core;
    }

    /**
     * The JSON error document the catalogue renders for `$code` with its
     * details filled from `$details`: a refusal this package makes itself,
     * reading as the core's own.
     *
     * @param array<string, mixed> $details
     */
    public static function errorDocument(string $code, array $details): string
    {
        $json = json_encode((object) $details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return self::cstring(Binding::current()->flz_core_error_document($code, $json))
            ?? throw new \LogicException("flz_core rendered no error document for '$code'");
    }

    /**
     * A `Response` channel payload as the core's decoder reads it: the target,
     * `created`, the request's bytes, whether the call was answered, and the
     * answer's bytes or the JSON error document. A payload that is no call
     * envelope throws `call.malformed_response`.
     *
     * @return array{string, bool, string, bool, string}
     */
    public static function responseDecode(string $payload): array
    {
        $ffi = Binding::current();
        $length = strlen($payload);
        // Buffers reused across calls: a per-call `FFI::new` costs more than the decode.
        if (self::$decodedBy !== $ffi || FFI::sizeof(self::$decoded) < $length) {
            self::$decoded = $ffi->new('char[' . max($length, 1024) . ']');
            [self::$decodedBy, self::$decodedParts] = [$ffi, $ffi->new('size_t[5]')];
        }
        $parts = self::$decodedParts;
        if ($ffi->flz_core_response_decode($payload, $length, self::$decoded, $parts) !== Binding::OK) {
            throw self::initRefusal($ffi);
        }
        [$target, $request] = [$parts[0], $parts[1]];
        $data = FFI::string(self::$decoded, $target + $request + $parts[2]);

        return [substr($data, 0, $target), $parts[3] === 1, substr($data, $target, $request), $parts[4] === 1, substr($data, $target + $request)];
    }

    /**
     * A knob's text parsed by the core's rule for `$kind`: null when it is no
     * value of that kind, so the caller keeps its default.
     */
    public static function knob(string $kind, string $raw): ?int
    {
        $ffi = Binding::current();
        $out = $ffi->new('int64_t');

        return match ($ffi->flz_core_knob($kind, $raw, \FFI::addr($out))) {
            0 => $out->cdata,
            1 => null,
            default => throw new \LogicException("'$kind' is not a knob kind"),
        };
    }

    /**
     * Attach to a context another thread created, by the address it published.
     *
     * `flz_core_declare_class` is per context, not per thread, so a worker
     * rebuilds only the PHP-side registry and reaches the same core through
     * this — and never frees it.
     */
    public static function attach(int $address): self
    {
        if ($address === 0) {
            throw new \LogicException('cannot attach to a null context address');
        }

        return new self(Binding::current(), $address, false);
    }

    /**
     * The context's address, as an integer a thread bootstrap argument can
     * carry: `Swoole\Thread` takes scalars, never objects or resources.
     */
    public function address(): int
    {
        return $this->live();
    }

    /** Whether this handle created the context and therefore frees it. */
    public function ownsContext(): bool
    {
        return $this->owned;
    }

    /** The schema version the loaded library speaks. */
    public function coreSchemaVersion(): int
    {
        return (int) $this->ffi->flz_core_schema_version();
    }

    /** Register one relay class from its `HostClassDecl` JSON. */
    public function declareClass(string $json): void
    {
        $this->check($this->ffi->flz_core_declare_class($this->live(), $json, strlen($json)));
    }

    /** Undeclare one class; after start, refused while it has live instances. */
    public function undeclareClass(string $factoryId): void
    {
        $this->check($this->ffi->flz_core_undeclare_class($this->live(), $factoryId));
    }

    /**
     * Judge one class declaration without registering it, and answer the
     * DERIVED declaration as JSON: the consumers, channel kinds, output names
     * and acknowledgement granularity the core read off the record's chains.
     *
     * Read-only, so a caller asks for the verdict its author would get with
     * nothing registered and no runner. A refused record throws the core's
     * {@see FlezhaException}: its code is the first issue's, and
     * `details['issues']` lists every issue as `{code, message, details}`.
     */
    public function checkClass(string $json): string
    {
        $answer = null;
        $status = $this->outBuffer(
            fn (CData $out, CData $len): int => $this->ffi->flz_core_check_class(
                $this->live(),
                $json,
                strlen($json),
                FFI::addr($out),
                FFI::addr($len),
            ),
            $answer,
        );
        // `flz_core_check_class` answers FLZ_OK, not the poll status.
        $this->check($status);

        return (string) $answer;
    }

    /** Connect to the controller and start the runner loop. */
    public function start(string $namespace): void
    {
        $this->check($this->ffi->flz_core_start($this->live(), $namespace));
    }

    /**
     * Start the declared class as a local run: no controller, no socket. The
     * alternative to {@see start()}, never an addition.
     */
    public function localStart(string $factoryId, string $relayId): void
    {
        $this->check($this->ffi->flz_core_local_start($this->live(), $factoryId, $relayId));
    }

    /** Queue a declared output for {@see localNextOutput()}. */
    public function localOnSink(string $channel): void
    {
        $this->check($this->ffi->flz_core_local_on_sink($this->live(), $channel));
    }

    /** Feed one value's wire bytes to a declared entry channel. */
    public function localFeed(string $channel, string $payload): void
    {
        $this->check($this->ffi->flz_core_local_feed($this->live(), $channel, $payload, strlen($payload)));
    }

    /**
     * The next value an output released, in `poll`'s three-way shape: the encoded
     * `Output` lands in `$bytes` on {@see Binding::POLL_ACTIVATION}.
     */
    public function localNextOutput(int $timeoutMs, ?string &$bytes): int
    {
        $released = null;
        $status = $this->outBuffer(
            fn (CData $out, CData $len): int => $this->ffi->flz_core_local_next_output(
                $this->live(),
                $timeoutMs,
                FFI::addr($out),
                FFI::addr($len),
            ),
            $released,
        );
        $bytes = $released;
        if ($status === Binding::ERR) {
            $this->check($status);
        }

        return $status;
    }

    /** Park until the graph is idle; a timeout is a refusal. */
    public function localRunUntilIdle(int $timeoutMs): void
    {
        $this->check($this->ffi->flz_core_local_run_until_idle($this->live(), $timeoutMs));
    }

    /** End the local run and release its Cells. */
    public function localStop(): void
    {
        $this->check($this->ffi->flz_core_local_stop($this->live()));
    }

    /**
     * Block for up to `$timeoutMs` for one activation.
     *
     * Answers the ABI's own three-way status; the encoded activation lands in
     * `$bytes` on {@see Binding::POLL_ACTIVATION}. Shaped like the C call
     * rather than wrapped, so the one place the three answers are interpreted
     * is the loop.
     */
    public function poll(int $timeoutMs, ?string &$bytes): int
    {
        $polled = null;
        $status = $this->outBuffer(
            fn (CData $out, CData $len): int => $this->ffi->flz_core_poll(
                $this->live(),
                $timeoutMs,
                FFI::addr($out),
                FFI::addr($len),
            ),
            $polled,
        );
        $bytes = $polled;
        if ($status === Binding::ERR) {
            $this->check($status);
        }

        return $status;
    }

    /**
     * Block until the core observes starvation: work queued with no thread
     * inside `poll`. Answers in `poll`'s three-way shape.
     */
    public function waitStarvation(int $timeoutMs): int
    {
        return $this->ffi->flz_core_wait_starvation($this->live(), $timeoutMs);
    }

    /** Complete an activation. Accepted from any thread. */
    public function complete(string $encoded): void
    {
        $this->check($this->ffi->flz_core_complete($this->live(), $encoded, strlen($encoded)));
    }

    /** Re-hand a polled activation's untouched items and release its cell. */
    public function abandon(int $token): void
    {
        $this->check($this->ffi->flz_core_abandon($this->live(), $token));
    }

    /** Ship every Cell's state to the controller, then wake every blocked poller and stop the runner loop. */
    public function shutdown(): void
    {
        $this->ffi->flz_core_shutdown($this->live());
    }

    /**
     * Ask the core to watch standard input and end this process on end of file.
     *
     * The watch is the core's, on a thread of its own, which is why a runner
     * with no second thread of its own still observes a harness that stops it
     * by closing its pipes — even from inside an activation body.
     */
    public function watchStdinEof(): void
    {
        $this->live();
        $this->ffi->flz_core_watch_stdin_eof();
    }

    public function spawnRequest(
        int $token,
        string $factoryId,
        string $id,
        string $payload,
        string $ifRunning,
        bool $replay,
        ?int $offset,
    ): int {
        $handle = $this->ffi->new('uint64_t');
        $this->check($this->ffi->flz_core_spawn_request(
            $this->live(),
            $token,
            $factoryId,
            $id,
            $payload,
            strlen($payload),
            $ifRunning,
            $replay ? 1 : 0,
            $offset ?? -1,
            FFI::addr($handle),
        ));

        return (int) $handle->cdata;
    }

    public function part(int $token, int $item, string $payload): void
    {
        $this->check($this->ffi->flz_core_part($this->live(), $token, $item, $payload, strlen($payload)));
    }

    public function effectBegin(int $token, int $item, string $name): array
    {
        $kept = null;
        $status = $this->outBuffer(
            fn (CData $out, CData $len): int => $this->ffi->flz_core_effect_begin(
                $this->live(),
                $token,
                $item,
                $name,
                FFI::addr($out),
                FFI::addr($len),
            ),
            $kept,
        );
        $this->check($status);

        return [match ($status) {
            Binding::EFFECT_KEPT => 'kept',
            Binding::EFFECT_UNRECORDED => 'unrecorded',
            default => 'run',
        }, $kept];
    }

    public function effectEnd(int $token, int $item, string $payload): ?string
    {
        $first = null;
        // The function's own result keeps no buffer, which leaves `$first` null.
        $this->check($this->outBuffer(
            fn (CData $out, CData $len): int => $this->ffi->flz_core_effect_end(
                $this->live(),
                $token,
                $item,
                $payload,
                strlen($payload),
                FFI::addr($out),
                FFI::addr($len),
            ),
            $first,
        ));

        return $first;
    }

    public function effectRelease(int $token, int $item): void
    {
        $this->check($this->ffi->flz_core_effect_release($this->live(), $token, $item));
    }

    /**
     * The handle's answer as JSON, or null while it is pending. A spawn that
     * reached no instance throws its {@see FlezhaException}: the controller's
     * code, `spawn.timeout` or `spawn.unavailable`, or
     * `runtime.spawn_handle_released` for a handle already released.
     */
    public function spawnResult(int $handle, int $timeoutMs): ?string
    {
        $answer = null;
        // Pending hands out no buffer, which leaves `$answer` null.
        $this->check($this->outBuffer(
            fn (CData $out, CData $len): int => $this->ffi->flz_core_spawn_result(
                $this->live(),
                $handle,
                $timeoutMs,
                FFI::addr($out),
                FFI::addr($len),
            ),
            $answer,
        ));

        return $answer;
    }

    /**
     * Administer the namespace this connection proved: one entry for the whole
     * surface, because the verb is data. Answers the published function's own
     * result as JSON.
     */
    public function adminCall(string $verb, string $args): string
    {
        $result = null;
        $this->check($this->outBuffer(
            fn (CData $out, CData $len): int => $this->ffi->flz_core_admin_call(
                $this->live(),
                $verb,
                $args,
                FFI::addr($out),
                FFI::addr($len),
            ),
            $result,
        ));

        return (string) $result;
    }

    /**
     * Send one message, through the controller or locally, answering as JSON:
     * the verdict, or `null` for a local send. A null id or key is none.
     */
    public function sendMessage(
        string $relay,
        string $channel,
        string $message,
        ?string $msgId,
        ?string $key,
        bool $local,
    ): string {
        $result = null;
        $this->check($this->outBuffer(
            fn (CData $out, CData $len): int => $this->ffi->flz_core_send_message(
                $this->live(),
                $relay,
                $channel,
                $message,
                strlen($message),
                $msgId ?? '',
                $key ?? '',
                (int) $local,
                FFI::addr($out),
                FFI::addr($len),
            ),
            $result,
        ));

        return (string) $result;
    }

    /**
     * The ids of the relay instances bound on this runner.
     *
     * @return list<string>
     */
    public function relays(): array
    {
        $ids = null;
        $this->check($this->outBuffer(
            fn (CData $out, CData $len): int => $this->ffi->flz_core_relays(
                $this->live(),
                FFI::addr($out),
                FFI::addr($len),
            ),
            $ids,
        ));

        return array_values(json_decode((string) $ids, true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * Write the core's notices held since the last take through the SDK's
     * logger. Context-free; the first take starts the holding.
     */
    public function drainNotices(): void
    {
        $taken = null;
        $this->outBuffer(
            fn (CData $out, CData $len): int => $this->ffi->flz_core_take_notices(FFI::addr($out), FFI::addr($len)),
            $taken,
        );
        Logger::current()->notices(json_decode((string) $taken, true, 512, JSON_THROW_ON_ERROR) ?? []);
    }

    /** The core's monotonic clock, in nanoseconds since a process origin. */
    public function monotonicNanos(): int
    {
        return (int) $this->ffi->flz_core_monotonic_nanos();
    }

    /**
     * Release the context. A no-op on an attached handle, which is what makes
     * the ownership rule structural rather than a caller's discipline.
     */
    public function free(): void
    {
        $ctx = $this->ctx;
        $this->ctx = null;
        if ($ctx !== null && $this->owned) {
            $this->ffi->flz_core_context_free($ctx);
        }
    }

    /**
     * Call an entry point that hands back a core-allocated buffer, and take it.
     *
     * One place, because every such entry has the same obligation and the same
     * shape: the core allocated the buffer and the core frees it, so forgetting
     * the free leaks per activation rather than visibly, and every one of them
     * writes through a `char **` / `size_t *` pair the caller must own.
     *
     * Taken whenever the core wrote one, never only on the status that usually
     * carries it: a buffer a caller does not take is a buffer nobody frees.
     *
     * @param callable(CData, CData): int $call  fills the pair; answers the status
     * @param string|null                 $bytes the buffer, when there was one
     */
    private function outBuffer(callable $call, ?string &$bytes): int
    {
        $out = $this->ffi->new('char*');
        $len = $this->ffi->new('size_t');
        $bytes = null;

        $status = $call($out, $len);
        if (FFI::isNull($out)) {
            return $status;
        }

        $length = (int) $len->cdata;
        // `FFI::string` reads through the `char *` itself; the CData IS the
        // pointer, and a `->cdata` on it is a read of a field a pointer has no
        // more than a struct does.
        $bytes = $length === 0 ? '' : FFI::string($out, $length);
        $this->ffi->flz_core_buf_free($out, $length);

        return $status;
    }

    /** The context, refusing a call on a freed one rather than segfaulting. */
    private function live(): int
    {
        if ($this->ctx === null) {
            throw new \LogicException('the flezha core context has been freed; this call is a defect');
        }

        return $this->ctx;
    }

    /** Turn an ABI failure into the reason the core recorded on the context. */
    private function check(int $status): void
    {
        if ($status !== Binding::ERR) {
            return;
        }
        throw FlezhaException::fromDocument(
            self::cstring($this->ffi->flz_core_last_error($this->live()))
                ?? throw new \LogicException('the flezha core refused a call and recorded no reason'),
        );
    }

    /** The refusal a context-free call left on this thread. */
    private static function initRefusal(FFI $ffi): FlezhaException
    {
        return FlezhaException::fromDocument(
            self::cstring($ffi->flz_core_init_error())
                ?? throw new \LogicException('flz_core refused a call and reported no reason'),
        );
    }

    /**
     * A borrowed `const char *` as a PHP string, or null when there is none.
     *
     * PHP's FFI converts a `char *` return to a string on its own, so what
     * arrives is a string, a null, or — on a build that hands back the pointer
     * — `CData`. One reader for all three, because getting it wrong turns
     * every core refusal into a `TypeError` that hides the refusal.
     */
    private static function cstring(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value === '' ? null : $value;
        }
        if ($value instanceof CData && !FFI::isNull($value)) {
            return FFI::string($value);
        }

        return null;
    }
}

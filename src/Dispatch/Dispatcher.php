<?php

declare(strict_types=1);

namespace Flezha\Dispatch;

use Flezha\Codec\ActivationCodec;
use Flezha\Codec\Json;
use Flezha\Ctx;
use Flezha\Exception\FlezhaException;
use Flezha\Ffi\Core;
use Flezha\Graph\ChannelSpec;
use Flezha\Graph\ConsumerEdge;
use Flezha\Graph\TaskNode;
use Flezha\Logger;
use Flezha\SpawnBridge;
use Flezha\TimerFiring;
use Flezha\Transforms;
use Throwable;

/**
 * Turning one activation into one answer, exactly once.
 *
 * Per-cell exclusivity IS the handout: the core holds a cell's next
 * activation until this one is answered, so an activation that returns
 * unanswered retires that cell for the life of the process. Every path out
 * of {@see dispatch()} therefore ends in a completion or an abandon, through a
 * `finally` rather than a rule applied at each return site — a throwing
 * handler, an encoding failure and a killed worker all reach the same guard.
 */
final class Dispatcher
{
    public function __construct(
        private readonly Registry $registry,
        private readonly Core $core,
    ) {
    }

    /**
     * Dispatch one activation and answer it exactly once.
     *
     * The guard is the contract, not politeness: a throw from the dispatcher,
     * from encoding the result, or from `complete` itself abandons rather than
     * logging and returning.
     */
    public function dispatch(string $encoded): void
    {
        $token = null;
        $answered = false;
        try {
            $activation = ActivationCodec::decodeActivation($encoded);
            $token = $activation['token'];
            $this->core->complete(ActivationCodec::encodeResult($this->answer($activation)));
            $answered = true;
        } catch (Throwable $e) {
            Logger::current()->error('activation processing failed: ' . self::message($e));
        } finally {
            if (!$answered && $token !== null) {
                try {
                    $this->core->abandon($token);
                } catch (Throwable $e) {
                    // The core refuses an unknown token: `complete` had
                    // already landed and only the bookkeeping after it threw.
                    // Nothing is wedged.
                    Logger::current()->warn(
                        "abandoning activation $token failed: " . self::message($e),
                    );
                }
            }
        }
    }

    /**
     * Produce the answer for one activation.
     *
     * Throwing is not an option here: every failure becomes a result the core
     * can act on, because the alternative is a cell that never receives
     * work again.
     *
     * @param array<string, mixed> $activation
     * @return array<string, mixed>
     */
    public function answer(array $activation): array
    {
        try {
            $registered = $this->registry->get((string) $activation['factory']);
            if ($registered === null) {
                return self::failed($activation['token'], sprintf(
                    'no class registered for factory `%s`',
                    $activation['factory'],
                ), true);
            }

            $node = $registered->spec->tasklets[$activation['tasklet']] ?? null;
            if ($node === null) {
                return self::failed($activation['token'], sprintf(
                    'class `%s` has no tasklet at index %d',
                    $activation['factory'],
                    $activation['tasklet'],
                ), true);
            }
            if (isset($activation['cell_identity'])) {
                $registered->keepIdentity($node, $activation['relay'], $activation['cell_key'], $activation['cell_identity']);
            }

            return match ($activation['kind']) {
                'work' => $this->runWork($activation, $registered, $node),
                'restore_fold' => $this->restoreFold($activation, $registered, $node),
                'snapshot_request', 'live_state_read' => $this->serializedState($activation, $registered, $node),
                'apply_state' => $this->applyState($activation, $registered, $node),
                'key_request' => $this->prepare($activation, $registered),
                'compensate_run' => $this->compensate($activation, $registered, $node),
                'leave' => $this->leave($activation, $registered, $node),
                default => self::failed($activation['token'], sprintf(
                    'unknown activation kind `%s`',
                    $activation['kind'],
                ), true),
            };
        } catch (Throwable $e) {
            return self::failed($activation['token'], self::message($e), self::nonRetryable($e));
        }
    }

    /**
     * Run user code against one cell, and keep the state it produced.
     *
     * Every activation kind that reaches a handler goes through here — an
     * input, a timer firing, a compensation — so the context they receive and
     * the state they leave behind cannot differ between them. The state is
     * written back whether or not the body threw: a change recorded before the
     * throw is already journaled, and dropping it here would make the live
     * cell disagree with its own log.
     *
     * @param array<string, mixed> $activation
     */
    public static function invoke(
        array $activation,
        RegisteredClass $registered,
        TaskNode $node,
        SpawnBridge $bridge,
        int $now,
        bool $replay,
        callable $body,
        ?int $offset = null,
        ?int $item = null,
    ): Ctx {
        $ctx = new Ctx(
            spec: $registered->spec,
            node: $node,
            outgoing: $registered->outgoing[$node->index],
            registered: $registered,
            relay: (string) $activation['relay'],
            cellKey: $activation['cell_key'],
            changeSeq: (int) $activation['change_seq'],
            now: $now,
            replay: $replay,
            state: $registered->stateOf($node, (string) $activation['relay'], $activation['cell_key']),
            bridge: $bridge,
            token: (int) $activation['token'],
            offset: $offset,
            compensating: ($activation['kind'] ?? null) === 'compensate_run',
            item: $item,
        );

        try {
            $body($ctx);
        } finally {
            $registered->setState($node, (string) $activation['relay'], $activation['cell_key'], $ctx->state);
        }

        return $ctx;
    }

    /**
     * @param array<string, mixed> $activation
     * @return array<string, mixed>
     */
    private function runWork(array $activation, RegisteredClass $registered, TaskNode $node): array
    {
        $effects = [];

        foreach (array_values($activation['items']) as $index => $item) {
            $effect = $this->runItem($activation, $registered, $node, $item, $index);
            $effects[] = $effect;

            if ($effect['verdict'] !== 'ok') {
                // Items after a failure are re-handed by the core, so the
                // batch stops here rather than reporting effects the cell
                // never saw.
                break;
            }
        }

        return ['token' => $activation['token'], 'outcome' => 'work', 'effects' => $effects];
    }

    /**
     * One work item, and its effect however it ended.
     *
     * The `try` is the whole reason this is its own method: an item that
     * throws must become a verdict the core's ladder can act on, and a `catch`
     * wrapped around the loop instead would lose the effects of the items that
     * already succeeded.
     *
     * @param array<string, mixed> $activation
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function runItem(
        array $activation,
        RegisteredClass $registered,
        TaskNode $node,
        array $item,
        int $index,
    ): array {
        $now = (int) ($item['now'] ?? $item['scheduled_at'] ?? 0);

        try {
            $ctx = self::invoke(
                $activation,
                $registered,
                $node,
                $this->core,
                $now,
                (bool) ($item['replay'] ?? false),
                fn (Ctx $ctx) => $this->serve($ctx, $registered, $node, $item, $now),
                $item['consumed_offset'] ?? null,
                $index,
            );

            return $ctx->effectRecord($item['consumed_offset'] ?? null);
        } catch (Throwable $e) {
            // The backtrace is a host diagnostic, not a record: the verdict
            // below travels to the dead-letter entry and the controller's log.
            Logger::current()->debug(sprintf(
                "%s failed an item: %s\n%s",
                $node->name,
                self::message($e),
                $e->getTraceAsString(),
            ));

            return [
                ...self::failureOf($e),
                'event_time' => $now,
                'consumed_offset' => $item['consumed_offset'] ?? null,
            ];
        }
    }

    /**
     * A failed item's verdict and the message the wire carries: `unreadable`,
     * reported as its cause, when the entrance could not read the payload,
     * else what the handler's own exception says.
     *
     * @return array{verdict: string, message: string}
     */
    private static function failureOf(Throwable $e): array
    {
        if ($e instanceof UnreadablePayload) {
            return ['verdict' => 'unreadable', 'message' => $e->getMessage()];
        }

        return [
            'verdict' => self::nonRetryable($e) ? 'non_retryable' : 'retryable',
            'message' => self::message($e),
        ];
    }

    /**
     * Hand one item to user code: a timer firing, or a decoded input.
     *
     * @param array<string, mixed> $item
     */
    private function serve(Ctx $ctx, RegisteredClass $registered, TaskNode $node, array $item, int $now): void
    {
        $definition = $node->definition;

        if ($item['kind'] === 'timer') {
            if (!$definition->declares('onTimer')) {
                // Registration is a runtime call, so this cannot be refused at
                // declaration time.
                throw new FlezhaException('runtime.timer_without_on_timer', ['tasklet' => $node->name]);
            }
            $definition->instance->onTimer(new TimerFiring((string) $item['timer_kind'], $now), $ctx);

            return;
        }

        // A filtered value is consumed and reaches no handler. It still
        // produces an effect, so its offset advances and nothing is retried.
        // A fanned one reaches the handler once per value. The prepare crossing
        // decoded a keyed payload and ran this node's edge already, so each of
        // its values comes encoded as a value between tasklets.
        $values = $item['form'] === 'prepared'
            ? array_map(Json::decode(...), $item['values'])
            : self::entryValues($registered->spec->channels[$item['channel']] ?? null, $node, $item);
        foreach ($values as $input) {
            $definition->instance->run($input, $ctx);
        }
    }

    /**
     * @param array<string, mixed> $activation
     * @return array<string, mixed>
     */
    private function restoreFold(array $activation, RegisteredClass $registered, TaskNode $node): array
    {
        $registered->setState($node, (string) $activation['relay'], $activation['cell_key'], Restore::fold(
            $node->definition,
            ['snapshot' => $activation['snapshot'], 'changes' => $activation['changes']],
            $activation['cell_key'],
            $node->name,
        ));

        return ['token' => $activation['token'], 'outcome' => 'restored'];
    }

    /**
     * @param array<string, mixed> $activation
     * @return array<string, mixed>
     */
    private function leave(array $activation, RegisteredClass $registered, TaskNode $node): array
    {
        $registered->forget(
            $node,
            (string) $activation['relay'],
            $activation['cell_key'],
            (bool) $activation['whole_relay'],
        );

        return ['token' => $activation['token'], 'outcome' => 'state_applied'];
    }

    /**
     * @param array<string, mixed> $activation
     * @return array<string, mixed>
     */
    private function applyState(array $activation, RegisteredClass $registered, TaskNode $node): array
    {
        $registered->setState(
            $node,
            (string) $activation['relay'],
            $activation['cell_key'],
            $node->definition->decode('state', (string) $activation['state_json']),
        );

        return ['token' => $activation['token'], 'outcome' => 'state_applied'];
    }

    /**
     * @param array<string, mixed> $activation
     * @return array<string, mixed>
     */
    private function prepare(array $activation, RegisteredClass $registered): array
    {
        $request = $activation['key_request'];
        $channel = $registered->spec->channels[$request['channel']] ?? null;
        $answers = [];
        foreach ($request['payloads'] as $payload) {
            try {
                $answers[] = self::prepared($registered, $channel, $request, self::decoded($channel, (string) $payload));
            } catch (Throwable $e) {
                // A per-payload refusal enters the input channel's retry
                // ladder; it is not a failure of the crossing, whose other
                // payloads still have answers.
                $answers[] = ['error' => self::message($e), 'non_retryable' => self::nonRetryable($e)];
            }
        }

        return ['token' => $activation['token'], 'outcome' => 'prepared', 'prepared' => $answers];
    }

    /**
     * @param array<string, mixed> $activation
     * @return array<string, mixed>
     */
    private function compensate(array $activation, RegisteredClass $registered, TaskNode $node): array
    {
        $definition = $node->definition;
        if (!$definition->declares('compensate')) {
            return self::failed($activation['token'], "$node->name declares no compensate hook", true);
        }

        $outputs = array_map(
            static fn (string $bytes): mixed => Json::decode($bytes),
            $activation['outputs'],
        );
        $ctx = self::invoke(
            $activation,
            $registered,
            $node,
            $this->core,
            0,
            false,
            static fn (Ctx $ctx) => $definition->instance->compensate($outputs, $ctx),
        );

        return [
            'token' => $activation['token'],
            'outcome' => 'work',
            'effects' => [$ctx->effectRecord(null)],
        ];
    }

    /**
     * The two kinds that answer "the state, encoded".
     *
     * One arm, because they differ only in the field the core reads the bytes
     * out of: this codec IS JSON, so a compaction snapshot and a live read (or
     * a migration handover) carry the same bytes. There is no second encoding to
     * convert between — the one place this host is simpler than a MessagePack
     * one rather than merely different.
     *
     * @param array<string, mixed> $activation
     * @return array<string, mixed>
     */
    private function serializedState(array $activation, RegisteredClass $registered, TaskNode $node): array
    {
        [$outcome, $field] = $activation['kind'] === 'snapshot_request'
            ? ['snapshot', 'state']
            : ['state_json', 'json'];

        return [
            'token' => $activation['token'],
            'outcome' => $outcome,
            $field => $node->definition->encode(
                'state',
                $registered->stateOf($node, (string) $activation['relay'], $activation['cell_key']),
            ),
        ];
    }

    /**
     * One decoded payload's answer: every value each keyed consumer's own edge
     * yields, with the Cells it names, and the event time the first timed
     * consumer reads off its first value. A payload its chains filter answers
     * no value, so the core mints no Cell for it.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private static function prepared(RegisteredClass $registered, ?ChannelSpec $channel, array $request, mixed $value): array
    {
        $values = [];
        $eventTime = null;
        foreach ($channel?->consumers ?? [] as $edge) {
            $definition = $registered->spec->tasklets[$edge->tasklet]->definition;
            $keyed = $request['want_keys'] && ($edge->keyBy !== null || $definition->declares('cellsFor'));
            $timed = $request['want_event_time'] && $eventTime === null && $definition->declares('eventTime');
            if (!$keyed && !$timed) {
                continue;
            }
            $yielded = Transforms::apply($edge->transforms, $value);
            if ($timed && $yielded !== []) {
                $eventTime = $definition->eventTimeOf($yielded[0]);
            }
            foreach ($keyed ? $yielded : [] as $each) {
                $keys = $edge->keyBy !== null ? [(string) ($edge->keyBy)($each)] : $definition->cellsFor($each);
                $values[] = ['consumer' => $edge->tasklet, 'keys' => $keys, 'bytes' => Json::encode($each)];
            }
        }

        return ['values' => $values, 'event_time' => $eventTime];
    }

    /**
     * A delivered payload as the handler sees it: decoded, then through this
     * node's own edge off the channel when the core marks it untransformed. A
     * flow producer already ran that edge, and running it again would compose
     * the chain with itself. A failure on an entry channel (the wire's bytes,
     * not a producer's) is the entrance's, and is reported as such rather than
     * as the handler's.
     *
     * @param array<string, mixed> $item
     * @return list<mixed>
     */
    private static function entryValues(?ChannelSpec $channel, TaskNode $node, array $item): array
    {
        $edge = $item['form'] === 'untransformed' ? self::edgeInto($channel, $node->index) : null;
        try {
            return Transforms::apply($edge?->transforms ?? [], self::decoded($channel, (string) $item['payload']));
        } catch (Throwable $e) {
            if ($channel === null || $channel->kind === 'entry') {
                throw new UnreadablePayload(self::message($e), $e);
            }

            throw $e;
        }
    }

    /**
     * A payload as its channel reads it: the entry channel's decoder, or JSON
     * for a payload between tasklets. The one decoder for an input item and
     * for a payload at the prepare crossing.
     */
    private static function decoded(?ChannelSpec $channel, string $payload): mixed
    {
        return $channel?->decode !== null ? ($channel->decode)($payload) : Json::decode($payload);
    }

    /** The channel's edge that feeds `$node`, or null when it feeds none. */
    private static function edgeInto(?ChannelSpec $channel, int $node): ?ConsumerEdge
    {
        foreach ($channel?->consumers ?? [] as $edge) {
            if ($edge->tasklet === $node) {
                return $edge;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function failed(int $token, string $message, bool $nonRetryable): array
    {
        return [
            'token' => $token,
            'outcome' => 'failed',
            'error' => $message,
            'non_retryable' => $nonRetryable,
        ];
    }

    /**
     * What a failure says on the wire.
     *
     * `Class: message`, and the code after a refusal's, in family with what
     * the other SDKs send — this string is stored in a dead-letter entry and
     * read by a user, and a multi-line backtrace there is noise the retry
     * ladder multiplies.
     */
    private static function message(Throwable $e): string
    {
        $code = $e instanceof FlezhaException ? " ({$e->getCode()})" : '';

        return $e::class . ': ' . $e->getMessage() . $code;
    }

    /**
     * A handler declares an error non-retryable by answering `isNonRetryable`,
     * so any exception class can carry the verdict rather than only this SDK's.
     */
    private static function nonRetryable(Throwable $e): bool
    {
        return method_exists($e, 'isNonRetryable') && $e->isNonRetryable();
    }
}

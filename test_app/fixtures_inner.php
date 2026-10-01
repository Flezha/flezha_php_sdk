<?php

declare(strict_types=1);

/**
 * One stepped session per key inside a flow gateway, the mirror of
 * `sdk/rust/test_app/src/inner.rs`: `key:text` inputs; each key's session
 * counts its turns in its own state and answers `key#turns:text` on the
 * gateway's `replies` sink. The session sleeps after
 * `FLEZHA_FIXTURE_SLEEP_AFTER_MS` and wakes on its key's next message with its
 * count intact, while the gateway and every other key go on. Its second stage
 * fails on the text `fail` (the turn is undone) and holds `hold` until the
 * release env (a crash lands between the two stages); the gateway's reply holds
 * `stall` the same way (a crash lands after the session answered).
 *
 * `loop_gateway` embeds the chained `countdown`: `label:n` counts down one per
 * pass, and only the converged `label:0` reaches the gateway's `replies`.
 */

namespace Flezha\TestApp;

use Flezha\Attribute\Operator;
use Flezha\Attribute\Task;
use Flezha\ChannelCodec;
use Flezha\Ctx;
use Flezha\Graph\Builder;
use Flezha\Tasklet;

#[Task(name: 'session_turn')]
final class SessionTurn extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['value' => 0];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['value'] += $change['delta'];
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        [$key, $text] = explode(':', (string) $input, 2) + [1 => ''];
        $ctx->change(['delta' => 1]);
        $ctx->emit("{$key}#{$ctx->state['value']}:{$text}");
    }

    public function compensate(array $outputs, Ctx $ctx): void
    {
        $ctx->change(['delta' => -1]);
    }
}

#[Task(name: 'session_check')]
final class SessionCheck extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $text = explode(':', (string) $input, 2)[1] ?? '';
        if ($text === 'fail') {
            throw new \RuntimeException('session_check refuses fail');
        }
        if ($text === 'hold') {
            StepsGate::blockUntilReleased();
        }
        $ctx->emit($input);
    }
}

#[Operator(factoryId: 'session', run: ['steps' => ['each' => 'per_input']], durable: FIXTURE_DURABLE)]
final class SessionSteps extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        // A host tasklet emits only on a channel it declares: this one is the exit.
        $c->input('turns', codec: ChannelCodec::Text)
            ->into(SessionTurn::class)
            ->into(SessionCheck::class)
            ->named('answer');
    }
}

#[Task(name: 'gateway_reply')]
final class GatewayReply extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        if ((explode(':', (string) $input, 2)[1] ?? '') === 'stall') {
            StepsGate::blockUntilReleased();
        }
        $ctx->emit($input);
    }
}

// It journals its input (a kept instance that may sleep), so an answer a session
// passed it is replayed after a crash.
#[Operator(factoryId: 'session_gateway', durable: ['local' => ['sleep_after' => 3_600_000]])]
final class GatewayOperator extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('requests', codec: ChannelCodec::Text)
            ->keyBy(Wire::prefix(...))
            ->into(SessionSteps::class)
            ->into(GatewayReply::class)
            ->sink('replies', codec: ChannelCodec::Text);
    }
}

#[Task(name: 'countdown_step')]
final class CountdownStep extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        [$label, $n] = explode(':', (string) $input, 2) + [1 => '0'];
        if ((int) $n > 0) {
            $ctx->emit($label . ':' . ((int) $n - 1));
        }
    }
}

#[Operator(factoryId: 'countdown', run: ['steps' => ['each' => 'chained']])]
final class CountdownSteps extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)->into(CountdownStep::class)->named('carry');
    }
}

#[Operator(factoryId: 'loop_gateway')]
final class LoopGatewayOperator extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('requests', codec: ChannelCodec::Text)
            ->into(CountdownSteps::class)
            ->into(GatewayReply::class)
            ->sink('replies', codec: ChannelCodec::Text);
    }
}

final class InnerFixtures
{
    /**
     * The embedded classes are not registered on their own.
     *
     * @return list<class-string<\Flezha\Operator>>
     */
    public static function all(): array
    {
        return [GatewayOperator::class, LoopGatewayOperator::class];
    }
}

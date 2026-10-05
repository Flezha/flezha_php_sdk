<?php

declare(strict_types=1);

/**
 * Twins of the reference's relay call fixtures
 * (sdk/rust/test_app/src/relay_call.rs), under the same factory ids,
 * tasklet names, channel names and payloads: relays that call another
 * relay from their graph and read the outcome as a `Flezha\Response`.
 *
 * - `call_pricing`: a flow callee answering `price:<input>` on its sink
 *   `quotes`; an input starting `silent` is never answered.
 * - `call_orders`: a flow caller calling the instance `pricing`, answered from
 *   `quotes`.
 * - `call_agent`: a durable per-input keep-progress session answering
 *   `answer:<turn>`; `boom` fails the turn, `slow` answers after 2 s.
 * - `call_gateway`: an effectively-once caller calling `call_agent` by the
 *   session key of each `<session>:<text>` turn, and without creating it for a
 *   `resume:<session>:<text>` turn, both Response channels wired back into
 *   its calling tasklet.
 * - `call_tool_session`: a durable per-input session calling `pricing` from a
 *   turn and reading the outcome as its next turn.
 *
 * Every outcome is written to a sink as one line,
 * `<request>=ok:<answer>@<target>/<created>` or
 * `<request>=error:<code>@<target>/<created>`.
 */

namespace Flezha\TestApp;

use Flezha\Attribute\Relay;
use Flezha\Attribute\Task;
use Flezha\ChannelCodec;
use Flezha\Ctx;
use Flezha\Exception\NonRetryableException;
use Flezha\Graph\Builder;
use Flezha\Response;
use Flezha\Tasklet;

final class RelayCall
{
    public const DEADLINE_MS = 30_000;

    /**
     * The prefix a caller's tasklet puts on an outcome line, which no request
     * carries, so one output channel can feed both the call and the sink.
     */
    public const OUTCOME = 'response ';

    /**
     * The prefix of a turn resuming a session that must already run: its call
     * may not create the callee.
     */
    public const RESUME = 'resume:';

    public static function render(Response $response): string
    {
        $outcome = $response->ok() ? "ok:{$response->value}" : "error:{$response->error?->getCode()}";
        $created = $response->created ? 'true' : 'false';

        return "{$response->request}={$outcome}@{$response->target}/{$created}";
    }

    /** What a caller's tasklet emits: a turn as it came, an outcome as its line. */
    public static function pass(mixed $input): string
    {
        return $input instanceof Response ? self::OUTCOME . self::render($input) : (string) $input;
    }

    public static function isOutcome(mixed $line): bool
    {
        return str_starts_with((string) $line, self::OUTCOME);
    }

    public static function isResume(mixed $line): bool
    {
        return str_starts_with((string) $line, self::RESUME);
    }

    public static function isTurn(mixed $line): bool
    {
        return !self::isOutcome($line) && !self::isResume($line);
    }

    public static function unprefixed(mixed $line): string
    {
        return substr((string) $line, strlen(self::OUTCOME));
    }

    /** The session a turn or an outcome line belongs to: what precedes its `:`. */
    public static function session(mixed $line): string
    {
        $line = (string) $line;
        $line = self::isOutcome($line) ? self::unprefixed($line) : $line;

        return explode(':', $line, 2)[0];
    }
}

#[Task(name: 'pricing_quote')]
final class PricingQuote extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        if (!str_starts_with($input, 'silent')) {
            $ctx->emit("price:$input");
        }
    }
}

#[Relay(factoryId: 'call_pricing')]
final class CallPricing extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)->into(PricingQuote::class)->sink('quotes');
    }
}

#[Task(name: 'order_ask')]
final class OrderAsk extends PassThrough
{
}

#[Task(name: 'order_priced')]
final class OrderPriced extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit(RelayCall::render($input));
    }
}

#[Relay(factoryId: 'call_orders')]
final class CallOrders extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(OrderAsk::class)
            ->named('order')
            ->call(
                'pricing',
                deadlineMs: RelayCall::DEADLINE_MS,
                answerChannel: 'quotes',
                codec: ChannelCodec::Text,
            )
            ->into(OrderPriced::class)
            ->sink('priced');
    }
}

#[Task(name: 'agent_turn')]
final class AgentTurn extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $text = str_contains($input, ':') ? explode(':', $input, 2)[1] : $input;
        if ($text === 'boom') {
            throw new NonRetryableException('the turn failed');
        }
        if ($text === 'slow') {
            sleep(2);
        }
        $ctx->emit("answer:$input");
    }
}

#[Relay(
    factoryId: 'call_agent',
    run: ['steps' => ['each' => 'per_input', 'on_failure' => 'keep_progress']],
    durable: FIXTURE_DURABLE,
    maxDurationMs: 600_000,
)]
final class CallAgent extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('turn', codec: ChannelCodec::Text)->into(AgentTurn::class)->sink();
    }
}

#[Task(name: 'gateway_account')]
final class GatewayAccount extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit(RelayCall::pass($input));
    }
}

#[Task(name: 'gateway_resume')]
final class GatewayResume extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit(substr((string) $input, strlen(RelayCall::RESUME)));
    }
}

#[Task(name: 'gateway_log')]
final class GatewayLog extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit(RelayCall::unprefixed($input));
    }
}

#[Relay(factoryId: 'call_gateway', run: ['flow' => ['exactly' => 'effectively_once']])]
final class CallGateway extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $account = $c->input('in', codec: ChannelCodec::Text)->into(GatewayAccount::class)->named('gateway');
        $account->filter(RelayCall::isTurn(...))
            ->keyBy(RelayCall::session(...))
            ->call(factory: 'call_agent', deadlineMs: RelayCall::DEADLINE_MS, codec: ChannelCodec::Text)
            ->into(GatewayAccount::class);
        $account->filter(RelayCall::isResume(...))
            ->into(GatewayResume::class)
            ->named('resume')
            ->keyBy(RelayCall::session(...))
            ->call(
                factory: 'call_agent',
                deadlineMs: RelayCall::DEADLINE_MS,
                create: false,
                codec: ChannelCodec::Text,
            )
            ->into(GatewayAccount::class);
        $account->filter(RelayCall::isOutcome(...))
            ->keyBy(RelayCall::session(...))
            ->into(GatewayLog::class)
            ->keyed()
            ->sink('gateway_out');
    }
}

#[Task(name: 'tool_turn')]
final class ToolTurn extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit(RelayCall::pass($input));
    }
}

#[Task(name: 'tool_log')]
final class ToolLog extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit(RelayCall::unprefixed($input));
    }
}

#[Relay(
    factoryId: 'call_tool_session',
    run: ['steps' => ['each' => 'per_input']],
    durable: FIXTURE_DURABLE,
    maxDurationMs: 600_000,
)]
final class CallToolSession extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $turn = $c->input('turn', codec: ChannelCodec::Text)->into(ToolTurn::class)->named('tool');
        $turn->filter(static fn (mixed $line): bool => !RelayCall::isOutcome($line))
            ->call('pricing', deadlineMs: RelayCall::DEADLINE_MS, codec: ChannelCodec::Text)
            ->into(ToolTurn::class);
        $turn->filter(RelayCall::isOutcome(...))->into(ToolLog::class)->sink('tool_out');
    }
}

final class CallFixtures
{
    /** @return list<class-string<\Flezha\Relay>> */
    public static function all(): array
    {
        return [CallPricing::class, CallOrders::class, CallAgent::class, CallGateway::class, CallToolSession::class];
    }
}

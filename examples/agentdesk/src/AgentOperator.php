<?php

declare(strict_types=1);

namespace Flezha\Examples\Agentdesk;

use Flezha\Attribute\Operator;
use Flezha\ChannelCodec;
use Flezha\Graph\Builder;

/**
 * One conversation: an operator that runs in steps, one turn per message it is sent, for as long
 * as the session lasts. Every session is an instance of its own, with its own state.
 */
#[Operator(
    factoryId: 'agent',
    run: ['steps' => [
        'each' => 'per_input',                       // one turn per message
        'on_failure' => 'keep_progress',             // a failed turn fails alone
    ]],
    durable: ['local' => ['sleep_after' => 10_000]], // an idle session sleeps after 10 s
)]
final class AgentOperator extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('turn', codec: ChannelCodec::Text)
            ->into(Reply::class)
            ->sink('answer')                         // to whoever listens
            ->into(Deliver::class)
            ->response();                            // to whoever called
        $c->input('message')                         // from the gateway
            ->map(fn (array $message) => $message['text'])
            ->into(Reply::class);
    }
}

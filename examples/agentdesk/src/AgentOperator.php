<?php

declare(strict_types=1);

namespace Flezha\Examples\Agentdesk;

use Flezha\Attribute\Bounded;
use Flezha\Attribute\Operator;
use Flezha\BoundedOperator;
use Flezha\ChannelCodec;
use Flezha\Graph\Builder;
use Flezha\LoopMode;
use Flezha\RollbackMode;

/**
 * One conversation: a bounded operator that runs one turn per message it is sent, for as long as
 * the session lasts. Every session is an instance of its own, with its own state.
 */
#[Operator(factoryId: 'agent')]
#[Bounded(
    loopMode: LoopMode::PerInput,                    // one turn per message
    rollbackMode: RollbackMode::KeepProgress,        // a failed turn fails alone
)]
final class AgentOperator extends BoundedOperator
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

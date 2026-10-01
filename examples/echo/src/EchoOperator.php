<?php

declare(strict_types=1);

namespace Flezha\Examples\Echo;

use Flezha\Attribute\Operator;
use Flezha\ChannelCodec;
use Flezha\Graph\Builder;

/**
 * The deployable unit. An operator declares the graph a message travels: the
 * entry channel it arrives on, the steps it passes through, and the channel its
 * result leaves on.
 *
 * `factoryId` is the operator's stable identity, and the name
 * `flz operator start` takes. The graph is checked as a whole when the runner
 * declares it, so one that cannot work is refused at startup.
 *
 * The entry names the text codec, so what `flz operator send --text` sends
 * arrives as that string. The sink names nothing: a string is sent as it
 * stands, so the output is the text itself, and any other value would be JSON.
 */
#[Operator(factoryId: 'echo')]
final class EchoOperator extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)
            ->into(EchoTasklet::class)
            ->sink('out');
    }
}

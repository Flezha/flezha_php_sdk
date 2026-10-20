<?php

declare(strict_types=1);

/**
 * The smallest relay: one entry channel, one stateless tasklet, one
 * plugin output. A value leaves through `$ctx->emit()`, so a pass-through is
 * one call.
 *
 *     php examples/E01_Echo.php default
 */

namespace Flezha\Examples\E01;

require_once __DIR__ . '/../vendor/autoload.php';

use Flezha\Attribute\Relay;
use Flezha\ChannelBuilder;
use Flezha\ChannelCodec;
use Flezha\Ctx;
use Flezha\Flezha;
use Flezha\Tasklet;

#[Tasklet\Spec(name: 'echoer')]
final class Echoer extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit($input);
    }
}

#[Relay(factoryId: 'echo')]
final class EchoRelay extends \Flezha\Relay
{
    public static function channels(ChannelBuilder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)->into(Echoer::class)->output('out');
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    Flezha::createRunner($argv[1] ?? 'default')->registerFactory(EchoRelay::class)->run();
}

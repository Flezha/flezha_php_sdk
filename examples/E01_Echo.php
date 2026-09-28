<?php

declare(strict_types=1);

/**
 * The smallest operator: one entry channel, one stateless tasklet, one
 * plugin sink. A value leaves through `$ctx->emit()`, so a pass-through is
 * one call.
 *
 *     php examples/E01_Echo.php default
 */

namespace Flezha\Examples\E01;

require_once __DIR__ . '/../vendor/autoload.php';

use Flezha\Attribute\Operator;
use Flezha\Attribute\Task;
use Flezha\ChannelCodec;
use Flezha\Ctx;
use Flezha\Flezha;
use Flezha\Graph\Builder;
use Flezha\Tasklet;
use Flezha\UnboundedOperator;

#[Task(name: 'echoer')]
final class Echoer extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit($input);
    }
}

#[Operator(factoryId: 'echo')]
final class EchoOperator extends UnboundedOperator
{
    public static function channels(Builder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)->into(Echoer::class)->sink('out');
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    Flezha::createRunner($argv[1] ?? 'default')->registerFactory(EchoOperator::class)->run();
}

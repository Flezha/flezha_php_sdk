<?php

declare(strict_types=1);

namespace Flezha\Examples\Echo;

use Flezha\Attribute\Task;
use Flezha\Ctx;
use Flezha\Tasklet;

/**
 * A processing stage of the operator's graph, run once per message. This one
 * prefixes the input and emits it through the context: `run` returns nothing.
 *
 * `name` is the tasklet's stable identity, and it is required. State is filed
 * under it, so renaming the class keeps it, and renaming `name` declares a
 * different tasklet. With no `initState` and no `applyChange` overridden,
 * nothing is kept between messages and nothing is journaled.
 *
 * Where the result goes is not decided here. The operator decides that, so the
 * graph can be rewired without touching this class.
 */
#[Task(name: 'echo')]
final class EchoTasklet extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit('echo: ' . $input);
    }
}

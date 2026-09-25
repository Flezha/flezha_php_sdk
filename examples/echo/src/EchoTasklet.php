<?php

declare(strict_types=1);

namespace Flezha\Examples\Echo;

use Flezha\Attribute\Task;
use Flezha\Ctx;
use Flezha\Tasklet;

/**
 * A processing stage of the operator's graph, run once per message. This one
 * prefixes the input, and what `run` returns is emitted.
 *
 * `name` is the tasklet's stable identity. State is filed under it, so
 * renaming it later declares a different tasklet rather than renaming this
 * one. With no `state` and no `applyChange` overridden, nothing is kept between
 * messages and nothing is journaled.
 *
 * Where the result goes is not decided here. The operator decides that, so the
 * graph can be rewired without touching this class.
 */
#[Task(name: 'echo')]
final class EchoTasklet extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        return 'echo: ' . $input;
    }
}

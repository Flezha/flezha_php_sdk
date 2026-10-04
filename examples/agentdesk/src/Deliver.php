<?php

declare(strict_types=1);

namespace Flezha\Examples\Agentdesk;

use Flezha\Attribute\Task;
use Flezha\Ctx;
use Flezha\Tasklet;

/** Hands the finished answer to the caller: a channel has one end, and `answer` is a sink. */
#[Task(name: 'deliver')]
final class Deliver extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit($input); // the whole answer, to whoever called
    }

    // The undo, when a turn is taken back: an answer already given has nothing to take back.
    public function compensate(array $outputs, Ctx $ctx): void
    {
    }
}

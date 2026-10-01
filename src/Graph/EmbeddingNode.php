<?php

declare(strict_types=1);

namespace Flezha\Graph;

use Flezha\Attribute\Task;
use Flezha\Ctx;
use Flezha\Tasklet;

/**
 * The tasklet behind a node that embeds a steps class, which the core never
 * runs: each edge key runs a stepped instance of the class instead. Its
 * identity is the node's name, the class's factory id by default.
 *
 * @internal
 */
#[Task(name: 'embedding')]
final class EmbeddingNode extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
    }
}

<?php

declare(strict_types=1);

namespace Flezha;

/** How a spawn request reached an instance. */
enum SpawnOutcome: string
{
    /** A new instance started with the payload as its first input. */
    case Spawned = 'spawned';
    /** The id was running: the payload became one more input of that instance. */
    case Delivered = 'delivered';
    /** A replayed invocation issued no request. */
    case SkippedOnReplay = 'skipped_on_replay';
}

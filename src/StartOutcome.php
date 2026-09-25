<?php

declare(strict_types=1);

namespace Flezha;

/** What a start did. */
enum StartOutcome: string
{
    case Started = 'started';
    /** The named operator was already running and was left untouched. */
    case AlreadyRunning = 'already_running';
}

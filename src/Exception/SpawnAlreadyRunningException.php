<?php

declare(strict_types=1);

namespace Flezha\Exception;

/** The id was running and the request said `ifRunning: 'error'`. */
final class SpawnAlreadyRunningException extends SpawnException
{
}

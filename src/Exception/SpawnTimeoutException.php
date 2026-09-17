<?php

declare(strict_types=1);

namespace Flezha\Exception;

/** No answer arrived within the core's deadline for the request. */
final class SpawnTimeoutException extends SpawnException
{
}

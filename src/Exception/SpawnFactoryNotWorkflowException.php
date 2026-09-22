<?php

declare(strict_types=1);

namespace Flezha\Exception;

/** The requested factory is registered, as a stream, so there is no workflow to spawn. */
final class SpawnFactoryNotWorkflowException extends SpawnException
{
}

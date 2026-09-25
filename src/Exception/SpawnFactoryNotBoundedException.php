<?php

declare(strict_types=1);

namespace Flezha\Exception;

/** The requested factory is registered, as an unbounded operator, so there is no bounded operator to spawn. */
final class SpawnFactoryNotBoundedException extends SpawnException
{
}

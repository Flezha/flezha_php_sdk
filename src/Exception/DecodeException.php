<?php

declare(strict_types=1);

namespace Flezha\Exception;

/**
 * A wire value this host cannot represent, named by the field it was read at:
 * a defect between this package and the core it loaded, not a refusal.
 */
final class DecodeException extends \UnexpectedValueException
{
}

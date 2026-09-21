<?php

declare(strict_types=1);

namespace Flezha\Dispatch;

use Throwable;

/**
 * An entry payload the channel's decoder or its edge's transforms rejected:
 * the one failure the core is told apart from a handler's, because it parks
 * the payload rather than counting it as a tasklet failure. Only the entry
 * seam raises it.
 */
final class UnreadablePayload extends \RuntimeException
{
    public function __construct(string $message, Throwable $cause)
    {
        parent::__construct($message, 0, $cause);
    }
}

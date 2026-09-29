<?php

declare(strict_types=1);

namespace Flezha\Exception;

/**
 * A tasklet error the retry ladder must not retry: the item parks
 * immediately, with no further attempts.
 *
 * An author's own steering type, not a refusal, so it is no
 * {@see FlezhaException}. The dispatcher reads `isNonRetryable` off any
 * throwable, so an author can mark any exception class the same way.
 */
final class NonRetryableException extends \RuntimeException
{
    public function isNonRetryable(): bool
    {
        return true;
    }
}

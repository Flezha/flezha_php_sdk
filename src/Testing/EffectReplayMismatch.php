<?php

declare(strict_types=1);

namespace Flezha\Testing;

/**
 * A replayed input made an outside call its live run kept through
 * `Ctx::effect` some other way: its function ran again, or the kept result was
 * never asked for.
 */
final class EffectReplayMismatch extends \LogicException
{
    public function __construct(string $tasklet, public readonly string $name, string $what)
    {
        parent::__construct(sprintf(
            "%s: on the replay, effect '%s' %s. A re-run reads an outside call's result only "
            . 'through ctx->effect, with the same name in the same order.',
            $tasklet,
            $name,
            $what,
        ));
    }
}

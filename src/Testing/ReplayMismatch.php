<?php

declare(strict_types=1);

namespace Flezha\Testing;

/**
 * The recorded change log and the live state disagree: the harness's own
 * assertion about the tasklet under test, not a refusal.
 */
final class ReplayMismatch extends \LogicException
{
    public function __construct(string $tasklet, mixed $live, mixed $replayed)
    {
        parent::__construct(sprintf(
            '%s: replaying the recorded change log against a fresh initial state produced %s, '
            . 'but the live state is %s. Either a handler mutated the state without recording a '
            . 'change, or applyChange is not a pure fold — both are lost on restart and reported '
            . 'nowhere until then.',
            $tasklet,
            json_encode($replayed),
            json_encode($live),
        ));
    }
}

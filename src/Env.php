<?php

declare(strict_types=1);

namespace Flezha;

use Flezha\Ffi\Core;

/**
 * A knob read from the environment and parsed by the core's one rule, so a PHP
 * runner answers `"0"`, `"on"` or a typo exactly as every other runtime does.
 * A knob's name, type and default are declared once in `Flezha.Config.Keys`.
 */
final class Env
{
    /** A count from the environment, or null for anything that is not one. */
    public static function positive(string $name): ?int
    {
        $raw = getenv($name);

        return Core::knob('positive', $raw === false ? '' : $raw);
    }
}

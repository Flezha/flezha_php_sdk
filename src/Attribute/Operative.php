<?php

declare(strict_types=1);

namespace Flezha\Attribute;

use Attribute;
use Flezha\ChannelCodec;

/**
 * The factory id an operative class registers under, and whether its change
 * bytes reach the per-entity WAL.
 *
 * On a §5 short form, a class that defines its one procedure's `run` instead
 * of `channels()`, it also names the channels that procedure is wired between:
 * the entry channel (`input` when none is written) and its `codec`, and the
 * plugin `sink` the procedure's output reaches, if any.
 *
 * Deliberately not inherited: two operatives cannot share a factory id, and a
 * subclass silently taking its parent's would be refused by the core at
 * declare time rather than here. PHP attributes are not inherited by default,
 * which is the behaviour this wants.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Operative
{
    public function __construct(
        public readonly string $factoryId,
        public readonly bool $persistent = true,
        public readonly ?string $sink = null,
        public readonly ?string $input = null,
        public readonly ?ChannelCodec $codec = null,
    ) {
    }
}

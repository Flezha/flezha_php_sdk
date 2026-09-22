<?php

declare(strict_types=1);

namespace Flezha\Attribute;

use Attribute;
use Flezha\ChannelCodec;

/**
 * The factory id an operative class registers under, whether its change bytes
 * reach the per-cell WAL, and whether an idle instance sleeps: written out and
 * dropped from memory until its next input. Unwritten, a workflow sleeps when
 * it persists and a stream never does.
 *
 * On a §5 short form, a class that defines its one tasklet's `run` instead
 * of `channels()`, it also names the channels that tasklet is wired between:
 * the entry channel (`input` when none is written) and its `codec`, and the
 * plugin `sink` the tasklet's output reaches, if any.
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
        public readonly ?bool $sleepWhenIdle = null,
        public readonly ?string $sink = null,
        public readonly ?string $input = null,
        public readonly ?ChannelCodec $codec = null,
    ) {
    }
}

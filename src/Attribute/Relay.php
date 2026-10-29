<?php

declare(strict_types=1);

namespace Flezha\Attribute;

use Attribute;
use Flezha\ChannelCodec;

/**
 * The factory id a relay class registers under and its class settings,
 * each written as the settings tree's own data: `run: 'steps'`,
 * `run: ['steps' => ['each' => 'per_input']]`, `durable: false`,
 * `durable: ['local' => ['sleep_after' => 2_000]]`. Unwritten, the class runs
 * as a flow and keeps its work locally without sleeping (a steps class sleeps
 * after six idle hours unless it says otherwise). A finished instance keeps
 * nothing unless the level sets `keep_finished` to hours, or to `true` for the
 * controller's window (72 hours by default), so `flz relay info` can still
 * show its Cells and result. The core reads the tree when the class registers
 * and refuses a misplaced setting by name.
 *
 * On a §5 short form, a class that defines its one tasklet's `run` instead
 * of `channels()`, it also names the channels that tasklet is wired between:
 * the entry channel (`input` when none is written) and its `codec`, and the
 * plugin `output` the tasklet's output reaches, if any.
 *
 * Deliberately not inherited: two relays cannot share a factory id, and a
 * subclass silently taking its parent's would be refused by the core at
 * declare time rather than here. PHP attributes are not inherited by default,
 * which is the behaviour this wants.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Relay
{
    /** The short form's own arguments, which a full form wires in `channels()` instead. */
    public const SHORT_FORM_ARGUMENTS = ['output', 'input', 'codec'];

    /**
     * @param string|array<string, mixed>|null      $run
     * @param bool|string|array<string, mixed>|null $durable
     */
    public function __construct(
        public readonly string $factoryId,
        public readonly string|array|null $run = null,
        public readonly bool|string|array|null $durable = null,
        public readonly ?int $maxDurationMs = null,
        public readonly ?string $description = null,
        public readonly ?string $output = null,
        public readonly ?string $input = null,
        public readonly ?ChannelCodec $codec = null,
    ) {
    }
}

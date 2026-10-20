<?php

declare(strict_types=1);

/**
 * The probe family — `poison_probe` and `wedge_probe` — the same partition as
 * the reference's probe.rs. Loaded after fixtures.php, whose `Wire` codecs
 * these share.
 */

namespace Flezha\TestApp;

use Flezha\Attribute\Relay;
use Flezha\ChannelBuilder;
use Flezha\ChannelCodec;
use Flezha\Ctx;
use Flezha\Tasklet;

// -- poison_probe / wedge_probe ----------------------------------------------

#[Tasklet\Spec(name: 'poison_probe_tasklet')]
final class PoisonProbe extends Tasklet
{
    private static bool $antidoteTaken = false;

    public function run(mixed $input, Ctx $ctx): void
    {
        if ($input === 'antidote') {
            self::$antidoteTaken = true;
        }
        if ($input === 'poison' && !self::$antidoteTaken) {
            throw new \RuntimeException('poison payload');
        }

        $ctx->emit("ok:$input");
    }
}

#[Relay(factoryId: 'poison_probe')]
final class PoisonProbeRelay extends \Flezha\Relay
{
    public static function channels(ChannelBuilder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)->retryBackoffMs(25)
            ->into(PoisonProbe::class)
            ->output('out');
    }
}

/**
 * The `pool_wedge` fixture: the two ways a runner stops doing work, on one
 * relay.
 *
 *   "wedge" — blocks the dispatch inside user code forever, so the SDK's
 *     dispatch counter stops advancing while the heartbeat keeps reporting the
 *     runner alive: connected, heartbeating, processing nothing.
 *   "abort" — ends its activation as violently as this host allows, WITHOUT
 *     emitting, once per process. PHP has no way for user code to kill a
 *     worker outright — the dispatcher catches per item and the throw rides
 *     the retry ladder — so what the scenario asserts is that the cell is
 *     served again and the aborting item does not produce twice.
 *   "slow" — emits two seconds late, so the relay can be stopped or moved
 *     while the activation still runs.
 */
#[Tasklet\Spec(name: 'wedge_probe_tasklet')]
final class WedgeProbe extends Tasklet
{
    private static bool $aborted = false;

    public function run(mixed $input, Ctx $ctx): void
    {
        if ($input === 'wedge') {
            while (true) {
                sleep(3600);
            }
        }
        if ($input === 'abort' && !self::$aborted) {
            self::$aborted = true;

            throw new \RuntimeException('wedge_probe: killing the worker holding this activation');
        }
        if ($input === 'slow') {
            sleep(2);
        }

        $ctx->emit("ok:$input");
    }
}

#[Relay(factoryId: 'wedge_probe')]
final class WedgeProbeRelay extends \Flezha\Relay
{
    public static function channels(ChannelBuilder $c): void
    {
        $c->input('input', codec: ChannelCodec::Text)
            ->into(WedgeProbe::class)
            ->output('out');
    }
}

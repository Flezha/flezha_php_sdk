<?php

declare(strict_types=1);

namespace Flezha\Graph;

use Flezha\Codec\Json;
use Flezha\Exception\FlezhaException;
use Flezha\Ffi\Core;

/**
 * The verdict on one declaration.
 *
 * The topology is judged in ONE place, `flz_core::host::register::check`: this
 * class builds the record, submits it through `flz_core_check_class`, and the
 * core's refusal reaches the author as the {@see FlezhaException} it throws.
 */
final class Validate
{
    private function __construct()
    {
    }

    /**
     * Judge one declaration and answer what the core derived from it: the
     * channel names, kinds, consumers, output names and acknowledgement
     * granularity it read off the record.
     *
     * @return array<string, mixed>
     */
    public static function spec(ClassSpec $spec, Core $core): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = Json::decode($core->checkClass(Json::encode(HostClassDecl::of($spec))));
        self::bindEdges($spec, $decoded);

        return $decoded;
    }

    /**
     * The consumers the CORE derived, each carrying the closures of the chain
     * that produced it: the builder held them aside per chain, and the derived
     * edge names the chain. Which edge survives a re-declaration is therefore
     * the core's answer, not a rule this package repeats.
     *
     * @param array<string, mixed> $derived
     */
    private static function bindEdges(ClassSpec $spec, array $derived): void
    {
        /** @var list<array{index: int, kind: string, consumers: list<array{tasklet: int, chain: int}>}> $channels */
        $channels = $derived['channels'];
        foreach ($channels as $channel) {
            $target = $spec->channels[$channel['index']];
            $consumers = [];
            foreach ($channel['consumers'] as $edge) {
                $consumers[] = $target->chainEdges[$edge['chain']];
            }
            $target->consumers = $consumers;
            $target->derivedKind = $channel['kind'];
        }
    }
}

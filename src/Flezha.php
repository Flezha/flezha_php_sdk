<?php

declare(strict_types=1);

namespace Flezha;

/**
 * The Flezha PHP SDK: a poll host over `flz_core`.
 *
 * The SDK contains no kernel code. Transport, framing, ordering, per-cell
 * exclusivity, durability, retries, the retry-to-park ladder, timers, credit,
 * routing, the steps driver and reconnect all live in the core; this
 * package brings the authoring surface, the codec for user types, the
 * concurrency host and idiomatic errors.
 *
 *     #[Task(name: 'echo')]
 *     final class EchoTasklet extends Tasklet
 *     {
 *         public function run(mixed $input, Ctx $ctx): void { $ctx->emit($input); }
 *     }
 *
 *     #[Relay(factoryId: 'echo')]
 *     final class EchoRelay extends Relay
 *     {
 *         public static function channels(Builder $c): void
 *         {
 *             $c->input('input')->into(EchoTasklet::class)->sink('out');
 *         }
 *     }
 *
 *     Flezha::createRunner('default')->registerFactory(EchoRelay::class)->run();
 */
final class Flezha
{
    private function __construct()
    {
    }

    /** Start here. */
    public static function createRunner(string $namespace): Runner
    {
        return new Runner($namespace);
    }
}

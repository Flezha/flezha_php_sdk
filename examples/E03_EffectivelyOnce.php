<?php

declare(strict_types=1);

/**
 * The effectively-once tier, with checkpoint acknowledgement.
 *
 * The tier IS durable state, so it needs persistence; every plugin-bound
 * channel must be `keyed()`, because deduplication is by output identity. With
 * a checkpoint `every`, changes stage without advancing the watermark until the
 * interval closes — every four inputs here, or on an explicit
 * `$ctx->checkpoint()`.
 */

namespace Flezha\Examples\E03;

require_once __DIR__ . '/../vendor/autoload.php';

use Flezha\Attribute\Relay;
use Flezha\ChannelBuilder;
use Flezha\Ctx;
use Flezha\Flezha;
use Flezha\Tasklet;

#[Tasklet\Spec(name: 'ledger')]
final class Ledger extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['balance' => 0];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['balance'] += (int) $change;
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->change((int) $input['delta']);

        // Close the interval as soon as the balance is settled, rather than
        // waiting for the count: an explicit checkpoint is what a business
        // boundary looks like.
        if ($input['settled'] ?? false) {
            $ctx->checkpoint();
        }

        $ctx->emit(['account' => $ctx->cellKey, 'balance' => $ctx->state['balance']]);
    }
}

#[Relay(
    factoryId: 'ledger',
    run: ['flow' => ['exactly' => ['effectively_once' => ['ack' => ['checkpoint' => ['every' => 4]]]]]],
)]
final class LedgerRelay extends \Flezha\Relay
{
    public static function channels(ChannelBuilder $c): void
    {
        $c->input('entries')
            ->keyBy(static fn (array $e): string => (string) $e['account'])
            ->into(Ledger::class)->keyed()
            ->output('balances');
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    Flezha::createRunner($argv[1] ?? 'default')->registerFactory(LedgerRelay::class)->run();
}

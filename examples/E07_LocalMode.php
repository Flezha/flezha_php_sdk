<?php

declare(strict_types=1);

/**
 * The two-tasklet word count, run in this process with no controller.
 *
 *   scripts/build-php-sdk.sh
 *   php sdk/php/examples/E07_LocalMode.php
 *
 * `Split` and `Tally` are ordinary tasklets with nothing local-mode about
 * them: the same classes deploy against a controller unchanged. What local
 * mode supplies is the output — the values `Tally` releases arrive here instead
 * of on a socket.
 *
 * State is held in memory and nothing is durable; the run says so on stderr.
 */

namespace Flezha\Examples\E07;

require_once __DIR__ . '/../vendor/autoload.php';

use Flezha\Attribute\Relay;
use Flezha\ChannelCodec;
use Flezha\Ctx;
use Flezha\ChannelBuilder;
use Flezha\LocalRelay;
use Flezha\Tasklet;
use Flezha\LocalOutput;

/** One line into its words: the hop to `Tally` is the runtime's job. */
#[Tasklet\Spec(name: 'split')]
final class Split extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        foreach (preg_split('/\s+/', trim((string) $input), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $ctx->emit(strtolower($word));
        }
    }
}

/** One Cell per word, minted on first sight of it. */
#[Tasklet\Spec(name: 'tally')]
final class Tally extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return 0;
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state = (int) $change;
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        $seen = ((int) $ctx->state) + 1;
        $ctx->change($seen);

        $ctx->emit((string) $input . ' ' . $seen);
    }
}

#[Relay(factoryId: 'local_word_count_example')]
final class WordCountRelay extends \Flezha\Relay
{
    public static function channels(ChannelBuilder $c): void
    {
        $c->input('lines', codec: ChannelCodec::Text)
            ->into(Split::class)
            // One Cell per word: the hop from `Split` to `Tally` is the
            // runtime's, not this file's.
            ->keyBy(static fn (string $word): string => $word)
            ->into(Tally::class)->keyed()
            ->output('totals');
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $run = LocalRelay::start(WordCountRelay::class);

    try {
        $run->onOutput('totals', static fn (LocalOutput $out) => print($out->payload . PHP_EOL));
        $run->feed('lines', 'the quick brown fox');
        $run->feed('lines', 'the lazy dog');
        $run->runUntilIdle(10_000);
    } finally {
        $run->close();
    }
}

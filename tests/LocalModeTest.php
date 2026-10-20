<?php

declare(strict_types=1);

namespace Flezha\Tests;

require_once __DIR__ . '/../examples/E07_LocalMode.php';

use Flezha\Attribute\Relay;
use Flezha\Ctx;
use Flezha\Examples\E07\WordCountRelay;
use Flezha\Exception\FlezhaException;
use Flezha\ChannelBuilder;
use Flezha\LocalRelay;
use Flezha\Tasklet;
use Flezha\LocalOutput;

/**
 * The PHP SDK's local mode: the shipped example's relay class, its
 * declared two-tasklet graph, and no controller anywhere.
 *
 * The example is what is asserted rather than a copy of it, so the file a
 * reader is pointed at is the file this proves.
 *
 * The graph and its expected output are read from
 * `test/fixtures/local_mode_word_count.txt`, which the Rust, Java, Node,
 * Python, Ruby and Elixir local-mode suites read too — so every core is
 * compared against one recorded expectation instead of by inspection.
 */
final class LocalModeTest extends RepositoryTestCase
{
    use CapturesTheLog;

    /**
     * The scenario every core runs: the lines to feed, and the output values.
     *
     * @return array{list<string>, array<string, int>}
     */
    private function scenario(): array
    {
        $text = (string) file_get_contents($this->repositoryFile('test/fixtures/local_mode_word_count.txt'));
        $lines = [];
        $totals = [];
        foreach (explode("\n", $text) as $row) {
            if (str_starts_with($row, 'lines: ')) {
                $lines[] = substr($row, 7);
            } elseif (str_starts_with($row, 'totals: ')) {
                $total = substr($row, 8);
                $totals[$total] = ($totals[$total] ?? 0) + 1;
            }
        }

        return [$lines, $totals];
    }

    public function testADeclaredGraphRunsLocallyAndReleasesTheRecordedOutput(): void
    {
        [$lines, $expected] = $this->scenario();
        $released = [];
        $run = LocalRelay::start(WordCountRelay::class);

        try {
            $run->onOutput('totals', static function (LocalOutput $out) use (&$released): void {
                $released[] = $out;
            });
            foreach ($lines as $line) {
                $run->feed('lines', $line);
            }
            $run->runUntilIdle(20_000);
        } finally {
            $run->close();
        }

        $counted = [];
        foreach ($released as $output) {
            $counted[$output->payload] = ($counted[$output->payload] ?? 0) + 1;
        }
        ksort($counted);
        ksort($expected);
        self::assertSame(
            $expected,
            $counted,
            'both tasklets ran, the runtime performed the hop, and one Cell per word counted',
        );

        self::assertSame('totals', $released[0]->channel);
        foreach (['flz.cell', 'flz.seq', 'flz.key'] as $stamp) {
            self::assertArrayHasKey($stamp, $released[0]->metadata, "a keyed sink carries $stamp");
        }
    }

    public function testAChannelTheClassDoesNotDeclareIsRefusedByName(): void
    {
        $run = LocalRelay::start(WordCountRelay::class);

        try {
            $this->expectException(FlezhaException::class);
            $this->expectExceptionCode('runtime.channel_undeclared');
            $this->expectExceptionMessageMatches('/nope.*lines/s');
            $run->feed('nope', '1');
        } finally {
            $run->close();
        }
    }

    public function testAnUndeclaredEntryReadsJsonAndAnUndeclaredOutputFollowsTheValue(): void
    {
        $released = [];
        $run = LocalRelay::start(DefaultCodecs::class);

        try {
            $run->onOutput('out', static function (LocalOutput $out) use (&$released): void {
                $released[] = $out->payload;
            });
            $run->feed('in', '{"a":1}');
            $run->runUntilIdle(5_000);
        } finally {
            $run->close();
        }

        self::assertSame(['a is 1', '{"a":1}'], $released);
    }

    public function testTheBannerReachesTheSdkLoggerAtErrorAsTheRunStarts(): void
    {
        $run = LocalRelay::start(WordCountRelay::class);

        try {
            self::assertCount(1, $this->logged);
            self::assertStringStartsWith(
                'error: LOCAL MODE: state is held in memory and NOTHING is durable',
                $this->logged[0],
            );
        } finally {
            $run->close();
        }
    }

    public function testARetryNoticeReachesTheSdkLogger(): void
    {
        FlakyTasklet::$attempts = 0;
        $run = LocalRelay::start(FlakyRelay::class);

        try {
            $run->onOutput('out', static function (): void {
            });
            $run->feed('in', '"x"');
            $run->runUntilIdle(10_000);
        } finally {
            $run->close();
        }

        $retries = array_values(array_filter(
            $this->logged->getArrayCopy(),
            static fn (string $line): bool => str_starts_with($line, 'warn: tasklet flaky'),
        ));
        self::assertCount(1, $retries);
        self::assertStringStartsWith(
            'warn: tasklet flaky attempt 1/3 failed (retrying in ',
            $retries[0],
        );
        self::assertStringContainsString('the first attempt fails', $retries[0]);
        // The SDK's own line for the failed attempt, at `debug`.
        $failed = array_values(array_filter(
            $this->logged->getArrayCopy(),
            static fn (string $line): bool => str_starts_with($line, 'debug: flaky failed an item: '),
        ));
        self::assertCount(1, $failed);
        $this->logged->exchangeArray(array_values(
            array_diff($this->logged->getArrayCopy(), $retries, $failed),
        ));
    }
}

/** Fails its first attempt, so the core retries it and says so. */
#[Tasklet\Spec(name: 'flaky')]
final class FlakyTasklet extends Tasklet
{
    public static int $attempts = 0;

    public function run(mixed $input, Ctx $ctx): void
    {
        if (++self::$attempts === 1) {
            throw new \RuntimeException('the first attempt fails');
        }
        $ctx->emit($input);
    }
}

#[Relay(factoryId: 'local_flaky_php')]
final class FlakyRelay extends \Flezha\Relay
{
    public static function channels(ChannelBuilder $c): void
    {
        $c->input('in')->into(FlakyTasklet::class)->output('out');
    }
}

/** Reads the map an undeclared entry decodes, and emits a string and a map. */
#[Tasklet\Spec(name: 'defaults')]
final class DefaultCodecsTasklet extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit('a is ' . $input['a']);
        $ctx->emit(['a' => $input['a']]);
    }
}

#[Relay(factoryId: 'local_default_codecs_php')]
final class DefaultCodecs extends \Flezha\Relay
{
    public static function channels(ChannelBuilder $c): void
    {
        $c->input('in')->into(DefaultCodecsTasklet::class)->output('out');
    }
}

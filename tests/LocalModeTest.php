<?php

declare(strict_types=1);

namespace Flezha\Tests;

require_once __DIR__ . '/../examples/E07_LocalMode.php';

use Flezha\Attribute\Operator;
use Flezha\Attribute\Task;
use Flezha\Ctx;
use Flezha\Examples\E07\WordCountOperator;
use Flezha\Exception\FlezhaException;
use Flezha\Graph\Builder;
use Flezha\LocalOperator;
use Flezha\Tasklet;
use Flezha\SinkOutput;
use Flezha\UnboundedOperator;

/**
 * The PHP SDK's local mode: the shipped example's operator class, its
 * declared two-tasklet graph, and no controller anywhere.
 *
 * The example is what is asserted rather than a copy of it, so the file a
 * reader is pointed at is the file this proves.
 *
 * The graph and its expected sink output are read from
 * `test/fixtures/local_mode_word_count.txt`, which the Rust, Java, Node,
 * Python, Ruby and Elixir local-mode suites read too — so every core is
 * compared against one recorded expectation instead of by inspection.
 */
final class LocalModeTest extends RepositoryTestCase
{
    /**
     * The scenario every core runs: the lines to feed, and the sink values.
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
        $run = LocalOperator::start(WordCountOperator::class);

        try {
            $run->onSink('totals', static function (SinkOutput $out) use (&$released): void {
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
        $run = LocalOperator::start(WordCountOperator::class);

        try {
            $this->expectException(FlezhaException::class);
            $this->expectExceptionMessageMatches('/nope.*lines/s');
            $run->feed('nope', '1');
        } finally {
            $run->close();
        }
    }

    public function testAnUndeclaredEntryReadsJsonAndAnUndeclaredSinkFollowsTheValue(): void
    {
        $released = [];
        $run = LocalOperator::start(DefaultCodecs::class);

        try {
            $run->onSink('out', static function (SinkOutput $out) use (&$released): void {
                $released[] = $out->payload;
            });
            $run->feed('in', '{"a":1}');
            $run->runUntilIdle(5_000);
        } finally {
            $run->close();
        }

        self::assertSame(['a is 1', '{"a":1}'], $released);
    }
}

/** Reads the map an undeclared entry decodes, and emits a string and a map. */
#[Task(name: 'defaults')]
final class DefaultCodecsTasklet extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): mixed
    {
        $ctx->emit('a is ' . $input['a']);
        $ctx->emit(['a' => $input['a']]);

        return null;
    }
}

#[Operator(factoryId: 'local_default_codecs_php')]
final class DefaultCodecs extends UnboundedOperator
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(DefaultCodecsTasklet::class)->sink('out');
    }
}

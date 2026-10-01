<?php

declare(strict_types=1);

namespace Flezha\Tests\Inner;

use Flezha\Attribute\Operator;
use Flezha\Attribute\Task;
use Flezha\ChannelCodec;
use Flezha\Ctx;
use Flezha\Exception\FlezhaException;
use Flezha\Graph\Builder;
use Flezha\LocalOperator;
use Flezha\SinkOutput;
use Flezha\Tasklet;
use Flezha\Tests\RepositoryTestCase;

/**
 * An operator inside an operator, run locally: a flow class whose keyed edge
 * ends in a steps class runs one stepped instance of it per key, each with its
 * own state, and the instance's exit output continues on the outer graph; a
 * flow class is inlined under prefixed names.
 */
final class InnerOperatorTest extends RepositoryTestCase
{
    /**
     * @param class-string<\Flezha\Operator> $operator
     * @param list<string> $lines
     * @return list<string>
     */
    private function released(string $operator, array $lines, int $count): array
    {
        $outputs = [];
        $run = LocalOperator::start($operator);
        try {
            $run->onSink('out', static function (SinkOutput $out) use (&$outputs): void {
                $outputs[] = $out->payload;
            });
            foreach ($lines as $line) {
                $run->feed('requests', $line);
            }
            $run->runUntilIdle(5_000);
        } finally {
            $run->close();
        }
        self::assertCount($count, $outputs, json_encode($outputs, JSON_THROW_ON_ERROR));
        sort($outputs);

        return $outputs;
    }

    public function testEveryKeyRunsItsOwnScopeAndTheExitContinuesTheOuterGraph(): void
    {
        self::assertSame(
            ['alice#1:hi', 'alice#2:again', 'bob#1:yo'],
            $this->released(Gateway::class, ['alice:hi', 'bob:yo', 'alice:again'], 3),
        );
    }

    public function testAThirdKeyPastMaxCellsIsDropped(): void
    {
        self::assertSame(
            ['alice#1:hi', 'bob#1:yo'],
            $this->released(Gateway::class, ['alice:hi', 'bob:yo', 'carol:hey'], 2),
        );
    }

    public function testAFlowClassIsInlinedUnderPrefixedNames(): void
    {
        self::assertSame(['HI'], $this->released(Inlining::class, ['  hi  '], 1));
        $names = array_map(static fn ($node): string => $node->name, Inlining::spec()->tasklets);
        sort($names);
        self::assertSame(['inner_cleanup_php.trim', 'inner_cleanup_php.upper', 'reply'], $names);
        foreach (Inlining::spec()->tasklets as $node) {
            self::assertNull($node->embeds);
        }
    }

    public function testAnInlinedClassWithTwoEntriesAndNoInputIsRefusedNamingBoth(): void
    {
        try {
            AmbiguousEntry::spec();
            self::fail('refused');
        } catch (FlezhaException $error) {
            self::assertSame('topology.embedded_entry_ambiguous', $error->getCode());
            self::assertEquals(['inner' => 'inner_two_entries_php', 'channels' => 'a, b'], $error->details);
        }
    }

    public function testAValueATaskletEmitsEntersTheScopeInItsEntryCodec(): void
    {
        self::assertSame(['alice#1:hi'], $this->released(AfterATasklet::class, ['  alice:hi  '], 1));
    }

    public function testASinkRightAfterAnEmbeddedClassIsRefused(): void
    {
        try {
            LocalOperator::start(SinkAfter::class);
            self::fail('refused');
        } catch (FlezhaException $error) {
            self::assertSame('topology.invalid_setting', $error->getCode());
            self::assertSame('out', $error->details['value']);
        }
    }

    public function testAStepsClassEmbeddingAStepsClassIsRefusedNamingBoth(): void
    {
        try {
            LocalOperator::start(StepsOuter::class);
            self::fail('refused');
        } catch (FlezhaException $error) {
            self::assertSame('topology.nested_steps', $error->getCode(), $error->getMessage());
            self::assertStringContainsString('inner_steps_outer_php', $error->getMessage());
            self::assertStringContainsString('inner_session_php', $error->getMessage());
        }
    }
}

/** One turn of a session: `key:text` answered as `key#turns:text`. */
#[Task(name: 'turn')]
final class Turn extends Tasklet
{
    public function initState(?string $cellKey): mixed
    {
        return ['turns' => 0];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['turns'] += $change['by'];
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        [$key, $text] = explode(':', $input, 2);
        $ctx->change(['by' => 1]);
        $ctx->emit("$key#{$ctx->state['turns']}:$text");
    }
}

#[Task(name: 'reply')]
final class Reply extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit($input);
    }
}

#[Task(name: 'trim')]
final class Trim extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit(trim($input));
    }
}

#[Task(name: 'upper')]
final class Upper extends Tasklet
{
    public function run(mixed $input, Ctx $ctx): void
    {
        $ctx->emit(strtoupper($input));
    }
}

#[Operator(factoryId: 'inner_session_php', run: ['steps' => ['each' => 'per_input']], durable: false)]
final class Session extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('turns', codec: ChannelCodec::Text)->into(Turn::class)->named('answer');
    }
}

#[Operator(factoryId: 'inner_gateway_php')]
final class Gateway extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('requests', codec: ChannelCodec::Text)
            ->keyBy(static fn (string $line): string => explode(':', $line)[0])
            ->into(Session::class, maxCells: 2)
            ->into(Reply::class)
            ->sink('out', codec: ChannelCodec::Text);
    }
}

#[Operator(factoryId: 'inner_after_a_tasklet_php')]
final class AfterATasklet extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('requests', codec: ChannelCodec::Text)
            ->into(Trim::class)
            ->keyBy(static fn (string $line): string => explode(':', $line)[0])
            ->into(Session::class)
            ->into(Reply::class)
            ->sink('out', codec: ChannelCodec::Text);
    }
}

#[Operator(factoryId: 'inner_sink_after_php')]
final class SinkAfter extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('requests', codec: ChannelCodec::Text)->into(Session::class)->sink('out', codec: ChannelCodec::Text);
    }
}

#[Operator(factoryId: 'inner_cleanup_php')]
final class Cleanup extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('raw', codec: ChannelCodec::Text)->into(Trim::class)->into(Upper::class);
    }
}

#[Operator(factoryId: 'inner_inlining_php')]
final class Inlining extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('requests', codec: ChannelCodec::Text)
            ->into(Cleanup::class)
            ->into(Reply::class)
            ->sink('out', codec: ChannelCodec::Text);
    }
}

#[Operator(factoryId: 'inner_two_entries_php')]
final class TwoEntries extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('a')->into(Trim::class);
        $c->input('b')->into(Upper::class);
    }
}

#[Operator(factoryId: 'inner_ambiguous_entry_php')]
final class AmbiguousEntry extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('in')->into(TwoEntries::class)->into(Reply::class);
    }
}

#[Operator(factoryId: 'inner_steps_outer_php', run: ['steps' => ['each' => 'per_input']], durable: false)]
final class StepsOuter extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)->into(Session::class)->into(Reply::class);
    }
}

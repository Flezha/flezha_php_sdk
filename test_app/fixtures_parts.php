<?php

declare(strict_types=1);

/**
 * Twins of the reference's part fixtures (test/apps/rust_runner/src/part.rs)
 * under the same factory ids, tasklet names, channel names and payloads: for
 * an input `n` the tasklet sends the parts "1" .. "n" and then emits
 * "done:<n>" on its sink `answer`. `retry:<n>` sends its parts and then fails
 * retryably the first time this process sees it, so the retry's parts number
 * from 0 again under the next attempt.
 */

namespace Flezha\TestApp;

use Flezha\Attribute\Operator;
use Flezha\Attribute\Task;
use Flezha\ChannelCodec;
use Flezha\Ctx;
use Flezha\Exception\NonRetryableException;
use Flezha\Graph\Builder;
use Flezha\Tasklet;

abstract class PartSender extends Tasklet
{
    /** @var array<string, true> the `retry:` inputs that have already failed once in this process */
    private static array $failedOnce = [];

    public function run(mixed $input, Ctx $ctx): void
    {
        $input = (string) $input;
        $count = str_starts_with($input, 'retry:') ? substr($input, strlen('retry:')) : $input;
        if (!ctype_digit($count)) {
            throw new NonRetryableException("not a part count: $input");
        }
        for ($part = 1; $part <= (int) $count; $part++) {
            $ctx->part((string) $part);
        }
        if (str_starts_with($input, 'retry:') && !isset(self::$failedOnce[$input])) {
            self::$failedOnce[$input] = true;
            throw new \RuntimeException("first attempt of $input");
        }

        $ctx->emit('done:' . (int) $count);
    }
}

#[Task(name: 'part_echo')]
final class PartEchoStep extends PartSender
{
}

#[Operator(factoryId: 'part_echo')]
final class PartEcho extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)->retryBackoffMs(25)
            ->into(PartEchoStep::class)
            ->sink('answer');
    }
}

#[Task(name: 'part_echo_turn')]
final class PartEchoTurn extends PartSender
{
}

#[Operator(
    factoryId: 'part_echo_steps',
    run: ['steps' => ['each' => 'per_input']],
    durable: FIXTURE_DURABLE,
    maxDurationMs: 600_000,
)]
final class PartEchoSteps extends \Flezha\Operator
{
    public static function channels(Builder $c): void
    {
        $c->input('in', codec: ChannelCodec::Text)->retryBackoffMs(25)
            ->into(PartEchoTurn::class)
            ->sink('answer');
    }
}

final class PartFixtures
{
    /** @return list<class-string<\Flezha\Operator>> */
    public static function all(): array
    {
        return [PartEcho::class, PartEchoSteps::class];
    }
}

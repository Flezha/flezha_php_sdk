<?php

declare(strict_types=1);

/**
 * A chat session as a `PerInput` workflow: one iteration per message.
 *
 * The conversation lives in cell state, so a crash resumes at the last
 * committed turn, and between messages the instance parks without holding a
 * runner thread. The message whose `kind` is `end` ends the instance through
 * `$ctx->complete()`, once its iteration commits.
 */

namespace Flezha\Examples\E06;

require_once __DIR__ . '/../vendor/autoload.php';

use Flezha\Attribute\Operative;
use Flezha\Attribute\Task;
use Flezha\Attribute\Workflow;
use Flezha\Ctx;
use Flezha\Flezha;
use Flezha\Graph\Builder;
use Flezha\LoopMode;
use Flezha\Tasklet;
use Flezha\WorkflowOperative;

#[Task]
final class ChatTurn extends Tasklet
{
    public function state(?string $cellKey): mixed
    {
        return ['history' => []];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        $state['history'][] = $change;
    }

    public function run(mixed $input, Ctx $ctx): mixed
    {
        if (($input['kind'] ?? null) === 'end') {
            $ctx->complete(['messages' => count($ctx->state['history'])]);

            return null;
        }

        $ctx->change($input['text']);

        return ['reply' => 'noted: ' . $input['text'], 'seen' => count($ctx->state['history'])];
    }
}

#[Operative(factoryId: 'chat')]
#[Workflow(loopMode: LoopMode::PerInput, maxDurationMs: 3_600_000)]
final class ChatWorkflow extends WorkflowOperative
{
    public static function channels(Builder $c): void
    {
        $c->input('messages')->into(ChatTurn::class)->sink('replies');
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    Flezha::createRunner($argv[1] ?? 'default')->registerFactory(ChatWorkflow::class)->run();
}

<?php

declare(strict_types=1);

/**
 * Per-cell timers: a session that closes itself after a quiet period.
 *
 * A timer's scheduled time is anchored on `$ctx->now()` — the core-evaluated
 * declared clock — never on an ambient read, which is what makes a replayed
 * invocation register the timestamp the live one did. `onTimer` receives the
 * scheduled time, and `now()` inside it equals that time, so a chained timer
 * anchors on the firing that woke it.
 */

namespace Flezha\Examples\E04;

require_once __DIR__ . '/../vendor/autoload.php';

use Flezha\Attribute\Relay;
use Flezha\Attribute\Task;
use Flezha\Ctx;
use Flezha\Flezha;
use Flezha\Graph\Builder;
use Flezha\Tasklet;
use Flezha\Timer;

#[Task(name: 'session', idleTtlSeconds: 3_600)]
final class Session extends Tasklet
{
    private const QUIET_MS = 30_000;

    public function initState(?string $cellKey): mixed
    {
        return ['events' => 0, 'deadline' => null];
    }

    public function applyChange(mixed &$state, mixed $change): void
    {
        if ($change['kind'] === 'event') {
            $state['events']++;
            $state['deadline'] = $change['deadline'];

            return;
        }
        $state['events'] = 0;
        $state['deadline'] = null;
    }

    public function run(mixed $input, Ctx $ctx): void
    {
        // Cancel the timer this cell is holding before arming the next one,
        // by the timestamp its registration answered.
        if ($ctx->state['deadline'] !== null) {
            $ctx->cancelTimer('processing_time', (int) $ctx->state['deadline']);
        }
        $ctx->change(['kind' => 'event', 'deadline' => $ctx->timerAfter(self::QUIET_MS)]);
    }

    public function onTimer(Timer $timer, Ctx $ctx): void
    {
        $events = $ctx->state['events'];
        $ctx->change(['kind' => 'closed', 'deadline' => null]);
        $ctx->emit(['session' => $ctx->cellKey, 'events' => $events, 'closed_at' => $ctx->now()]);
    }
}

#[Relay(factoryId: 'sessions')]
final class SessionRelay extends \Flezha\Relay
{
    public static function channels(Builder $c): void
    {
        $c->input('events')
            ->keyBy(static fn (array $e): string => (string) $e['session'])
            ->into(Session::class)->keyed()
            ->sink('closed');
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    Flezha::createRunner($argv[1] ?? 'default')->registerFactory(SessionRelay::class)->run();
}

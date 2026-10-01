<?php

declare(strict_types=1);

namespace Flezha\Examples\Agentdesk;

use Flezha\Attribute\Operator;
use Flezha\Graph\Builder;

/**
 * The HTTP side of the desk: a flow operator that carries the HTTP plugin, answers every
 * request, and calls the session a message is for.
 */
#[Operator(
    factoryId: 'gateway',
    run: ['flow' => ['exactly' => 'effectively_once']], // each request and answer on disk before it runs
)]
final class GatewayOperator extends \Flezha\Operator
{
    private const DEADLINE_MS = 60_000;

    public static function channels(Builder $c): void
    {
        $user = fn (array $value) => $value['user'];
        $http = $c->input('http', Request::decode(...));
        $http->filter(fn (Request $request) => !$request->sends())
            ->into(Watch::class)
            ->response();                            // a stream, or 404
        $verdicts = $http->filter(fn (Request $request) => $request->sends())
            ->map(fn (Request $request) => $request->message())
            ->keyBy($user)                           // one cell per user
            ->into(Budget::class);
        $verdicts->into(Admit::class)
            ->response();                            // 202, or 429 over the budget
        $verdicts->filter(Budget::admitted(...))
            ->map(fn (array $verdict) => $verdict['message'])
            ->keyBy($user)                           // the user's session
            ->call(factory: 'agent', channel: 'message', deadlineMs: self::DEADLINE_MS)
            ->map(Budget::used(...))
            ->keyBy($user)
            ->into(Budget::class);                   // the tokens the answer used
    }
}

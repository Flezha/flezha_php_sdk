<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\Dispatch\Dispatcher;
use Flezha\Dispatch\Registry;
use Flezha\Ffi\Core;
use Flezha\Graph\Validate;
use Flezha\Operator;

/** Activations as the core hands them, for tests that drive the dispatcher directly. */
trait Dispatches
{
    /**
     * A dispatcher over one declared operator, with a context of its own the
     * caller frees.
     *
     * @param class-string<Operator> $operator
     * @return array{Dispatcher, Core}
     */
    private static function dispatcherFor(string $operator): array
    {
        $core = Core::create();
        $registry = new Registry();
        $spec = $operator::spec();
        Validate::spec($spec, $core);
        $registry->add($spec);

        return [new Dispatcher($registry, $core), $core];
    }

    /** @return array<string, mixed> */
    private static function activation(string $factory, string $kind, string $operator = 'op'): array
    {
        return [
            'token' => 1, 'factory' => $factory, 'tasklet' => 0, 'operator' => $operator,
            'cell_key' => null, 'change_seq' => 0, 'kind' => $kind,
        ];
    }

    /** @return array<string, mixed> */
    private static function input(string $payload, ?int $offset = null, int $channel = 0): array
    {
        return [
            'kind' => 'input',
            'channel' => $channel,
            'payload' => $payload,
            'metadata' => [],
            'consumed_offset' => $offset,
            'replay' => false,
            'now' => 1_000,
        ];
    }
}

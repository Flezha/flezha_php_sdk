<?php

declare(strict_types=1);

namespace Flezha;

use Flezha\Exception\FlezhaException;
use Flezha\Ffi\Core;

/**
 * Administering the namespace this runner's connection proved, from ordinary
 * application code.
 *
 * No tasklet has to be running and the application holds no credential of
 * its own: the controller judges every call as the identity the connection
 * already established, so nothing an unattended process holds expires. An
 * user grants a namespace's applications the authority once and revokes it
 * the same way, and a revocation takes effect on the next call:
 *
 *     flz role bind runner@team-orders app team-orders
 *
 * Every method here is one line over a single native entry point, because the
 * verb travels as data. Adding a verb costs a method and no native work.
 */
final class Admin
{
    /** @param \Closure(string, string): string $adminCall {@see Core::adminCall()} */
    public function __construct(private readonly \Closure $adminCall)
    {
    }

    /**
     * Start an operator of `$factoryId`, on the specification `flz operator
     * start` takes.
     *
     * Without an `$id` the controller generates one, so a start retried after a
     * lost answer starts a second operator. With one, a start of an id already
     * running leaves that operator untouched and answers `already_running`.
     *
     * @throws FlezhaException the controller's refusal, as for every call here
     */
    public function startOperator(
        string $factoryId,
        ?string $id = null,
        mixed $initValues = null,
        mixed $opts = null,
    ): StartResult {
        $answer = $this->call('Operator.start', array_filter(
            ['factory_id' => $factoryId, 'id' => $id, 'init_values' => $initValues, 'opts' => $opts],
            static fn (mixed $field): bool => $field !== null,
        ));
        $outcome = StartOutcome::tryFrom((string) ($answer['outcome'] ?? ''));
        if ($outcome === null || !is_string($answer['id'] ?? null)) {
            throw new FlezhaException(sprintf(
                'the controller answered a start with %s, not an id and an outcome',
                json_encode($answer),
            ));
        }

        return new StartResult($answer['id'], $outcome);
    }

    /** Stop an operator, or the whole partition group it names. */
    public function stopOperator(string $operatorId): mixed
    {
        return $this->call('Operator.stop', $operatorId);
    }

    /** Inject a message into an operator's input stream, blocking for the verdict. */
    public function send(string $operatorId, string $message, string $channel = ''): mixed
    {
        return $this->call('Operator.send', $operatorId, $message, $channel);
    }

    /** Enable a plugin, built from the fields `flz plugin enable` takes. */
    public function enablePlugin(string $pluginId, array $fields): mixed
    {
        return $this->call('PluginManager.enable', $pluginId, $fields);
    }

    /** Disable a plugin, or every partitioned instance of it. */
    public function disablePlugin(string $pluginId): mixed
    {
        return $this->call('PluginManager.disable', $pluginId);
    }

    /** The operator ids running in this namespace. */
    public function listOperators(): mixed
    {
        return $this->call('Operator.list_operators');
    }

    /** The plugins enabled on the controller node answering this call. */
    public function listPlugins(): mixed
    {
        return $this->call('PluginManager.list');
    }

    /** One cell's live state, and the change sequence it was read at. */
    public function cellState(string $operatorId, string $cellKey): mixed
    {
        return $this->call('Operator.cell_state', $operatorId, $cellKey);
    }

    /**
     * Any published function, by name. What succeeds follows from the role the
     * namespace is bound to, never from a list kept here. The arguments are
     * the call's own other than the namespace, which the controller fills from
     * the connection.
     */
    public function call(string $verb, mixed ...$args): mixed
    {
        $answer = ($this->adminCall)($verb, json_encode(array_values($args), JSON_THROW_ON_ERROR));

        return json_decode($answer, true, 512, JSON_THROW_ON_ERROR);
    }
}

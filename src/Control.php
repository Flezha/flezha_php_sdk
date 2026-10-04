<?php

declare(strict_types=1);

namespace Flezha;

use Flezha\Exception\FlezhaException;
use Flezha\Ffi\Core;

/**
 * Administering the namespace this runner's connection proved, from ordinary
 * application code. Sending is the runner's own, {@see \Flezha\Runner::send()}.
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
final class Control
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
        $answer = $this->call('Operator.start', self::given(
            ['factory_id' => $factoryId, 'id' => $id, 'init_values' => $initValues, 'opts' => $opts],
        ));
        $outcome = StartOutcome::tryFrom((string) ($answer['outcome'] ?? ''));
        if ($outcome === null || !is_string($answer['id'] ?? null)) {
            throw new FlezhaException('control.unreadable_answer', ['verb' => 'Operator.start']);
        }

        return new StartResult($answer['id'], $outcome);
    }

    /** Stop an operator, or the whole partition group it names. */
    public function stopOperator(string $operatorId): mixed
    {
        return $this->call('Operator.stop', $operatorId);
    }

    /**
     * Enable the plugin `$name` in this namespace, of the provider `$provider`
     * (for example `operator_websocket`), on its `$opts`. `$single` runs one
     * copy in the whole cluster; `$operatorId` with `$hooks` binds that
     * operator in the same call.
     *
     * @param array<string, mixed> $opts
     * @param list<mixed>|null $hooks names or `[name, opts]` pairs
     */
    public function enablePlugin(
        string $name,
        string $provider,
        array $opts = [],
        bool $single = false,
        ?string $operatorId = null,
        ?array $hooks = null,
    ): mixed {
        return $this->call('PluginManager.enable', $name, self::given([
            'provider' => $provider,
            'opts' => (object) $opts,
            'single' => $single ?: null,
            'operator_id' => $operatorId,
            'hooks' => $hooks,
        ]));
    }

    /**
     * Bind the operator `$operatorId` to the enabled plugin `$name`, with the
     * optional `$hooks` and `$channel`.
     *
     * @param list<mixed>|null $hooks names or `[name, opts]` pairs
     */
    public function bindPlugin(string $name, string $operatorId, ?array $hooks = null, ?string $channel = null): mixed
    {
        return $this->call(
            'PluginManager.bind',
            $name,
            self::given(['operator_id' => $operatorId, 'hooks' => $hooks, 'channel' => $channel]),
        );
    }

    /** Unbind the operator `$operatorId` from the plugin `$name`, which stays enabled. */
    public function unbindPlugin(string $name, string $operatorId): mixed
    {
        return $this->call('PluginManager.unbind', $name, ['operator_id' => $operatorId]);
    }

    /** Disable the plugin `$name`, stopping it wherever it runs. */
    public function disablePlugin(string $name): mixed
    {
        return $this->call('PluginManager.disable', $name, new \stdClass());
    }

    /** The operator ids running in this namespace. */
    public function listOperators(): mixed
    {
        return $this->call('Operator.list_operators');
    }

    /** The plugins enabled in this namespace: name, provider, single, bound operators. */
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

    /**
     * The named parameters a call was given, leaving out every one it was not.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function given(array $fields): array
    {
        return array_filter($fields, static fn (mixed $field): bool => $field !== null);
    }
}

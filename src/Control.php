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
     * Start a relay of `$factoryId`, on the specification `flz relay
     * start` takes.
     *
     * Without an `$id` the controller generates one, so a start retried after a
     * lost answer starts a second relay. With one, a start of an id already
     * running leaves that relay untouched and answers `already_running`.
     *
     * @throws FlezhaException the controller's refusal, as for every call here
     */
    public function startRelay(
        string $factoryId,
        ?string $id = null,
        mixed $initValues = null,
        mixed $opts = null,
    ): StartResult {
        $answer = $this->call('Relay.start', self::given(
            ['factory_id' => $factoryId, 'id' => $id, 'init_values' => $initValues, 'opts' => $opts],
        ));
        $outcome = StartOutcome::tryFrom((string) ($answer['outcome'] ?? ''));
        if ($outcome === null || !is_string($answer['id'] ?? null)) {
            throw new FlezhaException('control.unreadable_answer', ['verb' => 'Relay.start']);
        }

        return new StartResult($answer['id'], $outcome);
    }

    /** Stop a relay, or the whole partition group it names. */
    public function stopRelay(string $relayId): mixed
    {
        return $this->call('Relay.stop', $relayId);
    }

    /**
     * Enable the plugin `$name` in this namespace, of the provider `$provider`
     * (for example `relay_websocket`), on its `$opts`. `$single` runs one
     * copy in the whole cluster; `$relayId` with `$hooks` binds that
     * relay in the same call.
     *
     * @param array<string, mixed> $opts
     * @param list<mixed>|null $hooks names or `[name, opts]` pairs
     */
    public function enablePlugin(
        string $name,
        string $provider,
        array $opts = [],
        bool $single = false,
        ?string $relayId = null,
        ?array $hooks = null,
    ): mixed {
        return $this->call('PluginManager.enable', $name, self::given([
            'provider' => $provider,
            'opts' => (object) $opts,
            'single' => $single ?: null,
            'relay_id' => $relayId,
            'hooks' => $hooks,
        ]));
    }

    /**
     * Bind the relay `$relayId` to the enabled plugin `$name`, with the
     * optional `$hooks` and `$channel`.
     *
     * @param list<mixed>|null $hooks names or `[name, opts]` pairs
     */
    public function bindPlugin(string $name, string $relayId, ?array $hooks = null, ?string $channel = null): mixed
    {
        return $this->call(
            'PluginManager.bind',
            $name,
            self::given(['relay_id' => $relayId, 'hooks' => $hooks, 'channel' => $channel]),
        );
    }

    /** Unbind the relay `$relayId` from the plugin `$name`, which stays enabled. */
    public function unbindPlugin(string $name, string $relayId): mixed
    {
        return $this->call('PluginManager.unbind', $name, ['relay_id' => $relayId]);
    }

    /** Disable the plugin `$name`, stopping it wherever it runs. */
    public function disablePlugin(string $name): mixed
    {
        return $this->call('PluginManager.disable', $name, new \stdClass());
    }

    /** The relay ids running in this namespace. */
    public function listRelays(): mixed
    {
        return $this->call('Relay.list_relays');
    }

    /** The plugins enabled in this namespace: name, provider, single, bound relays. */
    public function listPlugins(): mixed
    {
        return $this->call('PluginManager.list');
    }

    /** One cell's live state, and the change sequence it was read at. */
    public function cellState(string $relayId, string $cellKey): mixed
    {
        return $this->call('Relay.cell_state', $relayId, $cellKey);
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

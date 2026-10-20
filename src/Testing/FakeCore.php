<?php

declare(strict_types=1);

namespace Flezha\Testing;

use Flezha\Codec\Json;
use Flezha\Exception\FlezhaException;
use Flezha\SpawnBridge;

/**
 * The core a `Ctx` asks inside {@see TaskletTestHarness}, answered as the core
 * would for one tasklet with no controller behind it.
 *
 * @internal
 */
final class FakeCore implements SpawnBridge
{
    /** The lowercase base32 a generated id's suffix is spelled in. */
    private const BASE32 = 'abcdefghijklmnopqrstuvwxyz234567';

    /** The longest effect name, in bytes, as the core refuses it. */
    private const MAX_EFFECT_NAME = 255;

    /** @var array<int, string> each spawn's answer by handle number, released when its invocation ends */
    private array $spawns = [];

    private int $spawnCount = 0;

    /** @var list<array<string, list<string>>> each live input's effect results, by name in call order */
    private array $keptEffects = [];

    private int $replays = 0;

    /** @var array<string, list<string>> this input's effect results */
    private array $effects = [];

    private bool $replaying = false;

    /** @var array<string, int> this invocation's count of each effect name */
    private array $ordinals = [];

    /** @var array{string, int}|null the effect whose function is running */
    private ?array $running = null;

    public function __construct(private readonly string $tasklet)
    {
    }

    /** Start an input: a live one keeps its effect results, a replay is served the next live input's. */
    public function beginInput(bool $replay): void
    {
        if ($replay) {
            $this->effects = $this->keptEffects[$this->replays++] ?? [];
        } else {
            $this->effects = [];
            $this->keptEffects[] = [];
        }
        $this->replaying = $replay;
        $this->ordinals = [];
        $this->running = null;
    }

    /** End an input: a replayed one must have asked for every result its live run kept. */
    public function endInput(): void
    {
        foreach ($this->replaying ? $this->effects : [] as $name => $results) {
            if (count($results) > ($this->ordinals[$name] ?? 0)) {
                throw new EffectReplayMismatch($this->tasklet, (string) $name, 'was never asked for');
            }
        }
    }

    /** Release the invocation's spawn handles, as the core does when it ends. */
    public function endInvocation(): void
    {
        $this->spawns = [];
    }

    /**
     * A harness has no controller, so a spawn answers at once what one would
     * have: `spawned` under the given id, or under an id shaped like a
     * generated one, and `skipped_on_replay` on a replay.
     */
    public function spawnRequest(
        int $token,
        string $factoryId,
        string $id,
        string $payload,
        string $ifRunning,
        bool $replay,
        ?int $offset,
    ): int {
        $handle = $this->spawnCount++;
        if ($id === '' && !$replay) {
            // The spawn's number as the suffix: deterministic, and distinct per spawn.
            $suffix = '';
            for ($n = $handle, $i = 0; $i < 16; $i++, $n >>= 5) {
                $suffix = self::BASE32[$n & 31] . $suffix;
            }
            $id = "$factoryId-$suffix";
        }
        $this->spawns[$handle] = Json::encode(['id' => $id, 'outcome' => $replay ? 'skipped_on_replay' : 'spawned']);

        return $handle;
    }

    public function spawnResult(int $handle, int $timeoutMs): ?string
    {
        return $this->spawns[$handle]
            ?? throw new FlezhaException('runtime.spawn_handle_released', ['handle' => $handle]);
    }

    /** No controller and no listener, so the part, already encoded, goes nowhere. */
    public function part(int $token, int $item, string $payload): void
    {
    }

    /**
     * The core's answer to a begun `Ctx::effect`, over this input's results:
     * the kept one, or run the function (on a live input only).
     */
    public function effectBegin(int $token, int $item, string $name): array
    {
        if ($name === '' || strlen($name) > self::MAX_EFFECT_NAME) {
            throw new FlezhaException('effect.bad_name', ['name' => Json::encode($name)]);
        }
        if ($this->running !== null) {
            throw new FlezhaException('effect.nested', ['name' => $name]);
        }

        $ordinal = $this->ordinals[$name] ?? 0;
        $this->ordinals[$name] = $ordinal + 1;
        if (isset($this->effects[$name][$ordinal])) {
            return ['kept', $this->effects[$name][$ordinal]];
        }
        if ($this->replaying) {
            throw new EffectReplayMismatch($this->tasklet, $name, 'ran its function');
        }
        $this->running = [$name, $ordinal];

        return ['run', null];
    }

    /** Keep a live input's result, as the controller does. */
    public function effectEnd(int $token, int $item, string $payload): ?string
    {
        [$name, $ordinal] = $this->running ?? throw new \LogicException('an end follows a begin that runs');
        $this->effects[$name][$ordinal] = $payload;
        $this->keptEffects[array_key_last($this->keptEffects)] = $this->effects;
        $this->running = null;

        return null;
    }

    public function effectRelease(int $token, int $item): void
    {
        $this->running = null;
    }
}

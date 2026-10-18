<?php

declare(strict_types=1);

namespace Flezha\Tests;

use Flezha\Codec\ActivationCodec;
use Flezha\Ffi\Core;

/**
 * The cross-language byte pin.
 *
 * The bytes are the rows of `test/fixtures/postcard_bodies.txt`, which the core
 * encodes from its own structs, rather than a copy here: a schema change is a
 * red PHP build in the commit that makes it. A copy would only fail once
 * someone remembered to update it.
 *
 * Both directions are pinned against the bytes rather than against each other:
 * decode-then-encode round trips pass a mistake that is symmetric, and every
 * mistake in a hand-written codec is.
 */
final class SchemaFixtureTest extends RepositoryTestCase
{
    private function schemaSource(): string
    {
        return file_get_contents($this->repositoryFile('flz_core/src/schema.rs')) ?: '';
    }

    public function testTheShimSpeaksTheCoresSchemaVersion(): void
    {
        preg_match('/pub const SCHEMA_VERSION: u32 = (\d+);/', $this->schemaSource(), $matches);

        self::assertNotEmpty($matches, 'flz_core/src/schema.rs no longer declares SCHEMA_VERSION');
        self::assertSame(
            (int) $matches[1],
            Core::SCHEMA_VERSION,
            'the core bumped its activation schema and this package still speaks the old one; '
            . 'move Core::SCHEMA_VERSION and re-check the fixtures below',
        );
    }

    public function testTheWorkActivationFixtureDecodesToTheValuesTheCoreEncoded(): void
    {
        $activation = ActivationCodec::decodeActivation(self::bytes($this->pinned('activation', 'work')));

        self::assertSame([
            'token' => 42,
            'relay' => 'op-1',
            'tasklet' => 3,
            'cell_key' => 'k',
            'kind' => 'work',
            'items' => [
                [
                    'kind' => 'input',
                    'channel' => 1,
                    'payload' => "\xAA\xBB",
                    'metadata' => ['flz.offset' => '7'],
                    'consumed_offset' => 7,
                    'replay' => true,
                    'now' => 1_000,
                ],
                ['kind' => 'timer', 'timer_kind' => 'event_time', 'scheduled_at' => 2_000],
            ],
            'change_seq' => 9,
            'factory' => 'f',
        ], $activation);
    }

    public function testTheResultFixtureIsEncodedByteForByte(): void
    {
        self::assertSame($this->pinned('result', 'work'), bin2hex(ActivationCodec::encodeResult(self::resultFixture())));
    }

    /** A completion is the effect's last field, after the DLQ entry. */
    public function testACompletionIsTheEffectsLastField(): void
    {
        $encoded = ActivationCodec::encodeResult(self::resultFixture(complete: 'done'));

        self::assertSame($this->pinned('result', 'completing'), bin2hex($encoded));
    }

    /**
     * Every command activation kind the fixture pins, envelope included, so a
     * shim cannot pass by agreeing on the payload and disagreeing on the target.
     */
    public function testEveryCommandKindDecodes(): void
    {
        $expected = [
            'restore_fold' => [
                'snapshot' => ['bytes' => "\x0F", 'from_version' => null],
                'changes' => [
                    ['bytes' => "\x01", 'from_version' => null],
                    ['bytes' => "\x02", 'from_version' => 1],
                ],
            ],
            'snapshot_request' => [],
            'live_state_read' => [],
            'apply_state' => ['state_json' => '{}'],
            'migration_serialize' => [],
            'key_request' => ['key_request' => [
                'channel' => 2,
                'payloads' => ['p'],
                'want_keys' => true,
                'want_event_time' => false,
            ]],
            'compensate_run' => ['outputs' => ["\x0A"]],
            'leave' => ['whole_relay' => true],
        ];

        $commands = $this->rows('activation');
        unset($commands['work']);
        self::assertEqualsCanonicalizing(array_keys($expected), array_keys($commands));

        foreach ($commands as $kind => $hex) {
            $activation = ActivationCodec::decodeActivation(self::bytes($hex));

            self::assertSame([
                'token' => 1,
                'relay' => 'o',
                'tasklet' => 0,
                'cell_key' => null,
                'kind' => $kind,
                ...$expected[$kind],
                'change_seq' => 0,
                'factory' => 'f',
            ], $activation, "the $kind fixture");
        }
    }

    /** @return array<string, string> the hex of every `<type>` row, by case */
    private function rows(string $type): array
    {
        $rows = [];
        foreach (file($this->repositoryFile('test/fixtures/postcard_bodies.txt'), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $fields = explode('|', $line);
            if (count($fields) === 3 && $fields[0] === $type) {
                $rows[$fields[1]] = $fields[2];
            }
        }

        return $rows;
    }

    private function pinned(string $type, string $case): string
    {
        $rows = $this->rows($type);
        self::assertArrayHasKey($case, $rows, "test/fixtures/postcard_bodies.txt has no row $type|$case");

        return $rows[$case];
    }

    /** @return array<string, mixed> the values the core's result fixture encodes */
    private static function resultFixture(?string $complete = null): array
    {
        return [
            'token' => 42,
            'outcome' => 'work',
            'effects' => [[
                'verdict' => 'ok',
                'changes' => ["\x01"],
                'outputs' => [[
                    'target' => 'plugin',
                    'plugin_channel' => 'out',
                    'key' => 'k2',
                    'event_time' => null,
                    'metadata' => [],
                    'payload' => "\x02",
                ]],
                'consumed_offset' => 7,
                'event_time' => 1_000,
                'timers' => [['kind' => 'processing_time', 'scheduled_at' => 2_000, 'register' => false]],
                'checkpoint' => true,
                'complete' => $complete,
            ]],
        ];
    }

    private static function bytes(string $hex): string
    {
        return (string) hex2bin($hex);
    }
}

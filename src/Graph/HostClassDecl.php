<?php

declare(strict_types=1);

namespace Flezha\Graph;

/**
 * The declaration the core deserializes. The settings tree goes as the author
 * wrote it: the core judges it and refuses a misplaced setting by name.
 *
 * Every key here is pinned by `test/fixtures/host_class_decl_keys.txt`, which
 * this package's own test reads: a key the core learns that PHP cannot spell
 * fails that test, which is what makes "one change, every SDK" a red build
 * rather than a silently narrower SDK.
 */
final class HostClassDecl
{
    /** The PHP SDK's byte in the core's `sdk_lang` allocation table. */
    public const SDK_LANG_PHP = 6;

    /**
     * This SDK's own released version, stamped into every durable record it
     * writes; the core packs it. Sole source of truth: `composer.json` declares
     * no `version` key, because Composer resolves a published package's version
     * from its VCS tag and a key in the file is how a stale one ships. So the
     * release checklist bumps this constant, and no gate can compare it against
     * package metadata that does not exist.
     */
    public const SDK_VERSION = '0.1.0';

    /**
     * JSON. The byte names the encoding, not the SDK — the cross-language gate
     * is `sdk_lang`.
     */
    public const FORMAT_ID_JSON = 6;

    private function __construct()
    {
    }

    /** @return array<string, mixed> */
    public static function of(ClassSpec $spec): array
    {
        $keyed = self::keyedTasklets($spec->channels);

        return self::compact([
            'factory_id' => $spec->factoryId,
            'description' => $spec->description,
            'max_duration_ms' => $spec->maxDurationMs,
            'run' => $spec->run,
            'durable' => $spec->durable,
            'sdk_lang' => self::SDK_LANG_PHP,
            'sdk_version' => self::SDK_VERSION,
            'change_format_id' => self::FORMAT_ID_JSON,
            'compact_after_bytes' => $spec->compactAfterBytes,
            'channels' => array_map(self::channel(...), $spec->channels),
            'tasklets' => array_map(
                static fn (TaskNode $node): array => self::tasklet($node, isset($keyed[$node->index])),
                $spec->tasklets,
            ),
        ]);
    }

    /**
     * The channel as the record carries it: the kind the author declared and
     * the sequences declared off its handle. The consumers, the plugin kind and
     * the sink name are the core's to DERIVE from those chains.
     *
     * @return array<string, mixed>
     */
    private static function channel(ChannelSpec $channel): array
    {
        return self::compact([
            'name' => $channel->name,
            'kind' => $channel->kind,
            'producer' => $channel->producer,
            'chains' => $channel->chains,
            'retry_max' => $channel->retryMax,
            'retry_backoff_ms' => $channel->retryBackoffMs,
            'overflow' => $channel->overflow->value,
            'keyed' => $channel->keyed,
        ]);
    }

    /**
     * The tasklets some chain keys its values for: a `key` link before the
     * `into` or merge that names the tasklet. The cell mode belongs to the
     * node, not the class, since one class may be wired keyed in one operator
     * and unkeyed in another.
     *
     * @param list<ChannelSpec> $channels
     * @return array<int, true>
     */
    private static function keyedTasklets(array $channels): array
    {
        $keyed = [];
        foreach ($channels as $channel) {
            foreach ($channel->chains as $links) {
                $key = false;
                foreach ($links as $link) {
                    $key = $key || $link['link'] === 'key';
                    if ($key && ($link['link'] === 'into' || $link['link'] === 'merge')) {
                        $keyed[$link['tasklet']] = true;
                    }
                }
            }
        }

        return $keyed;
    }

    /** @return array<string, mixed> */
    private static function tasklet(TaskNode $node, bool $keyed): array
    {
        $definition = $node->definition;
        $decl = $definition->decl;

        return self::compact([
            'name' => $node->name,
            'cell_mode' => match (true) {
                $decl->cellKeys !== null => 'static',
                $keyed => 'dynamic',
                default => 'single',
            },
            'cell_keys' => $decl->cellKeys ?? [],
            'idle_ttl_ms' => $decl->idleTtlSeconds === null ? null : $decl->idleTtlSeconds * 1000,
            'max_cells' => $decl->maxCells,
            'cells_for_overridden' => $definition->declares('cellsFor'),
            'compensate_overridden' => $definition->declares('compensate'),
            'clock' => $definition->clock(),
            'mailbox_capacity' => $decl->mailboxCapacity ?? 1024,
            // A tasklet with no state journals nothing, so a logical clock
            // over it never advances. PHP has no per-tasklet Change type;
            // the presence of an initState method IS the same statement.
            'change_is_nochange' => !$definition->declares('initState'),
            'schema_version' => $decl->schemaVersion,
            'min_readable_schema_version' => $decl->minReadableSchemaVersion,
        ]);
    }

    /**
     * Drop the keys whose value is null, so the JSON omits them and the core's
     * own defaults apply.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function compact(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => $value !== null);
    }
}

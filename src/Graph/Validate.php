<?php

declare(strict_types=1);

namespace Flezha\Graph;

use Flezha\Codec\Json;
use Flezha\Exception\FlezhaException;
use Flezha\Ffi\Core;

/**
 * The verdict on one declaration, and the defect a record cannot carry.
 *
 * The topology is judged in ONE place, `flz_core::host::register::check`: this
 * class builds the record, submits it through `flz_core_check_class`, and the
 * core's refusal reaches the author as the {@see FlezhaException} it throws. What is refused
 * here instead is what the record cannot state: whether a PHP class supplies
 * the migration a schema floor needs. The core cannot see it.
 */
final class Validate
{
    private function __construct()
    {
    }

    /**
     * Judge one declaration and answer what the core derived from it: the
     * channel names, kinds, consumers, sink names and acknowledgement
     * granularity it read off the record.
     *
     * The defect this package can see is refused first, so an author reads
     * "no migration" rather than a topology verdict.
     *
     * @return array<string, mixed>
     */
    public static function spec(ClassSpec $spec, Core $core): array
    {
        // The core judges an embedding against the class it names, so every
        // steps class this one embeds is declared first: registered, never
        // announced.
        foreach ($spec->tasklets as $node) {
            if ($node->embeds !== null) {
                $inner = $node->embeds::spec();
                self::spec($inner, $core);
                $core->declareClass(Json::encode(HostClassDecl::of($inner, embedded: true)));
            }
        }
        foreach ($spec->tasklets as $node) {
            self::schema($node);
        }

        return self::submit($spec, $core);
    }

    /**
     * The record, over the ABI, and the verdict back.
     *
     * @return array<string, mixed>
     */
    private static function submit(ClassSpec $spec, Core $core): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = Json::decode($core->checkClass(Json::encode(HostClassDecl::of($spec))));
        self::bindEdges($spec, $decoded);

        return $decoded;
    }

    /**
     * The consumers the CORE derived, each carrying the closures of the chain
     * that produced it: the builder held them aside per chain, and the derived
     * edge names the chain. Which edge survives a re-declaration is therefore
     * the core's answer, not a rule this package repeats.
     *
     * @param array<string, mixed> $derived
     */
    private static function bindEdges(ClassSpec $spec, array $derived): void
    {
        /** @var list<array{index: int, consumers: list<array{tasklet: int, chain: int}>}> $channels */
        $channels = $derived['channels'];
        foreach ($channels as $channel) {
            $target = $spec->channels[$channel['index']];
            $consumers = [];
            foreach ($channel['consumers'] as $edge) {
                $consumers[] = $target->chainEdges[$edge['chain']];
            }
            $target->consumers = $consumers;
        }
    }

    /**
     * A schema floor promises a migration, and the migration is a method: the
     * record carries the versions and this package carries the class.
     */
    private static function schema(TaskNode $node): void
    {
        $definition = $node->definition;
        $decl = $definition->decl;
        $floor = $decl->minReadableSchemaVersion;
        if ($floor === null) {
            return;
        }

        if ($floor > $decl->schemaVersion) {
            throw new FlezhaException(
                'topology.schema_floor_above_version',
                ['tasklet' => $node->name, 'floor' => $floor, 'version' => $decl->schemaVersion],
            );
        }
        if ($floor < $decl->schemaVersion
            && !$definition->declares('migrateState')
            && !$definition->declares('migrateChange')) {
            throw new FlezhaException(
                'topology.schema_floor_without_migration',
                ['tasklet' => $node->name, 'floor' => $floor],
            );
        }
    }
}

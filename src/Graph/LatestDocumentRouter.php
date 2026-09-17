<?php

declare(strict_types=1);

namespace Flezha\Graph;

use Flezha\Attribute\Proc;
use Flezha\Codec\Json;
use Flezha\Ctx;
use Flezha\Procedure;

/**
 * The single-entity procedure {@see Builder::routeLatest()} expands to: it
 * retains the latest document and hands it to a key on that key's own next
 * traffic item.
 *
 * Not part of the authoring surface. PHP has no package visibility, and the
 * dispatcher instantiates a procedure from its class, so this one is public.
 *
 * The document is opaque: retained and compared as the JSON the state codec
 * writes, never parsed, so parsing stays with the consumer.
 *
 * @internal
 */
#[Proc(name: 'route_latest')]
final class LatestDocumentRouter extends Procedure
{
    /**
     * The merge mapping for a document.
     *
     * @return array{document: mixed}
     */
    public static function document(mixed $document): array
    {
        return ['document' => $document];
    }

    /**
     * The merge mapping for a traffic item's key. The traffic value itself
     * never reaches the router, so it holds no application values but the
     * document.
     *
     * @return array{traffic: string}
     */
    public static function traffic(mixed $key): array
    {
        return ['traffic' => (string) $key];
    }

    /** `delivered` grows with the keys this member sees, which it already pays for as entities. */
    public function state(?string $entityKey): mixed
    {
        return ['document' => null, 'version' => 0, 'delivered' => []];
    }

    /**
     * A `retain` replaces the document and advances the version, which is what
     * makes every key owed a delivery again; a `delivered` records the version
     * one key received.
     */
    public function applyChange(mixed &$state, mixed $change): void
    {
        if (array_key_exists('retain', $change)) {
            $state['document'] = $change['retain'];
            $state['version']++;

            return;
        }
        $state['delivered'][$change['delivered']['key']] = $change['delivered']['version'];
    }

    public function run(mixed $input, Ctx $ctx): mixed
    {
        $state = $ctx->state;
        // Version 0 means nothing retained, so the first document lands at 1
        // and every key's absent entry is already behind it.
        $retained = $state['version'] > 0;

        if (array_key_exists('document', $input)) {
            if (!$retained || Json::encode($state['document']) !== Json::encode($input['document'])) {
                $ctx->change(['retain' => $input['document']]);
            }

            return null;
        }

        $key = $input['traffic'];
        if (!$retained || ($state['delivered'][$key] ?? 0) >= $state['version']) {
            return null;
        }
        // Emit first: a failed emit rides the channel's retry ladder, and
        // recording the delivery before it would make the retry silent.
        $ctx->emit([$key, $state['document']]);
        $ctx->change(['delivered' => ['key' => $key, 'version' => $state['version']]]);

        return null;
    }
}

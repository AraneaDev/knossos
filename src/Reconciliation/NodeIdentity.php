<?php

declare(strict_types=1);

namespace Knossos\Reconciliation;

use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Store\StableId;

/**
 * The language and stable id a reported node gets.
 *
 * The one place this is computed, so whoever needs a native node's stable id
 * before reconcile runs (the enrichment pass) cannot drift from the id the
 * reconciler assigns.
 */
final class NodeIdentity
{
    /**
     * The language and stable id of one reported node.
     *
     * Native nodes keep the language their local id names; enriched nodes get `ext:<enricher>`.
     *
     * @return array{0: string, 1: string} [language, stable id]
     */
    public static function of(string $projectId, NodeFact $node): array
    {
        $language = self::languageFromReference($node->localId);
        // An enricher's local id is `ext:<enricher>/<id>`. The language names the enricher too, so two enrichers
        // that report the same kind and canonical name get two stable ids and two nodes, each under its own owner.
        if ($language === 'ext' && preg_match('#^ext:([^/]+)/#', $node->localId, $matches) === 1) {
            $language = 'ext:' . $matches[1];
        }

        return [$language, StableId::symbol($projectId, $language, $node->kind, $node->canonicalName)];
    }

    /** The language a local id names, the part before its first colon. */
    private static function languageFromReference(string $reference): string
    {
        $parts = explode(':', $reference, 2);
        if (count($parts) !== 2 || $parts[0] === '') {
            throw new ReconciliationException(sprintf('Node local ID has no language namespace: %s', $reference));
        }
        return $parts[0];
    }
}

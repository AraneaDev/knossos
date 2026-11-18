<?php

declare(strict_types=1);

namespace Knossos\Reconciliation;

use Knossos\Configuration\EnricherDefinition;
use Knossos\Scanner\Protocol\ScannerManifest;

/**
 * Identity of the analyzer set, so a change invalidates incremental reuse.
 */
final class ScannerSetHash
{
    /**
     * The hash of the scanners and the active external enrichers.
     *
     * The enrichers count by name, command and the native node kinds they
     * take: adding, removing or changing one changes the facts a scan
     * produces. Their timeout and environment do not. With no enrichers the
     * hash is what it was before enrichers existed, so a stored graph keeps
     * its fast path.
     *
     * The test-fidelity rules count by id, which carries the rule's version:
     * the findings a graph stores were judged by them, so adding, removing or
     * revising one has to rebuild the graph rather than take the fast path.
     * With no rules the hash is unchanged, as with no enrichers.
     *
     * @param list<ScannerManifest> $scanners
     * @param list<EnricherDefinition> $enrichers
     * @param list<string> $testFidelityRules the ids of the registered test-fidelity rules
     */
    public static function of(array $scanners, array $enrichers = [], array $testFidelityRules = []): string
    {
        $serialized = [];
        foreach ($scanners as $scanner) {
            $serialized[$scanner->id] = $scanner->jsonSerialize();
        }
        ksort($serialized, SORT_STRING);
        if ($enrichers !== []) {
            $active = [];
            foreach ($enrichers as $enricher) {
                $active[$enricher->name] = ['command' => $enricher->command, 'input_node_kinds' => $enricher->inputNodeKinds];
            }
            ksort($active, SORT_STRING);
            // A key no scanner id takes, since none starts with a colon.
            $serialized[':enrichers'] = $active;
        }
        if ($testFidelityRules !== []) {
            $rules = array_values(array_unique($testFidelityRules));
            sort($rules, SORT_STRING);
            $serialized[':test_fidelity'] = $rules;
        }
        return hash('sha256', json_encode($serialized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}

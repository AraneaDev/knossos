<?php

declare(strict_types=1);

namespace Knossos\Enrichment;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Diagnostic;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;

/**
 * Checks one enricher's decoded output against the native graph, fact by fact.
 *
 * This runs before reconcile because the reconciler cannot be trusted with it:
 * an edge from an unknown source aborts the whole scan, an unknown target
 * becomes a synthesised external node, and evidence in an undiscovered file
 * throws. A bad fact is dropped with one warning and the rest is kept, so a
 * single wrong fact from an enricher cannot blank the graph or read as
 * native.
 *
 * What survives is relabelled: local ids become `ext:<enricher>/<id>`, edges
 * to native nodes point at the worker's local id (which is what the
 * reconciler resolves), origin is `enricher`, a declared `certain` is lowered
 * to `probable`, and the enricher, scan and time are stamped into attributes.
 */
final class EnrichmentValidator
{
    /** Native node kinds a `represents` link may target: methods, functions and route handlers. */
    public const REPRESENTABLE_KINDS = ['method', 'function', 'route'];

    private const NODE_KIND_PREFIX = 'ext:';

    /** Attributes the reconciler stamps itself; an enricher must not pre-empt them. */
    private const RESERVED_ATTRIBUTES = ['scanner', 'scanner_local_id'];

    /** @param array<string, true> $discoveredPaths project-relative paths this scan discovered */
    public function __construct(
        private NativeNodeIndex $native,
        private array $discoveredPaths,
    ) {}

    /** Validate a decoded contribution; $producedAt is the ISO-8601 time this run finished. */
    public function validate(string $enricher, string $scanId, string $producedAt, ScanContribution $raw): ValidatedEnrichment
    {
        $stamp = ['enricher' => $enricher, 'enricher_scan_id' => $scanId, 'enricher_produced_at' => $producedAt];
        $dropped = [];
        $own = [];
        $nodes = $this->nodes($enricher, $stamp, $raw->nodes, $own, $dropped);
        $edges = $this->edges($enricher, $stamp, $raw->edges, $own, $dropped);

        return new ValidatedEnrichment(
            new ScanContribution('enricher:' . $enricher, $nodes, $edges, $this->diagnostics($raw->diagnostics)),
            $dropped,
        );
    }

    /**
     * Keep the nodes that pass, relabelled and namespaced; record why each other was dropped.
     *
     * @param array<string, string> $stamp
     * @param list<NodeFact> $facts
     * @param array<string, string> $own enricher's local id => namespaced id, filled here
     * @param list<Diagnostic> $dropped
     * @return list<NodeFact>
     */
    private function nodes(string $enricher, array $stamp, array $facts, array &$own, array &$dropped): array
    {
        $nodes = [];
        $identities = [];
        foreach ($facts as $node) {
            $problem = $this->nodeProblem($node, $identities);
            if ($problem === false) {
                continue;
            }
            if ($problem !== null) {
                $dropped[] = self::warning($problem[0], sprintf('Enricher %s: node %s dropped: %s', $enricher, $node->localId, $problem[1]));
                continue;
            }
            $identities[$node->localId] = $node->kind . "\0" . $node->canonicalName;
            $namespaced = 'ext:' . $enricher . '/' . $node->localId;
            $own[$node->localId] = $namespaced;
            $nodes[] = new NodeFact(
                $namespaced,
                $node->kind,
                $node->canonicalName,
                $node->displayName,
                Origin::Enricher,
                self::confidence($node->confidence),
                $node->evidence,
                $stamp + array_diff_key($node->attributes, array_flip(self::RESERVED_ATTRIBUTES)),
            );
        }

        return $nodes;
    }

    /**
     * Why a node cannot be kept: [code, reason], null when it can, false when it repeats an accepted node.
     *
     * @param array<string, string> $identities kind and name of each accepted local id
     * @return array{string, string}|null|false
     */
    private function nodeProblem(NodeFact $node, array $identities): array|null|false
    {
        if (!str_starts_with($node->kind, self::NODE_KIND_PREFIX) || $node->kind === self::NODE_KIND_PREFIX) {
            return ['ENRICHER_NATIVE_KIND', sprintf('kind %s is not in the ext: namespace; native kinds are refused.', $node->kind)];
        }
        if (!isset($this->discoveredPaths[$node->evidence->relativePath])) {
            return ['ENRICHER_UNDISCOVERED_FILE', sprintf('evidence cites %s, which this scan did not discover.', $node->evidence->relativePath)];
        }
        $previous = $identities[$node->localId] ?? null;
        if ($previous === null) {
            return null;
        }

        return $previous === $node->kind . "\0" . $node->canonicalName
            ? false
            : ['ENRICHER_CONFLICTING_ID', 'the id is already used by a node of another kind or name.'];
    }

    /**
     * Keep the edges that pass, with ends rewritten to the reconciler's ids; record why each other was dropped.
     *
     * @param array<string, string> $stamp
     * @param list<EdgeFact> $facts
     * @param array<string, string> $own
     * @param list<Diagnostic> $dropped
     * @return list<EdgeFact>
     */
    private function edges(string $enricher, array $stamp, array $facts, array $own, array &$dropped): array
    {
        $edges = [];
        $represented = [];
        foreach ($facts as $edge) {
            $source = $this->endpoint($edge->sourceReference, $own);
            $target = $this->endpoint($edge->targetReference, $own);
            $problem = $this->edgeProblem($edge, $source, $target, $represented);
            if ($problem !== null) {
                $dropped[] = self::warning($problem[0], sprintf(
                    'Enricher %s: %s edge from %s to %s dropped: %s',
                    $enricher,
                    $edge->kind,
                    $source['label'] ?? $edge->sourceReference,
                    $target['label'] ?? $edge->targetReference,
                    $problem[1],
                ));
                continue;
            }
            if ($edge->kind === 'represents') {
                $represented[$edge->sourceReference] = true;
            }
            $edges[] = new EdgeFact(
                $edge->kind,
                (string) $source['reference'],
                (string) $target['reference'],
                Origin::Enricher,
                self::confidence($edge->confidence),
                $edge->evidence,
                $stamp + $edge->attributes,
            );
        }

        return $edges;
    }

    /**
     * Resolve an edge end: the enricher's own node, a native node by stable id, or neither.
     *
     * @param array<string, string> $own
     * @return array{reference: string, native: bool, label: string, stable: string}|null
     */
    private function endpoint(string $reference, array $own): ?array
    {
        if (isset($own[$reference])) {
            return ['reference' => $own[$reference], 'native' => false, 'label' => $reference, 'stable' => $reference];
        }
        $local = $this->native->localIdFor($reference);

        return $local === null ? null : ['reference' => $local, 'native' => true, 'label' => $local, 'stable' => $reference];
    }

    /**
     * Why an edge cannot be kept: [code, reason], or null when it can.
     *
     * @param array{reference: string, native: bool, label: string, stable: string}|null $source
     * @param array{reference: string, native: bool, label: string, stable: string}|null $target
     * @param array<string, true> $represented sources that already have a `represents` link
     * @return array{string, string}|null
     */
    private function edgeProblem(EdgeFact $edge, ?array $source, ?array $target, array $represented): ?array
    {
        if ($edge->kind === 'contains') {
            return ['ENRICHER_FORBIDDEN_KIND', 'enrichers may not emit contains edges; that would nest enriched nodes under native ones.'];
        }
        if ($edge->kind === 'represents') {
            $reason = $this->representsProblem($edge, $source, $target, $represented);
            if ($reason !== null) {
                return ['ENRICHER_REPRESENTS_INVALID', $reason];
            }
        } elseif ($source === null || $target === null) {
            return ['ENRICHER_UNKNOWN_ENDPOINT', 'an end is neither a node of this enricher nor a native node of this scan.'];
        }
        if (!isset($this->discoveredPaths[$edge->evidence->relativePath])) {
            return ['ENRICHER_UNDISCOVERED_FILE', sprintf('evidence cites %s, which this scan did not discover.', $edge->evidence->relativePath)];
        }
        if ($source['native'] && $target['native'] && $this->native->hasNativeEdge($source['reference'], $target['reference'], $edge->kind)) {
            return ['ENRICHER_DUPLICATES_NATIVE', sprintf('a native %s edge between these nodes already exists; the native fact stands.', $edge->kind)];
        }

        return null;
    }

    /**
     * Why a `represents` link is invalid, or null when it holds.
     *
     * @param array{reference: string, native: bool, label: string, stable: string}|null $source
     * @param array{reference: string, native: bool, label: string, stable: string}|null $target
     * @param array<string, true> $represented
     */
    private function representsProblem(EdgeFact $edge, ?array $source, ?array $target, array $represented): ?string
    {
        if ($source === null || $source['native']) {
            return 'the source must be an enriched node of this enricher.';
        }
        if ($target === null || !$target['native']) {
            return 'the target must be a native node of this scan.';
        }
        $kind = $this->native->kindOf($target['stable']);
        if (!in_array($kind, self::REPRESENTABLE_KINDS, true)) {
            return sprintf('a %s cannot be represented; only %s can.', (string) $kind, implode(', ', self::REPRESENTABLE_KINDS));
        }

        return isset($represented[$edge->sourceReference]) ? 'the source already represents another symbol.' : null;
    }

    /**
     * Keep the enricher's own diagnostics as warnings at most, under a code of their own.
     *
     * An enricher's `error` would count toward the error budget of a quality
     * gate, and its code could imitate a native one; both are made
     * impossible. Evidence in a file this scan did not discover is removed.
     *
     * @param list<Diagnostic> $diagnostics
     * @return list<Diagnostic>
     */
    private function diagnostics(array $diagnostics): array
    {
        $kept = [];
        foreach ($diagnostics as $diagnostic) {
            $evidence = $diagnostic->evidence;
            $citesUndiscovered = $evidence !== null && !isset($this->discoveredPaths[$evidence->relativePath]);
            $kept[] = new Diagnostic(
                $diagnostic->severity === 'error' ? 'warning' : $diagnostic->severity,
                'ENRICHER_REPORTED:' . $diagnostic->code,
                $diagnostic->message,
                $citesUndiscovered ? null : $evidence,
            );
        }

        return $kept;
    }

    /** `certain` is reserved for native facts, so an enricher's claim is lowered to `probable`. */
    private static function confidence(Confidence $declared): Confidence
    {
        return $declared === Confidence::Certain ? Confidence::Probable : $declared;
    }

    /** A dropped fact is always a warning, never an error: the scan goes on. */
    private static function warning(string $code, string $message): Diagnostic
    {
        return new Diagnostic('warning', $code, $message);
    }
}

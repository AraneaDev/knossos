<?php

declare(strict_types=1);

namespace Knossos\Reconciliation;

use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Store\SharedNodeKinds;
use Knossos\Store\StableId;

/**
 * Resolves a scan's contributions into the rows the graph transaction writes.
 *
 * Pure: it assigns stable ids, resolves edge endpoints, synthesises external
 * nodes and binds classifications and boundaries, without touching the
 * repository. {@see GraphReconciler} persists what it returns.
 */
final readonly class GraphAssembler
{
    /**
     * A reference the graph cannot turn into a symbol. Emitted by a scanner that
     * could not name one segment — a relative import with no parent package, an
     * unqualified call it deferred and never resolved. One malformed fact must
     * cost its own edge, not the scan: the source file is still worth a graph,
     * and the caller needs to be told which reference was dropped.
     */
    private const UNRESOLVABLE_TARGET_CODE = 'reconciler.unresolvable_edge_target';

    /** Resolve the request into the rows the transaction writes, before any lock is taken. */
    public function assemble(FullScanRequest $request): PreparedGraph
    {
        $projectId = StableId::project($request->projectIdentity);
        $scannerSetHash = ScannerSetHash::of($request->scanners, $request->enrichers);
        $scanId = $request->scanId ?? StableId::scan($projectId, bin2hex(random_bytes(16)));

        $fileIds = [];
        foreach ($request->discovery->files as $file) {
            $fileIds[$file->relativePath] = StableId::file($projectId, $file->relativePath);
        }

        [$nodeMap, $nodes, $nodeWarnings] = $this->collectNodes($projectId, $request->contributions);
        $evidencePaths = array_map(static fn(array $node): string => $node['evidence_path'], $nodes);
        $this->attachNodeFiles($nodes, $fileIds);
        [$externalNodes, $edges, $edgeWarnings, $unconfirmedCalls] = $this->resolveEdges($projectId, $request->contributions, $nodeMap, $fileIds);
        self::recordUnconfirmedCalls($nodes, $unconfirmedCalls);
        foreach ($externalNodes as $id => $node) {
            $nodes[$id] = $node;
        }
        $binding = (new EndpointRouteBinder($projectId))->bind($nodes, $edges, $evidencePaths);
        foreach ($binding->edges as $id => $edge) {
            $edges[$id] = $edge;
        }

        return new PreparedGraph(
            $request,
            $projectId,
            $scanId,
            $scannerSetHash,
            $fileIds,
            $nodes,
            $edges,
            $this->resolveClassifications($projectId, $request->classifications, $nodeMap, $fileIds),
            $this->resolveBoundaries($projectId, $request->boundaries, $nodeMap),
            [...$nodeWarnings, ...$edgeWarnings, ...$binding->warnings],
            count($externalNodes),
        );
    }

    /**
     * Resolve classification facts onto node ids, dropping any whose node vanished.
     *
     * @param list<\Knossos\Classification\ClassificationFact> $facts
     * @param array<string, string> $nodeMap
     * @param array<string, string> $fileIds
     * @return list<array<string, mixed>>
     */
    private function resolveClassifications(string $projectId, array $facts, array $nodeMap, array $fileIds): array
    {
        $resolved = [];
        foreach ($facts as $fact) {
            $nodeId = $nodeMap[$fact->nodeReference] ?? null;
            if ($nodeId === null) {
                throw new ReconciliationException(sprintf('Classification target was not emitted: %s', $fact->nodeReference));
            }
            $fileId = $fileIds[$fact->evidence->relativePath] ?? null;
            if ($fileId === null) {
                throw new ReconciliationException(sprintf('Classification evidence file was not discovered: %s', $fact->evidence->relativePath));
            }
            $resolved[] = [
                'id' => StableId::classification($projectId, $nodeId, $fact->role, $fact->ruleId),
                'node_id' => $nodeId,
                'role' => $fact->role,
                'origin' => $fact->origin->value,
                'confidence' => $fact->confidence->value,
                'rule_id' => $fact->ruleId,
                'file_id' => $fileId,
                'start_line' => $fact->evidence->startLine,
                'end_line' => $fact->evidence->endLine,
                'attributes' => $fact->attributes,
            ];
        }
        return $resolved;
    }

    /**
     * Resolve boundary definitions and their memberships onto node ids.
     *
     * @param list<\Knossos\Boundary\BoundaryFact> $facts @param array<string, string> $nodeMap @return list<array<string, mixed>>
     */
    private function resolveBoundaries(string $projectId, array $facts, array $nodeMap): array
    {
        $resolved = [];
        foreach ($facts as $fact) {
            $nodeIds = [];
            foreach ($fact->nodeReferences as $reference) {
                if (!isset($nodeMap[$reference])) {
                    throw new ReconciliationException(sprintf('Boundary member was not emitted: %s', $reference));
                }
                $nodeIds[] = $nodeMap[$reference];
            }
            $resolved[] = [
                // Use identityName (the pre-suffix primary rule name) when present so a
                // merged inferred boundary's stable id is independent of its display-only
                // merged-from suffix; see BoundaryFact::$identityName.
                'id' => StableId::boundary($projectId, $fact->identityName ?? $fact->name, $fact->source),
                'name' => $fact->name,
                // The former names ride along in the stored matcher; see BoundaryAliases.
                'matcher' => $fact->storedMatcher(),
                'source' => $fact->source,
                'node_ids' => array_values(array_unique($nodeIds)),
            ];
        }
        return $resolved;
    }

    /**
     * Assign stable ids to the reported nodes and index them for edge resolution.
     *
     * @param list<ScanContribution> $contributions
     * @return array{0: array<string, string>, 1: array<string, array<string, mixed>>, 2: list<array<string, string|int>>}
     */
    private function collectNodes(string $projectId, array $contributions): array
    {
        $references = [];
        $nodes = [];
        $warnings = [];
        $warnedIds = [];
        foreach ($contributions as $contribution) {
            $scanner = $this->scannerFromOwner($contribution->ownerKey);
            foreach ($contribution->nodes as $node) {
                [$language, $id] = NodeIdentity::of($projectId, $node);
                if (isset($references[$node->localId]) && $references[$node->localId] !== $id) {
                    throw new ReconciliationException(sprintf('Conflicting scanner reference: %s', $node->localId));
                }
                $references[$node->localId] = $id;

                if (isset($nodes[$id])) {
                    // Two declarations share a stable id iff they share
                    // (language, kind, canonical_name) — the very inputs the id
                    // hashes — so a kind/name mismatch here is unreachable. A
                    // genuine collision is a re-declaration from a different
                    // evidence file; keep the first and surface a warning rather
                    // than silently discarding the divergent provenance.
                    // `package`/`endpoint`/`external_*` kinds are exempt: they are
                    // shared across every importing file by design (an endpoint is
                    // a URL, which every file calling it declares), so a
                    // re-declaration there is not suspicious. For kinds that are suspicious, warn
                    // once per stable id rather than once per re-declaring file.
                    $existingPath = $nodes[$id]['evidence_path'];
                    $sharedByDesign = SharedNodeKinds::isSharedByDesign($node->kind);
                    if ($existingPath !== $node->evidence->relativePath && !$sharedByDesign && !isset($warnedIds[$id])) {
                        $warnedIds[$id] = true;
                        $warnings[] = [
                            'owner' => $contribution->ownerKey,
                            'code' => 'reconciler.duplicate_symbol_evidence',
                            'message' => sprintf(
                                'Stable id %s re-declared by %s with a different evidence file (%s vs %s); keeping the first declaration.',
                                $id,
                                $contribution->ownerKey,
                                $existingPath,
                                $node->evidence->relativePath,
                            ),
                            'path' => $node->evidence->relativePath,
                        ];
                    }
                    continue;
                }

                $nodes[$id] = $this->nodeRecord($id, $language, $node, $contribution->ownerKey, $scanner);
            }
        }

        return [$references, $nodes, $warnings];
    }

    /**
     * Resolve each edge's endpoints to node ids, synthesising externals for unknown targets.
     *
     * @param list<ScanContribution> $contributions
     * @param array<string, string> $nodeMap
     * @param array<string, string> $fileIds
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>, 2: list<array<string, string|int>>, 3: array<string, array<string, true>>}
     */
    private function resolveEdges(string $projectId, array $contributions, array $nodeMap, array $fileIds): array
    {
        $external = [];
        $collapse = new EdgeCollapse($projectId, $fileIds);
        $unconfirmed = [];
        $warnings = [];
        $warnedReferences = [];
        $targets = EdgeTargetResolver::forContributions($nodeMap, $contributions);
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                $sourceId = $nodeMap[$edge->sourceReference] ?? null;
                if ($sourceId === null) {
                    throw new ReconciliationException(sprintf(
                        'Edge source was not emitted by any scanner: %s',
                        $edge->sourceReference,
                    ));
                }

                $expanded = $targets->expandedTargets($edge->targetReference);
                if ($expanded !== null) {
                    foreach ($expanded as $targetId) {
                        $collapse->add($edge, $sourceId, $targetId, $contribution->ownerKey);
                    }
                    continue;
                }
                $returned = str_contains($edge->targetReference, ':method_of_return:')
                    || str_contains($edge->targetReference, ':method_of_property:');
                // A scanner marks an edge speculative when it knows the
                // receiver's type but not whether that type declares the member
                // (it may be a trait's or a base's): kept only when it resolves.
                $deferred = $returned || ($edge->attributes['speculative'] ?? false) === true;
                [$reference, $targetId] = $targets->resolve($edge->targetReference, $returned);
                if ($targetId === null && $deferred) {
                    // A speculative reference the graph cannot confirm. Dropping
                    // it keeps an inference that did not pay off out of the
                    // graph, rather than inventing an external symbol for a
                    // member that may not exist. The call still reached some
                    // method of that name, so the name is kept as a call on a
                    // receiver nobody could type. A member read as a value may
                    // be a data attribute, so only a call counts.
                    $member = $edge->kind === 'calls' ? self::calledMemberName($edge->targetReference) : null;
                    if ($member !== null) {
                        $unconfirmed[$sourceId][$member] = true;
                    }
                    continue;
                }
                if ($targetId === null && !self::isResolvableReference((string) $reference)) {
                    if (!isset($warnedReferences[$reference])) {
                        $warnedReferences[$reference] = true;
                        $warnings[] = [
                            'owner' => $contribution->ownerKey,
                            'code' => self::UNRESOLVABLE_TARGET_CODE,
                            'message' => sprintf(
                                'Edge target %s names no resolvable symbol; the %s edge from %s was dropped.',
                                json_encode($reference),
                                $edge->kind,
                                $edge->sourceReference,
                            ),
                            'path' => $edge->evidence->relativePath,
                        ];
                    }
                    continue;
                }
                if ($targetId === null) {
                    [$targetId, $externalNode] = $this->externalNode(
                        $projectId,
                        $targets->spelling((string) $reference),
                        $edge->evidence,
                        $contribution->ownerKey,
                        $fileIds,
                    );
                    $external[$targetId] ??= $externalNode;
                }

                $collapse->add($edge, $sourceId, $targetId, $contribution->ownerKey);
            }
        }

        return [$external, $collapse->records(), $warnings, $unconfirmed];
    }

    /**
     * The member a method reference names (`<lang>:method:Owner::member`,
     * `<lang>:method_of_return:callee::member`,
     * `<lang>:method_of_property:Type::$property::member`), or null for any other and for
     * one whose owner or member is empty.
     */
    private static function calledMemberName(string $reference): ?string
    {
        if (!str_contains($reference, ':method:') && !str_contains($reference, ':method_of_return:') && !str_contains($reference, ':method_of_property:')) {
            return null;
        }
        [, , $target] = array_pad(explode(':', $reference, 3), 3, '');
        $separator = strrpos($target, '::');
        if ($separator === false || $separator === 0) {
            // No owner, so the reference names no receiver a call was made on.
            return null;
        }
        $member = substr($target, $separator + 2);

        return $member === '' ? null : $member;
    }

    /**
     * Adds each source node's unconfirmed calls to its `unresolved_member_calls`,
     * the names dead-code analysis reads project-wide: a method by one of them is
     * only possibly dead, as it is when a scanner could not type the receiver.
     *
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, array<string, true>> $unconfirmed source node id => member names
     */
    private static function recordUnconfirmedCalls(array &$nodes, array $unconfirmed): void
    {
        // Every source id came from the node map, so its node is here.
        foreach ($unconfirmed as $sourceId => $members) {
            $existing = $nodes[$sourceId]['attributes']['unresolved_member_calls'] ?? [];
            $names = [...(is_array($existing) ? array_values(array_filter($existing, 'is_string')) : []), ...array_map('strval', array_keys($members))];
            $names = array_values(array_unique($names));
            sort($names, SORT_STRING);
            $nodes[$sourceId]['attributes']['unresolved_member_calls'] = $names;
        }
    }

    /**
     * Whether a reference has the three non-empty segments an external node
     * needs. Mirrors the check {@see externalNode()} performs, so a caller can
     * decide to drop an edge before reaching it.
     */
    private static function isResolvableReference(string $reference): bool
    {
        $parts = explode(':', $reference, 3);

        return count($parts) === 3 && !in_array('', $parts, true);
    }

    /**
     * Build the persisted row for one reported node.
     *
     * @return array<string, mixed>
     */
    private function nodeRecord(string $id, string $language, NodeFact $node, string $owner, string $scanner): array
    {
        return [
            'id' => $id,
            'language' => $language,
            'kind' => $node->kind,
            'canonical_name' => $node->canonicalName,
            'display_name' => $node->displayName,
            'file_id' => null,
            'evidence_path' => $node->evidence->relativePath,
            'start_line' => $node->evidence->startLine,
            'end_line' => $node->evidence->endLine,
            'origin' => $node->origin->value,
            'confidence' => $node->confidence->value,
            'attributes' => $node->attributes + [
                'scanner' => $scanner,
                'scanner_local_id' => $node->localId,
            ],
            'owner_key' => $owner,
        ];
    }

    /** Synthesise a node for a referenced symbol outside the scanned tree, so the edge still has a target. */

    private function externalNode(
        string $projectId,
        string $reference,
        Evidence $evidence,
        string $owner,
        array $fileIds,
    ): array {
        $parts = explode(':', $reference, 3);
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            throw new ReconciliationException(sprintf('Unresolvable edge target reference: %s', $reference));
        }
        [$language, $kind, $canonical] = $parts;
        $externalKind = str_starts_with($kind, 'external_') ? $kind : 'external_' . $kind;
        $id = StableId::symbol($projectId, $language, $externalKind, $canonical);

        return [$id, [
            'id' => $id,
            'language' => $language,
            'kind' => $externalKind,
            'canonical_name' => $canonical,
            'display_name' => $this->displayName($canonical),
            'file_id' => $fileIds[$evidence->relativePath] ?? null,
            'start_line' => $evidence->startLine,
            'end_line' => $evidence->endLine,
            'origin' => 'derived',
            'confidence' => 'possible',
            'attributes' => ['unresolved' => true, 'reference' => $reference],
            'owner_key' => $owner,
        ]];
    }

    /**
     * Attach nodes to their file rows, which is what makes evidence paths resolvable.
     *
     * @param array<string, string> $fileIds
     */
    private function attachNodeFiles(array &$nodes, array $fileIds): void
    {
        foreach ($nodes as &$node) {
            $node['file_id'] = $fileIds[$node['evidence_path']] ?? null;
            // A file component names a file discovery leaves to no worker, so it has
            // no file row: its path is its name and its `path` attribute.
            if ($node['file_id'] === null && $node['owner_key'] !== NamedFiles::OWNER) {
                throw new ReconciliationException(sprintf(
                    'Node evidence file was not discovered: %s',
                    $node['evidence_path'],
                ));
            }
            unset($node['evidence_path']);
        }
    }

    /** The owning scanner for a fact, from its owner key. */

    private function scannerFromOwner(string $owner): string
    {
        $parts = explode(':', $owner, 2);
        return $parts[0];
    }
    /** The short name shown to a reader, derived from the canonical name. */

    private function displayName(string $canonical): string
    {
        $parts = preg_split('/(?:\\\\|::|[.#\/])/', $canonical);
        return $parts === false || $parts === [] ? $canonical : (string) end($parts);
    }
}

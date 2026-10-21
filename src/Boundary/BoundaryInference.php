<?php

declare(strict_types=1);

namespace Knossos\Boundary;

use InvalidArgumentException;
use Knossos\Discovery\ProjectUnit;
use Knossos\Discovery\UnitKind;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;

/**
 * Infers boundaries from manifests and directory structure, beside any the
 * project declares.
 *
 * Inference always runs: declared boundaries are added to the same rule set
 * rather than replacing it, so a node can sit in an explicit boundary and in
 * the inferred ones that match it. Inferred boundaries are labelled as such, so
 * they are never mistaken for an intentional architecture, and they give policy
 * checks and diagrams something to work with on an unconfigured project.
 */
final class BoundaryInference
{
    private const SYNTHETIC_NODE_KINDS = ['route', 'endpoint'];
    private const PHP_NAMESPACE_SEGMENT = '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/';
    private const PATH_SEGMENT = '/^[A-Za-z0-9_.@-]+$/';
    /**
     * Node id prefix => rule key prefix and matcher type of the rule its
     * first namespace or path segment seeds.
     */
    private const NODE_RULES = [
        'php:' => ['namespace:', 'namespace_prefix'],
        'ts:' => ['module:', 'path_prefix'],
        'py:' => ['python-package:', 'path_prefix'],
    ];

    /**
     * Infer boundaries from manifests and directory structure, and add the declared ones.
     *
     * @param list<ProjectUnit> $units
     * @param list<ScanContribution> $contributions
     * @param list<array<string, mixed>> $explicit
     * @return list<BoundaryFact>
     */
    public function infer(array $units, array $contributions, array $explicit = []): array
    {
        $nodes = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->nodes as $node) {
                $nodes[$node->localId] = $node;
            }
        }
        [$rules, $legacyIdentityCounts] = $this->manifestRules($units);
        $rules = self::disambiguateIdentities($rules, $legacyIdentityCounts);
        $rules = $this->inferredNodeRules($nodes, $rules);
        $rules = $this->explicitRules($explicit, $rules);
        ksort($rules, SORT_STRING);

        return $this->boundaryFacts(self::mergeSameMatcher($rules), $nodes);
    }

    /**
     * One rule per manifest unit, keyed by its kind and config path, with the
     * count of each pre-fix identity those rules display.
     *
     * @param list<ProjectUnit> $units
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, int>}
     */
    private function manifestRules(array $units): array
    {
        $rules = [];
        $legacyIdentityCounts = [];
        foreach ($units as $unit) {
            if (UnitKind::tryFrom($unit->kind)?->infersBoundary() !== true) {
                continue;
            }
            $directory = dirname(str_replace('\\', '/', $unit->configPath));
            $prefix = $directory === '.' ? '' : rtrim($directory, '/') . '/';
            // Keyed by the manifest's own path, never by its declared name: two
            // packages can legitimately declare the same name (a vendored or forked
            // copy), and keying on the name silently dropped one whole boundary along
            // with the path prefix that defined its members. Boundary NAMES stay
            // exactly as they were before this change ("kind:name", e.g.
            // "composer:acme/lib") — a policy's `from_boundary` resolves by name
            // (see BoundaryReferences, which also accepts a former name), so this
            // fix must not rename any boundary, only stop two of them from colliding
            // on the same internal key. TypeScript units have no declared package name
            // (tsconfig.json carries none), so they keep displaying their key, as
            // before this change.
            $rule = ['source' => 'inferred', 'matcher' => ['type' => 'path_prefix', 'value' => $prefix]];
            if ($unit->kind !== 'typescript') {
                $label = is_string($unit->metadata['name'] ?? null) ? $unit->metadata['name'] : ($prefix === '' ? 'root' : rtrim($prefix, '/'));
                $rule['display'] = $unit->kind . ':' . $label;
                // $rule['display'] is also the pre-fix rule key: every already-persisted
                // boundary's stable id was derived from exactly this string. Counting it
                // here lets disambiguateIdentities() reuse it as $identityName whenever it is
                // still unique, so the overwhelmingly common case (one manifest per
                // kind) keeps its existing id even though the array key that groups
                // rules is no longer this string.
                $legacyIdentityCounts[$rule['display']] = ($legacyIdentityCounts[$rule['display']] ?? 0) + 1;
            }
            $rules[$unit->kind . ':' . $unit->configPath] = $rule;
        }

        return [$rules, $legacyIdentityCounts];
    }

    /**
     * Pin each manifest rule's stable identity, and rename the rules whose
     * declared name another manifest shares.
     *
     * @param array<string, array<string, mixed>> $rules
     * @param array<string, int> $legacyIdentityCounts
     * @return array<string, array<string, mixed>>
     */
    private static function disambiguateIdentities(array $rules, array $legacyIdentityCounts): array
    {
        foreach ($rules as $key => $rule) {
            if (isset($rule['display']) && isset($legacyIdentityCounts[$rule['display']])) {
                // Two manifests colliding on the same declared name is exactly the case
                // this fix exists for: reusing their shared display as the stable-id basis
                // would just move the silent overwrite from the rule key to the id, so
                // fall back to each rule's own unique key instead. That fallback is
                // "path:"-prefixed so it can never collide with a genuine "kind:name"
                // display belonging to some other manifest — for example a manifest
                // literally named after another manifest's config path ("packages/a/composer.json")
                // would otherwise land the fallback in the very same string space as a
                // legacy identity and silently re-collide two distinct boundaries onto one id.
                $rules[$key]['identity'] = $legacyIdentityCounts[$rule['display']] === 1 ? $rule['display'] : ('path:' . $key);
                // The name has to differ too: boundaries are unique on
                // (project, name, source), and two `node:loc` rows failed the
                // whole scan. The manifest's directory tells them apart; a name
                // only one manifest declares is left as it always was.
                if ($legacyIdentityCounts[$rule['display']] > 1) {
                    $configPath = substr($key, strpos($key, ':') + 1);
                    $directory = dirname(str_replace('\\', '/', $configPath));
                    $rules[$key]['display'] = $rule['display'] . ' (' . ($directory === '.' ? 'root' : $directory) . ')';
                    // The name it had while it was the only one, which a policy may still use.
                    $rules[$key]['aliases'] = [$rule['display']];
                }
            }
        }

        return $rules;
    }

    /**
     * Add the namespace, module and package rules the scanned nodes imply.
     *
     * @param array<string, NodeFact> $nodes
     * @param array<string, array<string, mixed>> $rules
     * @return array<string, array<string, mixed>>
     */
    private function inferredNodeRules(array $nodes, array $rules): array
    {
        foreach ($nodes as $node) {
            // Synthetic nodes (routes, endpoints) have canonical names like
            // "GET /x => App\C::m" — structured labels, not namespaces or paths.
            // They may belong to boundaries but must never seed prefix rules.
            if (in_array($node->kind, self::SYNTHETIC_NODE_KINDS, true)) {
                continue;
            }
            foreach (self::NODE_RULES as $idPrefix => [$keyPrefix, $matcherType]) {
                if (!str_starts_with($node->localId, $idPrefix)) {
                    continue;
                }
                $top = self::topSegment($idPrefix, $node);
                if ($top !== null) {
                    $rules[$keyPrefix . $top] = ['source' => 'inferred', 'matcher' => ['type' => $matcherType, 'value' => $top . ($matcherType === 'namespace_prefix' ? '\\' : '/')]];
                }
            }
        }

        return $rules;
    }

    /**
     * The first namespace or path segment a node seeds a rule with, or null
     * when it seeds none.
     */
    private static function topSegment(string $idPrefix, NodeFact $node): ?string
    {
        if ($idPrefix === 'php:') {
            if (!str_contains($node->canonicalName, '\\')) {
                return null;
            }
            $namespace = explode('\\', ltrim($node->canonicalName, '\\'))[0];

            return $namespace !== '' && preg_match(self::PHP_NAMESPACE_SEGMENT, $namespace) === 1 ? $namespace : null;
        }
        // Python is not trimmed: Evidence refuses a path with a leading
        // separator, so unlike the TypeScript canonical name there is nothing
        // there to trim.
        $path = $idPrefix === 'ts:' ? explode('#', $node->canonicalName)[0] : $node->evidence->relativePath;
        $top = explode('/', $idPrefix === 'ts:' ? ltrim($path, '/') : $path)[0] ?? '';

        return $top !== '' && str_contains($path, '/') && preg_match(self::PATH_SEGMENT, $top) === 1 ? $top : null;
    }

    /**
     * Validate the declared boundaries and add one rule for each.
     *
     * @param list<array<string, mixed>> $explicit
     * @param array<string, array<string, mixed>> $rules
     * @return array<string, array<string, mixed>>
     */
    private function explicitRules(array $explicit, array $rules): array
    {
        $seenExplicit = [];
        foreach ($explicit as $rule) {
            if (!is_string($rule['name'] ?? null)) {
                throw new InvalidArgumentException('Explicit boundary requires a name.');
            }
            if (in_array($rule['name'], $seenExplicit, true)) {
                throw new InvalidArgumentException(sprintf('Duplicate explicit boundary name: %s.', $rule['name']));
            }
            $seenExplicit[] = $rule['name'];
            $hasPath = is_string($rule['path_prefix'] ?? null);
            $hasNamespace = is_string($rule['namespace_prefix'] ?? null);
            if ($hasPath && $hasNamespace) {
                throw new InvalidArgumentException(sprintf('Explicit boundary %s must declare either path_prefix or namespace_prefix, not both.', $rule['name']));
            }
            if ($hasPath) {
                $matcher = ['type' => 'path_prefix', 'value' => $this->pathPrefix($rule['path_prefix'])];
            } elseif ($hasNamespace) {
                $matcher = ['type' => 'namespace_prefix', 'value' => $this->namespacePrefix($rule['namespace_prefix'])];
            } else {
                throw new InvalidArgumentException('Explicit boundary requires path_prefix or namespace_prefix.');
            }
            $rules['explicit:' . $rule['name']] = ['source' => 'explicit', 'matcher' => $matcher, 'display' => $rule['name']];
        }

        return $rules;
    }

    /**
     * Keep one inferred rule per matcher, folding the others' names into it.
     *
     * @param array<string, array<string, mixed>> $rules
     * @return array<string, array<string, mixed>>
     */
    private static function mergeSameMatcher(array $rules): array
    {
        // Identical matchers produce identical member sets by construction; keep one
        // boundary per matcher. Only inferred rules merge — an explicit rule is a
        // user declaration and keeps its own identity even on a shared matcher.
        $byMatcher = [];
        foreach ($rules as $name => $rule) {
            if ($rule['source'] === 'inferred') {
                $key = $rule['matcher']['type'] . "\0" . $rule['matcher']['value'];
                if (!isset($byMatcher[$key])) {
                    $byMatcher[$key] = $name;
                } else {
                    $rules[$byMatcher[$key]]['merged_names'][] = $rule['display'] ?? $name;
                    // A merged-in rule's names (its own, the ones it had before,
                    // and the identity its stable id was derived from) stop
                    // naming a boundary of their own.
                    $rules[$byMatcher[$key]]['aliases'] = [
                        ...$rules[$byMatcher[$key]]['aliases'] ?? [],
                        ...$rule['aliases'] ?? [],
                        $rule['display'] ?? $name,
                        ...isset($rule['identity']) ? [$rule['identity']] : [],
                    ];
                    unset($rules[$name]);
                }
            }
        }

        return $rules;
    }

    /**
     * One boundary fact per rule, with the nodes it matches.
     *
     * @param array<string, array<string, mixed>> $rules
     * @param array<string, NodeFact> $nodes
     * @return list<BoundaryFact>
     */
    private function boundaryFacts(array $rules, array $nodes): array
    {
        $facts = [];
        foreach ($rules as $name => $rule) {
            $members = [];
            foreach ($nodes as $node) {
                if ($this->matches($node, $rule['matcher'])) {
                    $members[] = $node->localId;
                }
            }
            sort($members, SORT_STRING);
            $baseName = $rule['display'] ?? $name;
            $displayName = $baseName;
            // A manifest rule's pinned identity (see manifestRules()) keeps its stable id fixed
            // even though it now groups on a different, path-based array key than the
            // one that used to double as both key and display name. Rules with no
            // pinned identity (language-derived and explicit rules) fall back to null,
            // meaning "same as $name" — unchanged from before this fix.
            $identityName = $rule['identity'] ?? null;
            if (isset($rule['merged_names'])) {
                // The merged-from list is display-only: appending it to $displayName must
                // not perturb the boundary's stable id, or every scan whose merge
                // composition shifts (e.g. a package.json added next to composer.json)
                // would rename AND re-id the boundary, breaking persisted policy
                // references. $identityName pins the id to the surviving primary rule's
                // pre-suffix identity (its pinned identity when it has one, otherwise
                // its base display name).
                $displayName .= ' (+' . implode(', ', $rule['merged_names']) . ')';
                if ($identityName === null) {
                    $identityName = $baseName;
                }
                $rule['aliases'][] = $baseName;
            }
            $facts[] = new BoundaryFact($displayName, $rule['matcher'], $rule['source'], $members, $identityName, self::aliases($rule['aliases'] ?? [], $displayName));
        }

        return $facts;
    }

    /**
     * The names a boundary was known by, as {@see BoundaryFact} holds them:
     * sorted, distinct, and never its own name.
     *
     * @param list<string> $names
     * @return list<string>
     */
    private static function aliases(array $names, string $displayName): array
    {
        $aliases = array_values(array_unique(array_diff($names, [$displayName])));
        sort($aliases, SORT_STRING);

        return $aliases;
    }

    /**
     * Whether a node belongs to an inferred boundary.
     *
     * @param array{type: string, value: string} $matcher
     */
    private function matches(NodeFact $node, array $matcher): bool
    {
        if ($matcher['type'] === 'path_prefix') {
            // An external node has no source file of its own: scanners evidence it at
            // the reference site, so node:fs carries the path of whichever file
            // imported it first. A path prefix says where code lives, so letting that
            // borrowed path decide membership would make node:fs a member of that
            // importer's boundary — and every other boundary importing fs would then
            // read as depending on it. Namespace prefixes still apply: an external
            // class genuinely is in the namespace its name declares.
            //
            // An endpoint is the same: any file calling one URL names the one node,
            // so the first caller's path would put it in that caller's boundary and
            // make every other boundary calling the URL depend on it. Decided by
            // kind and not by the shared-node list: a Python or Rust package is
            // owned by its file and keeps its membership.
            return ($node->attributes['external'] ?? false) !== true
                && $node->kind !== 'endpoint'
                && str_starts_with($node->evidence->relativePath, $matcher['value']);
        }

        return str_starts_with(ltrim($node->canonicalName, '\\'), $matcher['value']);
    }

    /**
     * Anchor an explicit namespace prefix with a trailing separator so that
     * "App" matches "App\Service" but not "Apple\Service" or "AppKernel",
     * mirroring the inferred namespace rules.
     */
    private function namespacePrefix(string $prefix): string
    {
        $namespace = trim($prefix, '\\');

        return $namespace === '' ? '' : $namespace . '\\';
    }
    /** The path prefix defining an inferred boundary's membership. */

    private function pathPrefix(string $prefix): string
    {
        $prefix = trim(str_replace('\\', '/', $prefix), '/');
        foreach (explode('/', $prefix) as $segment) {
            if ($segment === '..') {
                throw new InvalidArgumentException('Boundary path prefix must be project-relative.');
            }
        }
        return $prefix === '' ? '' : $prefix . '/';
    }
}

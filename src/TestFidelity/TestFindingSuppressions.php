<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

use Knossos\Configuration\ProjectConfigurationLoader;
use Knossos\Discovery\DiscoveryException;

/**
 * The test findings a project's `test_finding_suppressions` put aside.
 *
 * Read from `knossos.json` when asked rather than frozen at scan time, so an
 * edit takes effect on the next read without a rescan. An entry names a `rule`
 * as a category (`GHOST_MEMBER`), a category and sub-rule
 * (`GHOST_MEMBER/missing_member`) or a full rule id, matched case-sensitively,
 * and optionally a `path`: a plain prefix of the double's project-relative
 * file, with no globbing.
 */
final readonly class TestFindingSuppressions
{
    /**
     * @param list<array{rule: string, path: ?string, reason: ?string}> $entries
     * @param ?string $invalid why the configuration could not be read, when it could not
     */
    public function __construct(private array $entries, private ?string $invalid = null) {}

    /** The project's suppressions; none, with the reason in {@see invalid()}, when its configuration is invalid. */
    public static function fromProjectRoot(string $root): self
    {
        try {
            return new self(ProjectConfigurationLoader::load($root, [$root])->testFindingSuppressions);
        } catch (DiscoveryException $e) {
            return new self([], $e->getMessage());
        }
    }

    /** Why the configuration was not applied, or null when it was read (or absent). */
    public function invalid(): ?string
    {
        return $this->invalid;
    }

    /** Whether a finding of this rule, category and sub-rule, on a double in this file, is suppressed. */
    public function suppresses(string $ruleId, string $category, string $subRule, string $file): bool
    {
        foreach ($this->entries as $entry) {
            $rule = $entry['rule'];
            if ($rule !== $ruleId && $rule !== $category && $rule !== $category . '/' . $subRule) {
                continue;
            }
            if ($entry['path'] === null || str_starts_with($file, $entry['path'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The WHERE fragment that keeps the findings not suppressed, over a
     * `test_findings` table under `$alias`, and its positional values, so a
     * reader filters in its query rather than after a LIMIT. A finding with no
     * file matches no `path`, as `suppresses()` with an empty file.
     *
     * @return array{0: string, 1: list<string>}
     */
    public function sqlCondition(string $alias): array
    {
        if ($this->entries === []) {
            return ['1 = 1', []];
        }
        $matches = [];
        $values = [];
        foreach ($this->entries as $entry) {
            $match = "({$alias}.rule_id = ? OR {$alias}.category = ? OR {$alias}.category || '/' || {$alias}.sub_rule = ?)";
            array_push($values, $entry['rule'], $entry['rule'], $entry['rule']);
            if ($entry['path'] !== null) {
                // Bytes, not LIKE: LIKE folds ASCII case and reads `_` and `%` as wildcards.
                $match .= " AND COALESCE(substr((SELECT relative_path FROM files WHERE id = {$alias}.file_id), 1, length(?)) = ?, 0)";
                array_push($values, $entry['path'], $entry['path']);
            }
            $matches[] = "({$match})";
        }

        return ['NOT (' . implode(' OR ', $matches) . ')', $values];
    }
}

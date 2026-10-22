<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use PDO;

/**
 * Where the component of a note on a lost name went, when the graph says so
 * without a guess: a move between namespaces or files keeps the kind and the
 * short name ({@see RenameMatching::moveSignature()}), so the one component
 * added since the name was last present that shares them is where its note
 * belongs.
 *
 * "Added since" is read against the newest retained snapshot that still held
 * the name, which also gives the name's language and kind. A name no
 * retained snapshot holds is matched by its short name alone, against what
 * the newest snapshot lacks, or against the whole active graph when no
 * snapshot is retained. Only a pair that is the one on both sides is
 * suggested: two lost names, or two components, sharing a signature leave
 * every one of them without a suggestion. A different short name (a real
 * rename) is never matched.
 */
final readonly class NoteMoveSuggestions
{
    public function __construct(private PDO $pdo) {}

    /**
     * The suggested component for each of the lost names, null for one with
     * none.
     *
     * @param list<string> $missing canonical names the active graph does not hold
     * @return array<string, ?string> by lost name
     */
    public function forMissing(string $projectId, array $missing): array
    {
        $missing = array_values(array_unique($missing));
        if ($missing === []) {
            return [];
        }
        $suggestions = array_fill_keys($missing, null);
        $active = $this->pdo->prepare('SELECT DISTINCT language, kind, canonical_name FROM nodes WHERE project_id = :project');
        $active->execute(['project' => $projectId]);
        $present = $active->fetchAll(PDO::FETCH_ASSOC);

        // Each lost name with the snapshot that last held it and its identity there.
        $unplaced = array_fill_keys($missing, true);
        $groups = [];
        $newest = null;
        foreach ($this->snapshots($projectId) as $scanId) {
            $names = [];
            $identities = [];
            try {
                (new SnapshotGraphReader($this->pdo))->eachArchivedRow($scanId, 'nodes', static function (array $row) use (&$names, &$identities, $unplaced): void {
                    $name = (string) ($row['canonical_name'] ?? '');
                    $names[$name] = true;
                    if (isset($unplaced[$name])) {
                        $identities[$name] = (string) ($row['language'] ?? '') . "\0" . (string) ($row['kind'] ?? '') . "\0" . $name;
                    }
                });
            } catch (InvalidArgumentException) {
                // A payload that cannot be read holds nothing to compare against.
                continue;
            }
            $newest ??= $names;
            if ($identities !== []) {
                $groups[] = ['names' => $names, 'removed' => array_map(RenameMatching::moveSignature(...), $identities), 'typed' => true];
                $unplaced = array_diff_key($unplaced, $identities);
            }
            if ($unplaced === []) {
                break;
            }
        }
        if ($unplaced !== []) {
            $groups[] = ['names' => $newest ?? [], 'removed' => array_map(static fn(string $name): string => self::shortName($name), array_combine(array_keys($unplaced), array_keys($unplaced))), 'typed' => false];
        }

        foreach ($groups as $group) {
            $added = [];
            foreach ($present as $node) {
                $name = (string) $node['canonical_name'];
                if (!isset($group['names'][$name])) {
                    $added[$name] = $group['typed']
                        ? RenameMatching::moveSignature((string) $node['language'] . "\0" . (string) $node['kind'] . "\0" . $name)
                        : self::shortName($name);
                }
            }
            foreach (RenameMatching::uniquePairs($group['removed'], $added, true) as $lost => $found) {
                $suggestions[$lost] = $found;
            }
        }

        return $suggestions;
    }

    /**
     * The project's complete retained snapshots, newest first.
     *
     * @return list<string> scan ids
     */
    private function snapshots(string $projectId): array
    {
        $statement = $this->pdo->prepare('SELECT scan_id FROM scan_snapshots WHERE project_id = :project AND complete = 1 ORDER BY captured_at DESC, rowid DESC');
        $statement->execute(['project' => $projectId]);

        return array_map(strval(...), $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** A name's signature with its language and kind unknown: its short name alone. */
    private static function shortName(string $name): string
    {
        $parts = explode("\0", RenameMatching::moveSignature("\0\0" . $name), 3);

        return $parts[2] ?? $name;
    }
}

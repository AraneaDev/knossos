<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use InvalidArgumentException;
use PDO;

/**
 * Where the component of a note on a lost name went, when the graph says so
 * without a guess: a move between namespaces or files keeps the language, the
 * kind and the short name ({@see RenameMatching::moveSignature()}), so the one
 * component added since the name was last present that shares them is where
 * its note belongs.
 *
 * The newest retained snapshot that still held the name gives its language,
 * kind and display name, and "added since" means absent from that snapshot.
 * A name no retained snapshot holds is suggested nothing. Only the active
 * components of that kind and display name are read as candidates, and each
 * snapshot is read a row at a time, keeping only the rows of the names asked
 * about and of the candidates.
 *
 * A pair is suggested only when it is the one on both sides: exactly one
 * added component shares the lost name's signature, and no other lost name
 * asked about in the same call, placed by the same snapshot, shares it. Lost
 * names without a note are not counted, so a removed component nobody noted
 * does not make a noted one ambiguous. A different short name (a real
 * rename) is never matched.
 */
final readonly class NoteMoveSuggestions
{
    public function __construct(private PDO $pdo) {}

    /**
     * The suggested component for each of the lost names, null for one with
     * none. No name, no read.
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
        $unplaced = array_fill_keys($missing, true);
        $reader = new SnapshotGraphReader($this->pdo);
        foreach ($this->snapshots($projectId) as $scanId) {
            // The lost names this snapshot still held, newest first: the first that holds a name places it.
            $placed = [];
            if (!$this->each($reader, $scanId, static function (array $row) use (&$placed, $unplaced): void {
                $name = (string) ($row['canonical_name'] ?? '');
                if (isset($unplaced[$name]) && !isset($placed[$name])) {
                    $placed[$name] = ['language' => (string) ($row['language'] ?? ''), 'kind' => (string) ($row['kind'] ?? ''), 'display_name' => (string) ($row['display_name'] ?? '')];
                }
            })) {
                continue;
            }
            if ($placed === []) {
                continue;
            }
            $unplaced = array_diff_key($unplaced, $placed);
            foreach ($this->pairs($projectId, $reader, $scanId, $placed) as $lost => $found) {
                $suggestions[$lost] = $found;
            }
            if ($unplaced === []) {
                break;
            }
        }

        return $suggestions;
    }

    /**
     * Each placed name paired with the one active component of its signature
     * that `$scanId`'s snapshot did not hold.
     *
     * @param array<string, array{language: string, kind: string, display_name: string}> $placed
     * @return array<string, string>
     */
    private function pairs(string $projectId, SnapshotGraphReader $reader, string $scanId, array $placed): array
    {
        $removed = [];
        $candidates = [];
        $lookup = $this->pdo->prepare('SELECT DISTINCT language, canonical_name FROM nodes WHERE project_id = :project AND kind = :kind AND display_name = :display');
        foreach ($placed as $name => $identity) {
            $removed[$name] = RenameMatching::moveSignature($identity['language'] . "\0" . $identity['kind'] . "\0" . $name);
            $lookup->execute(['project' => $projectId, 'kind' => $identity['kind'], 'display' => $identity['display_name']]);
            foreach ($lookup->fetchAll(PDO::FETCH_ASSOC) as $node) {
                $candidate = (string) $node['canonical_name'];
                $candidates[$candidate] = RenameMatching::moveSignature((string) $node['language'] . "\0" . $identity['kind'] . "\0" . $candidate);
            }
        }
        if ($candidates === []) {
            return [];
        }
        // A candidate the snapshot already held was not added since.
        $held = [];
        if (!$this->each($reader, $scanId, static function (array $row) use (&$held, $candidates): void {
            $name = (string) ($row['canonical_name'] ?? '');
            if (isset($candidates[$name])) {
                $held[$name] = true;
            }
        })) {
            return [];
        }

        return RenameMatching::uniquePairs($removed, array_diff_key($candidates, $held), true);
    }

    /**
     * Hand each node row of a snapshot to `$sink`; false when its payload
     * cannot be read, which holds nothing to compare against.
     *
     * @param Closure(array<string, mixed>): void $sink
     */
    private function each(SnapshotGraphReader $reader, string $scanId, Closure $sink): bool
    {
        try {
            $reader->eachArchivedRow($scanId, 'nodes', $sink);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
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
}

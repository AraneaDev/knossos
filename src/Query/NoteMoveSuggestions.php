<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use InvalidArgumentException;
use PDO;
use WeakMap;

/**
 * Where the component of a note on a lost name went, when the graph says so
 * without a guess: a move between namespaces or files keeps the language, the
 * kind and the short name ({@see RenameMatching::moveSignature()}), so the one
 * component added since the name was last present that shares them is where
 * its note belongs.
 *
 * The newest retained snapshot that still held the name gives its language,
 * kind and display name, and "added since" means absent from that snapshot.
 * A name no retained snapshot holds is suggested nothing, and remembered
 * so in this process until the project's next scan: such a name, an orphan
 * older than retention, would otherwise have every snapshot read again on
 * each call. Only the active components of that kind and display name are
 * read as candidates, and each snapshot is read once, a row at a time,
 * keeping only the rows of the names asked about and of the components that
 * share a short name with them.
 *
 * A pair is suggested only when it is the one on both sides: exactly one
 * added component shares the lost name's signature, and no other lost name
 * asked about in the same call, placed by the same snapshot, shares it. Lost
 * names without a note are not counted, so a removed component nobody noted
 * does not make a noted one ambiguous. A different short name (a real
 * rename) is never matched.
 */
final class NoteMoveSuggestions
{
    /**
     * The names no retained snapshot held, by connection and project, with
     * the active scan and the snapshots they were looked for in.
     *
     * @var ?WeakMap<PDO, array<string, array{key: string, names: array<string, true>}>>
     */
    private static ?WeakMap $unheld = null;

    public function __construct(private readonly PDO $pdo) {}

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
        $snapshots = $this->snapshots($projectId);
        $key = $this->activeScan($projectId) . "\0" . implode("\0", $snapshots);
        self::$unheld ??= new WeakMap();
        $known = self::$unheld[$this->pdo][$projectId] ?? null;
        $unheld = $known !== null && $known['key'] === $key ? $known['names'] : [];
        $unplaced = array_diff_key(array_fill_keys($missing, true), $unheld);
        $reader = new SnapshotGraphReader($this->pdo);
        foreach ($unplaced === [] ? [] : $snapshots as $scanId) {
            // The lost names this snapshot still held, newest first: the first
            // that holds a name places it. The names it held that share a
            // short name with one of them, so a candidate it held is known
            // without reading it again.
            $tails = [];
            foreach (array_keys($unplaced) as $name) {
                $tails[self::tail((string) $name)] = true;
            }
            $placed = [];
            $held = [];
            if (!$this->each($reader, $scanId, static function (array $row) use (&$placed, &$held, $unplaced, $tails): void {
                $name = (string) ($row['canonical_name'] ?? '');
                if (isset($unplaced[$name]) && !isset($placed[$name])) {
                    $placed[$name] = ['language' => (string) ($row['language'] ?? ''), 'kind' => (string) ($row['kind'] ?? ''), 'display_name' => (string) ($row['display_name'] ?? '')];
                }
                if (isset($tails[self::tail($name)])) {
                    $held[$name] = true;
                }
            })) {
                continue;
            }
            if ($placed === []) {
                continue;
            }
            $unplaced = array_diff_key($unplaced, $placed);
            foreach ($this->pairs($projectId, $placed, $held) as $lost => $found) {
                $suggestions[$lost] = $found;
            }
            if ($unplaced === []) {
                break;
            }
        }
        $map = self::$unheld[$this->pdo] ?? [];
        $map[$projectId] = ['key' => $key, 'names' => $unheld + $unplaced];
        self::$unheld[$this->pdo] = $map;

        return $suggestions;
    }

    /**
     * Each placed name paired with the one active component of its signature
     * that the snapshot placing them did not hold.
     *
     * @param array<string, array{language: string, kind: string, display_name: string}> $placed
     * @param array<string, true> $held the snapshot's names that share a short name with one placed
     * @return array<string, string>
     */
    private function pairs(string $projectId, array $placed, array $held): array
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

    /** A canonical name's short name, as {@see RenameMatching::moveSignature()} compares it. */
    private static function tail(string $name): string
    {
        return explode("\0", RenameMatching::moveSignature("\0\0" . $name), 3)[2];
    }

    /** The project's active scan, or an empty string when it has none. */
    private function activeScan(string $projectId): string
    {
        $statement = $this->pdo->prepare('SELECT active_scan_id FROM projects WHERE id = :project');
        $statement->execute(['project' => $projectId]);

        return (string) $statement->fetchColumn();
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

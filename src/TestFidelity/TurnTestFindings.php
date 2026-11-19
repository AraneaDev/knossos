<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

use PDO;

/**
 * The definite test findings a Claude Code turn touches, and the section of
 * the turn brief that names the ones it introduced.
 *
 * A finding is touched when its double sits in an edited file or its target
 * does: a stale mock usually lives in a test file the turn never edited, and
 * the stored target is the `doubles` edge followed back to production code.
 * A double whose module resolves to nothing has no target; it touches the
 * files its raw specifier can name ({@see MockedModulePaths}), and a deleted
 * one of those (a renamed module's old path) is the module the turn took away.
 * "Introduced" is the keys after the turn's rescan minus the keys before it;
 * a key carries no line, so a finding that only moved is not introduced.
 *
 * When another writer's scan (the live watcher's, mostly) took an edit in
 * before the brief, the keys before the turn come from the {@see \Knossos\Scan\ScanLedger}:
 * every ledgered scan keeps, for each file it changed, the keys that file
 * held before it ({@see self::held()}, {@see self::baselines()}). Keys read
 * from a scan the test rules never judged say nothing, so none are kept
 * ({@see self::judged()}).
 */
final class TurnTestFindings
{
    /** Findings listed in the brief; the total counts them all. */
    public const MAX_TEST_FINDINGS = 10;

    /** Keys the ledger keeps per file; past it the file's baseline is cut and a turn that edits it is not judged. */
    public const MAX_BASELINE_KEYS = 100;

    /** The section's status when the turn's findings were compared. */
    public const OK = 'ok';

    /** The section's status when the turn's baseline is unknown. */
    public const NOT_EVALUATED = 'not_evaluated';

    /**
     * The definite, unsuppressed findings whose double's file or target's file is one of `$editedFiles`,
     * or whose double's unresolved module can name one of `$deletedFiles`.
     *
     * @param list<string> $editedFiles
     * @param list<string> $deletedFiles
     * @return list<array{key: string, category: string, sub_rule: string, message: string, file: ?string, line: ?int, target_file: ?string}>
     */
    public static function affected(PDO $pdo, string $projectId, array $editedFiles, TestFindingSuppressions $suppressions, array $deletedFiles = []): array
    {
        if ($editedFiles === [] && $deletedFiles === []) {
            return [];
        }
        $edited = array_flip($editedFiles);
        $deleted = array_flip($deletedFiles);
        $affected = [];
        foreach ((new ActiveTestFindings($pdo, $projectId, $suppressions))->find(['confidence' => FindingConfidence::Definite->value]) as $finding) {
            $stranded = array_filter(self::modulePaths($finding), static fn(string $path): bool => isset($deleted[$path])) !== [];
            if ($stranded || ($finding['file'] !== null && isset($edited[$finding['file']])) || ($finding['target_file'] !== null && isset($edited[$finding['target_file']]))) {
                $affected[] = [
                    'key' => $finding['key'], 'category' => $finding['category'], 'sub_rule' => $finding['sub_rule'], 'message' => $finding['message'],
                    'file' => $finding['file'], 'line' => $finding['line'], 'target_file' => $finding['target_file'],
                ];
            }
        }

        return $affected;
    }

    /**
     * Every file's keys as {@see self::affected()} would find them for that
     * one file, edited or deleted: the definite, unsuppressed findings whose
     * double's file or target's file it is, or that its unresolved module can
     * name. Files that touch none are left out.
     *
     * @return array<string, list<string>> path => keys, sorted
     */
    public static function held(PDO $pdo, string $projectId, TestFindingSuppressions $suppressions): array
    {
        $held = [];
        foreach ((new ActiveTestFindings($pdo, $projectId, $suppressions))->find(['confidence' => FindingConfidence::Definite->value]) as $finding) {
            foreach (array_unique([...array_filter([$finding['file'], $finding['target_file']], static fn(?string $file): bool => $file !== null), ...self::modulePaths($finding)]) as $file) {
                $held[$file][$finding['key']] = true;
            }
        }
        $sorted = [];
        foreach ($held as $file => $keys) {
            $keys = array_map('strval', array_keys($keys));
            sort($keys);
            $sorted[(string) $file] = $keys;
        }

        return $sorted;
    }

    /**
     * {@see self::held()} as the ledger keeps it: at most {@see self::MAX_BASELINE_KEYS} keys a file, `truncated` past it.
     *
     * @param array<string, list<string>> $held
     * @return array<string, array{keys: list<string>, truncated: bool}>
     */
    public static function baselines(array $held): array
    {
        return array_map(static fn(array $keys): array => ['keys' => array_slice($keys, 0, self::MAX_BASELINE_KEYS), 'truncated' => count($keys) > self::MAX_BASELINE_KEYS], $held);
    }

    /**
     * {@see self::baselines()} of every file of the project at `$root`, read with its own suppressions: what a ledgered scan keeps.
     * Null when the test rules never judged the active scan, whose findings then say nothing.
     *
     * @return array<string, array{keys: list<string>, truncated: bool}>|null
     */
    public static function baselinesOf(PDO $pdo, string $projectId, string $root): ?array
    {
        return self::judged($pdo, $projectId) ? self::baselines(self::held($pdo, $projectId, ActiveTestFindings::suppressionsOf($root))) : null;
    }

    /**
     * Whether the test rules judged the project's active scan. A scan from
     * before them (the one active when a database is upgraded) has no
     * findings because they never ran, not because there were none.
     */
    public static function judged(PDO $pdo, string $projectId): bool
    {
        $statement = $pdo->prepare('SELECT s.test_findings_judged FROM projects p JOIN scans s ON s.id = p.active_scan_id WHERE p.id = :project');
        $statement->execute(['project' => $projectId]);

        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * The keys the reported files touched when the turn began; null when that cannot be told.
     *
     * A file no scan since the turn began took in is judged by what it holds
     * now. A file one did take in is judged by the keys the ledger kept for it
     * from before the first such scan; the turn is not judged when there are
     * none (a scan that did not read them or was never judged, a merged
     * entry) or they were cut.
     *
     * A key counts as before only when every taken-in file that holds it now
     * held it then: each such file's first scan may come after another's that
     * made the key (the watcher takes the production edit in, then the spec's
     * save), and that later baseline already holds it.
     *
     * @param list<string> $reported
     * @param array<string, list<string>> $held {@see self::held()}, read before the brief's own scan
     * @param array{before: array<string, string|null>, test_baselines: array<string, array{keys: list<string>, truncated: bool}|null>}|null $absorbed
     *        what the ledger says scans since the turn began took in ({@see \Knossos\Scan\ScanLedger::since()}); null when it cannot say
     * @return list<string>|null
     */
    public static function before(array $reported, array $held, ?array $absorbed): ?array
    {
        if ($absorbed === null) {
            return null;
        }
        $keys = [];
        $missing = [];
        foreach ($reported as $file) {
            if (!array_key_exists($file, $absorbed['before'])) {
                array_push($keys, ...$held[$file] ?? []);
                continue;
            }
            $baseline = $absorbed['test_baselines'][$file] ?? null;
            if ($baseline === null || $baseline['truncated']) {
                return null;
            }
            array_push($keys, ...$baseline['keys']);
            array_push($missing, ...array_diff($held[$file] ?? [], $baseline['keys']));
        }
        $missing = array_flip($missing);

        return array_values(array_filter(array_unique($keys), static fn(string $key): bool => !isset($missing[$key])));
    }

    /**
     * The files a finding's double can name by its raw module specifier, when the module resolved to no target.
     *
     * @param array{file: ?string, target_canonical: ?string, attributes: array<string, mixed>} $finding
     * @return list<string>
     */
    private static function modulePaths(array $finding): array
    {
        $specifier = $finding['attributes']['specifier'] ?? null;

        return $finding['file'] === null || $finding['target_canonical'] !== null || !is_string($specifier) ? [] : MockedModulePaths::of($finding['file'], $specifier);
    }

    /**
     * The brief's `test_findings`: what `$after` holds that `$before` did not, by key.
     * `total_stale` counts the introduced findings of a stale category ({@see StaleCategories}), uncapped like `total`.
     *
     * @param list<string>|null $before the keys before the turn; null when the turn's baseline is unknown
     * @param list<array{key: string, category: string, message: string, file: ?string, line: ?int}> $after
     * @return array{status: string, introduced: list<array{key: string, file: string, line: int, message: string, stale: bool}>, total: int, total_stale: int, truncated: bool, suppressions_invalid: ?string}
     */
    public static function section(?array $before, array $after, ?string $suppressionsInvalid): array
    {
        if ($before === null) {
            return self::notEvaluated($suppressionsInvalid);
        }
        $known = array_flip($before);
        $introduced = [];
        foreach ($after as $finding) {
            if (!isset($known[$finding['key']])) {
                $introduced[$finding['key']] = $finding;
            }
        }
        $ordered = array_values($introduced);
        usort($ordered, static fn(array $a, array $b): int => [(string) $a['file'], $a['line'] ?? 0, $a['key']] <=> [(string) $b['file'], $b['line'] ?? 0, $b['key']]);

        return [
            'status' => self::OK,
            'introduced' => array_map(static fn(array $f): array => ['key' => $f['key'], 'file' => $f['file'] ?? '', 'line' => $f['line'] ?? 0, 'message' => $f['message'], 'stale' => StaleCategories::isStale($f['category'])], array_slice($ordered, 0, self::MAX_TEST_FINDINGS)),
            'total' => count($ordered),
            'total_stale' => count(array_filter($ordered, static fn(array $f): bool => StaleCategories::isStale($f['category']))),
            'truncated' => count($ordered) > self::MAX_TEST_FINDINGS,
            'suppressions_invalid' => $suppressionsInvalid,
        ];
    }

    /**
     * The section of a turn whose baseline is unknown: nothing is claimed introduced.
     *
     * @return array{status: string, introduced: list<array{key: string, file: string, line: int, message: string, stale: bool}>, total: int, total_stale: int, truncated: bool, suppressions_invalid: ?string}
     */
    public static function notEvaluated(?string $suppressionsInvalid = null): array
    {
        return ['status' => self::NOT_EVALUATED, 'introduced' => [], 'total' => 0, 'total_stale' => 0, 'truncated' => false, 'suppressions_invalid' => $suppressionsInvalid];
    }
}

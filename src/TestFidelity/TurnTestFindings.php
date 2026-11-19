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
 * "Introduced" is the keys after the turn's rescan minus the keys before it;
 * a key carries no line, so a finding that only moved is not introduced.
 */
final class TurnTestFindings
{
    /** Findings listed in the brief; the total counts them all. */
    public const MAX_TEST_FINDINGS = 10;

    /** The section's status when the turn's findings were compared. */
    public const OK = 'ok';

    /** The section's status when the turn's baseline is unknown. */
    public const NOT_EVALUATED = 'not_evaluated';

    /**
     * The definite, unsuppressed findings whose double's file or target's file is one of `$editedFiles`.
     *
     * @param list<string> $editedFiles
     * @return list<array{key: string, category: string, sub_rule: string, message: string, file: ?string, line: ?int, target_file: ?string}>
     */
    public static function affected(PDO $pdo, string $projectId, array $editedFiles, ?TestFindingSuppressions $suppressions = null): array
    {
        if ($editedFiles === []) {
            return [];
        }
        $suppressions ??= self::suppressionsOf($pdo, $projectId);
        $edited = array_flip($editedFiles);
        $affected = [];
        foreach ((new ActiveTestFindings($pdo, $projectId, $suppressions))->find(['confidence' => FindingConfidence::Definite->value]) as $finding) {
            if (($finding['file'] !== null && isset($edited[$finding['file']])) || ($finding['target_file'] !== null && isset($edited[$finding['target_file']]))) {
                $affected[] = [
                    'key' => $finding['key'], 'category' => $finding['category'], 'sub_rule' => $finding['sub_rule'], 'message' => $finding['message'],
                    'file' => $finding['file'], 'line' => $finding['line'], 'target_file' => $finding['target_file'],
                ];
            }
        }

        return $affected;
    }

    /**
     * The brief's `test_findings`: what `$after` holds that `$before` did not, by key.
     *
     * @param list<array{key: string, message: string, file: ?string, line: ?int}>|null $before null when the turn's baseline is unknown
     * @param list<array{key: string, message: string, file: ?string, line: ?int}> $after
     * @return array{status: string, introduced: list<array{key: string, file: string, line: int, message: string}>, total: int, truncated: bool, suppressions_invalid: ?string}
     */
    public static function section(?array $before, array $after, ?string $suppressionsInvalid): array
    {
        if ($before === null) {
            return self::notEvaluated($suppressionsInvalid);
        }
        $known = array_flip(array_column($before, 'key'));
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
            'introduced' => array_map(static fn(array $f): array => ['key' => $f['key'], 'file' => $f['file'] ?? '', 'line' => $f['line'] ?? 0, 'message' => $f['message']], array_slice($ordered, 0, self::MAX_TEST_FINDINGS)),
            'total' => count($ordered),
            'truncated' => count($ordered) > self::MAX_TEST_FINDINGS,
            'suppressions_invalid' => $suppressionsInvalid,
        ];
    }

    /**
     * The section of a turn whose baseline is unknown: nothing is claimed introduced.
     *
     * @return array{status: string, introduced: list<array{key: string, file: string, line: int, message: string}>, total: int, truncated: bool, suppressions_invalid: ?string}
     */
    public static function notEvaluated(?string $suppressionsInvalid = null): array
    {
        return ['status' => self::NOT_EVALUATED, 'introduced' => [], 'total' => 0, 'truncated' => false, 'suppressions_invalid' => $suppressionsInvalid];
    }

    /** The suppressions of the project's folder, none when the project is unknown. */
    public static function suppressionsOf(PDO $pdo, string $projectId): TestFindingSuppressions
    {
        $statement = $pdo->prepare('SELECT root_realpath FROM projects WHERE id = :id');
        $statement->execute(['id' => $projectId]);
        $root = $statement->fetchColumn();

        return is_string($root) ? ActiveTestFindings::suppressionsOf($root) : new TestFindingSuppressions([]);
    }
}

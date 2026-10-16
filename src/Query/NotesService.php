<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use InvalidArgumentException;
use Knossos\Scan\ProjectPathResolver;
use Knossos\Store\SqliteBusy;
use Knossos\Store\SqliteTransactions;
use PDO;
use RuntimeException;
use Throwable;

/**
 * The pane's Notes tab: every annotation in the project, grouped so what
 * needs the person comes first, and the writes the tab offers on one.
 *
 * The groups, in order: `missing` (on a name the active graph no longer
 * holds, left behind by a rename or a move), `stale` (a model note whose
 * files changed since it was written), then `rest`, the person's before the
 * model's; each newest first. A person's `check` request is no row of its
 * own: it marks the rows of its component `checked`, with its reason.
 *
 * The writes go through {@see AnnotationService}, previewed unless
 * `$execute`. A refusal says why (`refused`); a write that met a scan's
 * lock answers `error` with {@see SqliteBusy::CODE}, so the pane keeps what
 * was typed and says to retry. Any other failure is thrown, for the command
 * around it to answer. A move copies the stored note to its new name and removes the old,
 * both by their exact names, in one transaction that takes the write lock
 * first. Adopting and flagging refuse a name the graph resolves to another
 * component, so a note left on a short name is never written over another's.
 */
final readonly class NotesService
{
    /** What the tab can narrow to. */
    public const FILTERS = ['all', 'yours', 'model', 'stale', 'missing', 'flagged'];

    /** The most rows read; `truncated` says when there are more. */
    public const MAX_ROWS = 2000;

    /** @param PDO $pdo an existing, migrated graph database */
    public function __construct(private PDO $pdo) {}

    /**
     * The project's notes, grouped and narrowed by `$filter` and by `$text`
     * (held, in any case, by the component's name or the note), and the
     * counts the Overview line reads, which never narrow.
     *
     * @return array<string, mixed>
     */
    public function notes(string $path, string $filter = 'all', string $text = ''): array
    {
        $envelope = ['filter' => $filter, 'text' => $text, 'groups' => ['missing' => [], 'stale' => [], 'rest' => []], 'counts' => self::zero(), 'truncated' => false];

        return $this->forProject($path, $envelope, function (string $projectId) use ($filter, $text): array {
            if (!in_array($filter, self::FILTERS, true)) {
                throw new InvalidArgumentException('filter must be one of: ' . implode(', ', self::FILTERS) . '.');
            }
            [$rows, $truncated] = $this->rows($projectId);
            $needle = mb_strtolower(trim($text));
            $shown = array_filter($rows, static fn(array $row): bool => self::kept($row, $filter) && ($needle === '' || str_contains(mb_strtolower($row['component'] . "\n" . $row['value']), $needle)));
            $groups = ['missing' => [], 'stale' => [], 'rest' => []];
            foreach ($shown as $row) {
                $groups[$row['component_status'] === 'missing' ? 'missing' : ($row['stale'] ? 'stale' : 'rest')][] = $row;
            }
            $newest = static fn(array $a, array $b): int => [$b['updated_at'], $a['component']] <=> [$a['updated_at'], $b['component']];
            usort($groups['missing'], $newest);
            usort($groups['stale'], $newest);
            usort($groups['rest'], static fn(array $a, array $b): int => [$a['author'] === 'user' ? 0 : 1, $b['updated_at'], $a['component']] <=> [$b['author'] === 'user' ? 0 : 1, $a['updated_at'], $b['component']]);

            return ['status' => 'ok', 'groups' => $groups, 'counts' => $this->counts($projectId), 'truncated' => $truncated];
        });
    }

    /**
     * How many notes the project holds, how many sit on a missing name, how
     * many of the model's are stale, and how many the person flagged.
     *
     * @return array{total: int, missing: int, stale: int, flagged: int}
     */
    public function counts(string $projectId): array
    {
        [$rows] = $this->rows($projectId);
        $flagged = $this->pdo->prepare("SELECT COUNT(*) FROM annotations WHERE project_id = :project AND kind = :kind AND author = 'user'");
        $flagged->execute(['project' => $projectId, 'kind' => AnnotationService::CHECK]);

        return [
            'total' => count($rows),
            'missing' => count(array_filter($rows, static fn(array $row): bool => $row['component_status'] === 'missing')),
            'stale' => count(array_filter($rows, static fn(array $row): bool => $row['component_status'] === 'present' && $row['stale'])),
            'flagged' => (int) $flagged->fetchColumn(),
        ];
    }

    /**
     * The model's note on `$component` made the person's: `$value` as edited,
     * else the model's own text. The model's note stays; the person's then
     * wins and never goes stale.
     *
     * @return array<string, mixed>
     */
    public function adopt(string $path, string $component, ?string $value = null, bool $execute = false): array
    {
        return $this->write($path, function (ArchitectureQueryService $queries, string $projectId) use ($component, $value, $execute): array {
            $model = $this->stored($projectId, $component, 'note', 'model');
            if ($model === null) {
                throw new InvalidArgumentException(sprintf('%s has no note by the model to adopt.', $component));
            }
            self::assertNamesItself($queries, $projectId, $component);

            return self::answered($queries->upsertAnnotation($projectId, $component, 'note', $value ?? $model, $execute, 'user')->data, 'adopt');
        });
    }

    /**
     * `$author`'s `$kind` note on `$component` moved to `$to`: the stored row
     * copied there, then removed here, together, both by their exact names.
     * The note keeps what it was written against, so a model note reads stale
     * where it lands until the model looks, and a check on `$to` stays: the
     * move is the person's, not the model's look. Refused, before anything is
     * written, when `$to` is a name the graph does not hold either, or
     * already holds a note of that kind by that author.
     *
     * @return array<string, mixed>
     */
    public function move(string $path, string $component, string $to, string $kind = 'note', string $author = 'user', bool $execute = false): array
    {
        return $this->write($path, fn(ArchitectureQueryService $queries, string $projectId): array => (new SqliteTransactions($this->pdo))->run(function () use ($projectId, $component, $to, $kind, $author, $execute): array {
            $source = $this->pdo->prepare('SELECT value, basis_json, created_at FROM annotations WHERE project_id = :project AND canonical_name = :name AND kind = :kind AND author = :author');
            $source->execute(['project' => $projectId, 'name' => $component, 'kind' => $kind, 'author' => $author]);
            $row = $source->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new InvalidArgumentException(sprintf('%s has no %s by the %s to move.', $component, $kind, $author));
            }
            $present = $this->pdo->prepare('SELECT 1 FROM nodes WHERE project_id = :project AND canonical_name = :name LIMIT 1');
            $present->execute(['project' => $projectId, 'name' => $to]);
            if ($present->fetchColumn() === false) {
                throw new InvalidArgumentException(sprintf('%s is not in the graph either: pick a component it holds.', $to));
            }
            if ($this->stored($projectId, $to, $kind, $author) !== null) {
                throw new InvalidArgumentException(sprintf('%s already holds a %s by the %s: edit or remove that one first.', $to, $kind, $author));
            }
            $answer = ['status' => 'ok', 'action' => 'move', 'executed' => $execute, 'component' => $component, 'to' => $to, 'kind' => $kind, 'author' => $author, 'value' => (string) $row['value']];
            if (!$execute) {
                return $answer;
            }
            $this->pdo->prepare(
                'INSERT INTO annotations(project_id, canonical_name, kind, value, author, basis_json, created_at, updated_at) ' .
                'VALUES (:project, :to, :kind, :value, :author, :basis, :created, :now)',
            )->execute(['project' => $projectId, 'to' => $to, 'kind' => $kind, 'value' => $row['value'], 'author' => $author, 'basis' => $row['basis_json'], 'created' => $row['created_at'], 'now' => gmdate('Y-m-d\TH:i:s\Z')]);
            $removed = $this->pdo->prepare('DELETE FROM annotations WHERE project_id = :project AND canonical_name = :name AND kind = :kind AND author = :author');
            $removed->execute(['project' => $projectId, 'name' => $component, 'kind' => $kind, 'author' => $author]);
            if ($removed->rowCount() !== 1) {
                throw new RuntimeException(sprintf('The note on %s changed while it was moved; nothing was moved.', $component));
            }

            return $answer;
        }));
    }

    /**
     * The person's request that the model look at `$component`'s note again,
     * with `$value` as the reason, or, with `$remove`, the request taken back.
     *
     * @return array<string, mixed>
     */
    public function check(string $path, string $component, string $value = '', bool $remove = false, bool $execute = false): array
    {
        return $this->write($path, static function (ArchitectureQueryService $queries, string $projectId) use ($component, $value, $remove, $execute): array {
            self::assertNamesItself($queries, $projectId, $component);

            return self::answered(
                $remove
                    ? $queries->removeAnnotation($projectId, $component, AnnotationService::CHECK, '', $execute, 'user')->data
                    : $queries->upsertAnnotation($projectId, $component, AnnotationService::CHECK, $value, $execute, 'user')->data,
                $remove ? 'uncheck' : 'check',
            );
        });
    }

    /**
     * Refuse `$component` when a write on it would land on another
     * component: the annotation service resolves a name no component holds
     * by the names the graph shows (`Target` for `App\Target`), so a note
     * left on such a short name would be written over that component's.
     * Asked of the service's own preview, so the two never disagree.
     */
    public static function assertNamesItself(ArchitectureQueryService $queries, string $projectId, string $component): void
    {
        $lands = (string) $queries->upsertAnnotation($projectId, $component, 'note', '', false, 'user')->data['component'];
        if ($lands !== $component) {
            throw new InvalidArgumentException(sprintf('%s names %s in the graph: move the note there first.', $component, $lands));
        }
    }

    /**
     * Every annotation but the person's check requests, each with its
     * component's presence, whether it is stale and why, and whether the
     * person flagged its component; at most {@see self::MAX_ROWS}.
     *
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function rows(string $projectId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT a.canonical_name, a.kind, a.value, a.author, a.basis_json, a.updated_at, ' .
            "CASE WHEN EXISTS (SELECT 1 FROM nodes n WHERE n.project_id = a.project_id AND n.canonical_name = a.canonical_name) THEN 'present' ELSE 'missing' END AS component_status, " .
            "(SELECT c.value FROM annotations c WHERE c.project_id = a.project_id AND c.canonical_name = a.canonical_name AND c.kind = :check AND c.author = 'user') AS check_reason " .
            'FROM annotations a WHERE a.project_id = :project AND a.kind <> :check ORDER BY a.canonical_name, a.kind, a.author LIMIT ' . (self::MAX_ROWS + 1),
        );
        $statement->execute(['project' => $projectId, 'check' => AnnotationService::CHECK]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $truncated = count($rows) > self::MAX_ROWS;
        $rows = array_slice($rows, 0, self::MAX_ROWS);
        $reasons = (new AnnotationNotes($this->pdo))->staleness($projectId, $rows);

        return [array_map(static fn(array $row, ?string $reason): array => [
            'component' => (string) $row['canonical_name'],
            'kind' => (string) $row['kind'],
            'author' => (string) $row['author'],
            'value' => (string) $row['value'],
            'updated_at' => (string) $row['updated_at'],
            'component_status' => (string) $row['component_status'],
            'stale' => $reason !== null,
            'stale_reason' => $reason,
            'checked' => $row['check_reason'] !== null,
            'check_reason' => $row['check_reason'] === null ? null : (string) $row['check_reason'],
        ], $rows, $reasons), $truncated];
    }

    /** Whether a row passes the tab's filter. @param array<string, mixed> $row */
    private static function kept(array $row, string $filter): bool
    {
        return match ($filter) {
            'yours' => $row['author'] === 'user',
            'model' => $row['author'] === 'model',
            'stale' => $row['component_status'] === 'present' && $row['stale'],
            'missing' => $row['component_status'] === 'missing',
            'flagged' => $row['checked'],
            default => true,
        };
    }

    /** The text `$author` stored as `$kind` on `$component`, or null. */
    private function stored(string $projectId, string $component, string $kind, string $author): ?string
    {
        $statement = $this->pdo->prepare('SELECT value FROM annotations WHERE project_id = :project AND canonical_name = :name AND kind = :kind AND author = :author');
        $statement->execute(['project' => $projectId, 'name' => $component, 'kind' => $kind, 'author' => $author]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    /**
     * A write's answer for the pane.
     *
     * @param array<string, mixed> $data the annotation service's result data
     * @return array<string, mixed>
     */
    private static function answered(array $data, string $action): array
    {
        if (($data['action'] ?? null) === 'refused') {
            return ['status' => 'refused', 'reason' => sprintf('%s holds a different model note.', (string) $data['component'])];
        }

        return [
            'status' => 'ok',
            'action' => $action,
            'executed' => (bool) $data['executed'],
            'component' => (string) $data['component'],
            'kind' => (string) $data['kind'],
            'value' => is_array($data['annotation'] ?? null) ? (string) $data['annotation']['value'] : null,
            'previous' => is_array($data['previous'] ?? null) ? (string) $data['previous']['value'] : null,
        ];
    }

    /**
     * A write for the project owning `$path`, its refusal or failure said as an answer.
     *
     * @param Closure(ArchitectureQueryService, string): array<string, mixed> $write
     * @return array<string, mixed>
     */
    private function write(string $path, Closure $write): array
    {
        return $this->forProject($path, [], function (string $projectId) use ($write): array {
            try {
                return $write(ArchitectureQueryService::forDatabase($this->pdo), $projectId);
            } catch (InvalidArgumentException $refused) {
                return ['status' => 'refused', 'reason' => $refused->getMessage()];
            } catch (Throwable $failed) {
                return self::busy($failed);
            }
        });
    }

    /**
     * The answer for a write that met another process's write lock, or the
     * failure thrown on when it is anything else.
     *
     * @return array{status: string, code: string, message: string}
     */
    public static function busy(Throwable $failed): array
    {
        if (!SqliteBusy::anywhere($failed)) {
            throw $failed;
        }

        return ['status' => 'error', 'code' => SqliteBusy::CODE, 'message' => SqliteBusy::MESSAGE];
    }

    /**
     * `$answer` for the project owning `$path`, with its envelope; `unscanned`
     * when there is none, `refused` when the answer refuses its arguments.
     *
     * @param array<string, mixed> $envelope the fields every answer carries
     * @param Closure(string): array<string, mixed> $answer
     * @return array<string, mixed>
     */
    private function forProject(string $path, array $envelope, Closure $answer): array
    {
        $absolute = realpath($path) ?: $path;
        $envelope = ['path' => $absolute, 'project_id' => null, 'snapshot_id' => null] + $envelope;
        $project = (new ProjectPathResolver($this->pdo))->resolve($absolute);
        if ($project === null || !is_string($project['active_scan_id']) || $project['active_scan_id'] === '') {
            return ['status' => 'unscanned'] + $envelope;
        }
        $envelope = ['project_id' => (string) $project['id'], 'snapshot_id' => $project['active_scan_id']] + $envelope;
        try {
            return $answer((string) $project['id']) + $envelope;
        } catch (InvalidArgumentException $refused) {
            return ['status' => 'refused', 'reason' => $refused->getMessage()] + $envelope;
        }
    }

    /**
     * The counts of a project with no notes, or of no project.
     *
     * @return array{total: int, missing: int, stale: int, flagged: int}
     */
    private static function zero(): array
    {
        return ['total' => 0, 'missing' => 0, 'stale' => 0, 'flagged' => 0];
    }
}

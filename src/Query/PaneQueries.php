<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use Knossos\Scan\ProjectPathResolver;
use PDO;

/**
 * The architecture pane's reads that look past one component's detail, and
 * its writes: `churn` (the files changed most that much depends on),
 * `blast-radius` (a component's dependents in rings), `path-between` (the
 * routes from one component to another), `annotate` (a note on a
 * component, previewed unless `execute` says to record it), and the Notes
 * tab's `notes`, `note-adopt`, `note-move`, `note-check` and `note-confirm`
 * ({@see NotesService}).
 *
 * A write that meets a scan's lock answers `error` with
 * {@see \Knossos\Store\SqliteBusy::CODE}, so the pane can tell it from a
 * fault and keep what the person typed.
 *
 * Each answers for the project owning a path, as the other pane commands
 * do; the command around it has already checked that the database exists.
 * `annotate` writes only when `execute` is given, which the pane passes
 * only after the person confirmed the preview in it.
 */
final readonly class PaneQueries
{
    /** The commands answered here. */
    public const COMMANDS = ['churn', 'blast-radius', 'path-between', 'annotate', 'notes', 'note-adopt', 'note-move', 'note-check', 'note-confirm'];

    /** @param PDO $pdo an existing, migrated graph database */
    public function __construct(private PDO $pdo) {}

    /**
     * The options each command takes, beside `db` and `json`.
     *
     * @return list<string>
     */
    public static function options(string $command): array
    {
        return match ($command) {
            'blast-radius' => ['component'],
            'path-between' => ['from', 'to'],
            'annotate' => ['component', 'kind', 'value', 'remove', 'author', 'execute'],
            'notes' => ['filter', 'text'],
            'note-adopt' => ['component', 'value', 'execute'],
            'note-move' => ['component', 'to', 'kind', 'author', 'execute'],
            'note-check' => ['component', 'value', 'remove', 'execute'],
            'note-confirm' => ['component', 'execute'],
            default => [],
        };
    }

    /**
     * The answer to `$command` for the project owning `$path`. `$single`
     * reads an option's one value (null when absent); `$flag` whether a
     * flag was given.
     *
     * @param \Closure(string): ?string $single
     * @param \Closure(string): bool $flag
     * @return array<string, mixed>
     */
    public function answer(string $command, string $path, \Closure $single, \Closure $flag): array
    {
        $required = static fn(string $name): string => $single($name) ?? throw new InvalidArgumentException(sprintf('--%s is required.', $name));

        return match ($command) {
            'churn' => (new ChurnService($this->pdo))->churn($path),
            'blast-radius' => (new BlastRadiusService($this->pdo))->rings($path, $required('component')),
            'path-between' => (new PathBetweenService($this->pdo))->routes($path, $required('from'), $required('to')),
            'annotate' => $this->annotate($path, $required('component'), $single('kind') ?? 'note', $single('value') ?? '', $flag('remove'), $flag('execute'), $single('author') ?? 'user'),
            'notes' => (new NotesService($this->pdo))->notes($path, $single('filter') ?? 'all', $single('text') ?? ''),
            'note-adopt' => (new NotesService($this->pdo))->adopt($path, $required('component'), $single('value'), $flag('execute')),
            'note-move' => (new NotesService($this->pdo))->move($path, $required('component'), $required('to'), $single('kind') ?? 'note', $single('author') ?? 'user', $flag('execute')),
            'note-check' => (new NotesService($this->pdo))->check($path, $required('component'), $single('value') ?? '', $flag('remove'), $flag('execute')),
            'note-confirm' => (new NotesService($this->pdo))->confirm($path, $required('component'), execute: $flag('execute')),
            default => throw new InvalidArgumentException(sprintf('Unknown pane command %s.', $command)),
        };
    }

    /**
     * A note on a component, previewed (nothing written) unless `$execute`:
     * what would be recorded or removed and what was there before, or, with
     * `$execute`, what now is. A request knossos refuses (an ambiguous name,
     * a kind it does not know, a value too long) is `refused` with why. A
     * note written here is the person's (`user`); a remove takes `$author`'s
     * note, the person's unless the pane names the model's.
     *
     * @return array<string, mixed>
     */
    private function annotate(string $path, string $component, string $kind, string $value, bool $remove, bool $execute, string $author): array
    {
        $absolute = realpath($path) ?: $path;
        $envelope = ['path' => $absolute, 'project_id' => null, 'snapshot_id' => null];
        $project = (new ProjectPathResolver($this->pdo))->resolve($absolute);
        if ($project === null || !is_string($project['active_scan_id']) || $project['active_scan_id'] === '') {
            return ['status' => 'unscanned'] + $envelope;
        }
        $id = (string) $project['id'];
        $envelope = ['project_id' => $id, 'snapshot_id' => $project['active_scan_id']] + $envelope;
        try {
            if (!$remove && $author !== 'user') {
                throw new InvalidArgumentException('A note written here is the person\'s: only a remove names an author.');
            }
            $annotations = QueryServices::wire($this->pdo)->annotationQueries;
            NotesService::assertNamesItself($annotations, $id, $component);
            // The pane is where a person writes: what they record is theirs.
            // Only a remove names another author, to take away a model's note.
            $result = $remove
                ? $annotations->removeAnnotation($id, $component, $kind, $value, $execute, $author)
                : $annotations->upsertAnnotation($id, $component, $kind, $value, $execute, 'user');
        } catch (InvalidArgumentException $refused) {
            return ['status' => 'refused', 'reason' => $refused->getMessage()] + $envelope;
        } catch (\Throwable $failed) {
            return NotesService::busy($failed) + $envelope;
        }
        $data = $result->data;

        return [
            'status' => 'ok',
            'component' => (string) $data['component'],
            'kind' => (string) $data['kind'],
            'action' => (string) $data['action'],
            'executed' => (bool) $data['executed'],
            'value' => $remove ? null : $value,
            'previous' => is_array($data['previous'] ?? null) ? (string) $data['previous']['value'] : null,
            'warnings' => array_values(array_filter($result->warnings, static fn(string $w): bool => !str_starts_with($w, 'Set execute=true'))),
        ] + $envelope;
    }
}

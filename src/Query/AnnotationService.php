<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use Knossos\Result\ResultEnvelope;

/**
 * Agent write-backs: durable annotations keyed by canonical name so they
 * survive rescans (node ids do not). Writes follow the repo's
 * preview-unless-execute convention.
 */
final readonly class AnnotationService extends AbstractArchitectureQueryService
{
    public const KINDS = ['intended_boundary', 'confirmed_dead', 'false_positive', 'intentional', 'note'];

    /**
     * Who wrote an annotation: a person, through the pane (`user`), or an
     * agent (`model`). A write touches only its own author's note, so a model
     * never changes or removes what a person recorded.
     */
    public const AUTHORS = ['user', 'model'];
    /**
     * Record or replace a durable annotation, previewing unless executing.
     *
     * @param list<string> $evidencePaths files besides the component's own that a model note rests on
     */
    public function upsertAnnotation(string $projectId, string $component, string $kind, string $value = '', bool $execute = false, string $author = 'model', array $evidencePaths = []): ResultEnvelope
    {
        $target = $this->target($projectId, $component, $kind, $value, $author);
        $basis = $author === 'model' ? $this->basis($projectId, $target['canonical'], $evidencePaths) : [];
        if (!$execute) {
            return $this->preview($projectId, $target, $kind, 'upsert', ['value' => $value]);
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO annotations(project_id, canonical_name, kind, value, author, basis_json, created_at, updated_at) ' .
            'VALUES (:project, :name, :kind, :value, :author, :basis, :now, :now) ' .
            // Re-recording refreshes the basis: it is how a stale note is confirmed.
            'ON CONFLICT(project_id, canonical_name, kind, author) DO UPDATE SET value = excluded.value, basis_json = excluded.basis_json, updated_at = excluded.updated_at',
        );
        $statement->execute(['project' => $projectId, 'name' => $target['canonical'], 'kind' => $kind, 'value' => $value, 'author' => $author, 'basis' => json_encode($basis, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'now' => gmdate('Y-m-d\TH:i:s\Z')]);

        return $this->executed($projectId, $target, $kind, 'upsert', 'Recorded', $this->fetch($projectId, $target['canonical'], $kind, $author));
    }

    /**
     * Remove a durable annotation, previewing unless executing.
     *
     * A value a caller passes along is checked as an upsert's would be and
     * otherwise ignored: the write tools take one value argument for both
     * actions, and an over-long one is refused whichever action it came with.
     */
    public function removeAnnotation(string $projectId, string $component, string $kind, string $value = '', bool $execute = false, string $author = 'model'): ResultEnvelope
    {
        $target = $this->target($projectId, $component, $kind, $value, $author);
        // Nothing of this author's to remove: say so, in the preview as well,
        // and name the other author's note left standing, so the caller does
        // not take it for gone or try again.
        $nothing = null;
        if ($target['existing'] === null) {
            $nothing = sprintf('%s has no %s annotation by the %s.', $target['canonical'], $kind, $author);
            $other = $author === 'model' ? 'user' : 'model';
            if ($this->fetch($projectId, $target['canonical'], $kind, $other) !== null) {
                $target['warnings'][] = $other === 'user'
                    ? sprintf('A %s annotation by the user remains; only the pane removes it.', $kind)
                    : sprintf('A %s annotation by the model remains.', $kind);
            }
        }
        if (!$execute) {
            return $this->preview($projectId, $target, $kind, 'remove', null, $nothing === null ? null : 'Preview: nothing to remove: ' . $nothing);
        }
        $statement = $this->pdo->prepare('DELETE FROM annotations WHERE project_id = :project AND canonical_name = :name AND kind = :kind AND author = :author');
        $statement->execute(['project' => $projectId, 'name' => $target['canonical'], 'kind' => $kind, 'author' => $author]);

        return $this->executed($projectId, $target, $kind, 'remove', 'Removed', null, $nothing === null ? null : 'Nothing removed: ' . $nothing);
    }

    /** How many files a model note may cite besides its component's own. */
    public const MAX_EVIDENCE_PATHS = 5;

    /**
     * The files a model note rests on, with the content hash each has now:
     * the component's own file first, then each cited path, once each. A
     * cited path the project's scan does not hold is refused, so a note never
     * rests on a file no reader can check.
     *
     * @param list<string> $evidencePaths
     * @return list<array{path: string, hash: string}>
     */
    private function basis(string $projectId, string $canonical, array $evidencePaths): array
    {
        if (count($evidencePaths) > self::MAX_EVIDENCE_PATHS) {
            throw new InvalidArgumentException(sprintf('evidence_paths takes at most %d paths.', self::MAX_EVIDENCE_PATHS));
        }
        // Every file the name is declared in (a class and an interface of the
        // same name can live apart), so a change to any of them is seen.
        $own = $this->pdo->prepare(
            'SELECT DISTINCT f.relative_path, f.content_hash FROM nodes n JOIN files f ON f.id = n.file_id ' .
            'WHERE n.project_id = :project AND n.canonical_name = :name ORDER BY f.relative_path LIMIT ' . self::MAX_EVIDENCE_PATHS,
        );
        $own->execute(['project' => $projectId, 'name' => $canonical]);
        $basis = [];
        foreach ($own->fetchAll() as $row) {
            $basis[(string) $row['relative_path']] = (string) $row['content_hash'];
        }
        $root = (string) $this->project($projectId)['root_realpath'];
        if ($basis === []) {
            // A file component (NamedFiles): the note rests on the file it names, hashed from disk.
            try {
                $named = ProjectRelativePath::of($root, $canonical);
                if (is_file($root . '/' . $named)) {
                    $basis[$named] = ProjectFileHash::of($root, $named);
                }
            } catch (InvalidArgumentException) {
                // A symbol name, not a path.
            }
        }
        $cited = $this->pdo->prepare('SELECT content_hash FROM files WHERE project_id = :project AND relative_path = :path');
        foreach ($evidencePaths as $written) {
            // Read as file_context reads a path: relative, `./`-led, or absolute under the root.
            try {
                $path = ProjectRelativePath::of($root, $written);
            } catch (InvalidArgumentException) {
                throw new InvalidArgumentException(sprintf('evidence path %s is outside the project.', $written));
            }
            if (isset($basis[$path])) {
                continue;
            }
            $cited->execute(['project' => $projectId, 'path' => $path]);
            $hash = $cited->fetchColumn();
            // A file no scan reads (a CI script, a shell tool) is hashed from disk.
            $basis[$path] = is_string($hash) ? $hash : ProjectFileHash::of($root, $path);
        }

        return array_map(static fn(string $path, string $hash): array => ['path' => $path, 'hash' => $hash], array_keys($basis), array_values($basis));
    }

    /**
     * Check a write's arguments and resolve the component it names: the
     * project, the canonical name the annotation is keyed by, the warnings
     * about that name, and the annotation already stored under it.
     *
     * @return array{project: array<string, mixed>, canonical: string, warnings: list<string>, existing: ?array<string, mixed>}
     */
    private function target(string $projectId, string $component, string $kind, string $value, string $author): array
    {
        $project = $this->project($projectId);
        if (!in_array($author, self::AUTHORS, true)) {
            throw new InvalidArgumentException('author must be one of: ' . implode(', ', self::AUTHORS) . '.');
        }
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('kind must be one of: ' . implode(', ', self::KINDS) . '.');
        }
        if (trim($component) === '') {
            throw new InvalidArgumentException('component must not be empty.');
        }
        // Characters, as the schema's maxLength counts them, not bytes.
        if (mb_strlen($value) > 2000) {
            throw new InvalidArgumentException('value must not exceed 2000 characters.');
        }
        // Exact matches only: a prefix match once turned `App\Invoice` into
        // `App\InvoiceService` and wrote the annotation on the wrong component.
        $matches = $this->resolveExact($projectId, $component);
        if (count($matches) > 1) {
            $names = array_slice(array_column($matches, 'canonical_name'), 0, 5);
            throw new InvalidArgumentException('Component is ambiguous; use a canonical name. Candidates: ' . implode(', ', $names) . '.');
        }
        $canonical = $matches === [] ? $component : (string) $matches[0]['canonical_name'];
        $warnings = [];
        if ($matches === [] && ProjectFile::exists((string) $project['root_realpath'], $component)) {
            // A noted project file becomes a file component on the next scan
            // (NamedFiles), so "not found" would send the caller looking for a typo.
            $warnings[] = sprintf('%s is a project file, not a component yet: the annotation is kept, and the file becomes a file component on the next scan.', $component);
        } elseif ($matches === []) {
            $warning = 'Component not found in the current graph; the annotation is kept anyway (dynamic or upcoming symbol?).';
            $near = array_slice(array_column($this->resolve($projectId, $component), 'canonical_name'), 0, 5);
            $warnings[] = $near === [] ? $warning : $warning . ' Did you mean: ' . implode(', ', $near) . '?';
        }

        return ['project' => $project, 'canonical' => $canonical, 'warnings' => $warnings, 'existing' => $this->fetch($projectId, $canonical, $kind, $author)];
    }

    /**
     * What a write would do, without doing it.
     *
     * @param array{project: array<string, mixed>, canonical: string, warnings: list<string>, existing: ?array<string, mixed>} $target
     * @param array<string, mixed>|null $annotation the annotation the write would leave
     */
    private function preview(string $projectId, array $target, string $kind, string $action, ?array $annotation, ?string $summary = null): ResultEnvelope
    {
        return new ResultEnvelope(
            $projectId,
            $target['project']['active_scan_id'],
            $summary ?? sprintf('Preview: would %s %s annotation on %s.', $action, $kind, $target['canonical']),
            ['component' => $target['canonical'], 'kind' => $kind, 'action' => $action, 'executed' => false, 'previous' => $target['existing'], 'annotation' => $annotation],
            [],
            [...$target['warnings'], 'Set execute=true to apply the change.'],
        );
    }

    /**
     * What a write did.
     *
     * @param array{project: array<string, mixed>, canonical: string, warnings: list<string>, existing: ?array<string, mixed>} $target
     * @param array<string, mixed>|null $annotation the annotation stored now
     */
    private function executed(string $projectId, array $target, string $kind, string $action, string $verb, ?array $annotation, ?string $summary = null): ResultEnvelope
    {
        return new ResultEnvelope(
            $projectId,
            $target['project']['active_scan_id'],
            $summary ?? sprintf('%s %s annotation on %s.', $verb, $kind, $target['canonical']),
            ['component' => $target['canonical'], 'kind' => $kind, 'action' => $action, 'executed' => true, 'previous' => $target['existing'], 'annotation' => $annotation],
            [],
            $target['warnings'],
        );
    }

    /** Annotations recorded for a project, optionally filtered. */

    public function listAnnotations(string $projectId, ?string $component = null, ?string $kind = null, int $limit = 100, int $offset = 0): ResultEnvelope
    {
        $project = $this->project($projectId);
        self::assertLimit($limit);
        if ($offset < 0 || $offset > 100_000) {
            throw new InvalidArgumentException('offset must be between 0 and 100000.');
        }
        if ($kind !== null && !in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('kind must be one of: ' . implode(', ', self::KINDS) . '.');
        }
        $sql = 'SELECT canonical_name, kind, value, author, created_at, updated_at FROM annotations WHERE project_id = :project';
        $parameters = ['project' => $projectId];
        if ($component !== null) {
            $sql .= ' AND canonical_name = :name';
            $parameters['name'] = $component;
        }
        if ($kind !== null) {
            $sql .= ' AND kind = :kind';
            $parameters['kind'] = $kind;
        }
        // A person's note before a model's on the same component and kind.
        $sql .= " ORDER BY canonical_name, kind, CASE author WHEN 'user' THEN 0 ELSE 1 END LIMIT :limit OFFSET :offset";
        $statement = $this->pdo->prepare($sql);
        foreach ($parameters as $key => $parameterValue) {
            $statement->bindValue(':' . $key, $parameterValue);
        }
        $statement->bindValue(':limit', $limit + 1, \PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        $truncated = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('Found %d annotation%s.', count($rows), count($rows) === 1 ? '' : 's'),
            ['annotations' => $rows, 'pagination' => ['offset' => $offset, 'next_offset' => $truncated ? $offset + $limit : null]],
            [],
            [],
            $truncated,
        );
    }

    /**
     * The stored annotation rows behind a listing.
     *
     * @return array<string, mixed>|null
     */
    private function fetch(string $projectId, string $canonical, string $kind, string $author): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT canonical_name, kind, value, author, created_at, updated_at FROM annotations ' .
            'WHERE project_id = :project AND canonical_name = :name AND kind = :kind AND author = :author',
        );
        $statement->execute(['project' => $projectId, 'name' => $canonical, 'kind' => $kind, 'author' => $author]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }
}

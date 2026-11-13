<?php

declare(strict_types=1);

namespace Knossos\Query\CreationBrief;

use InvalidArgumentException;
use Knossos\Configuration\ProjectConfiguration;
use Knossos\Configuration\ProjectConfigurationLoader;
use Knossos\Discovery\DiscoveryException;
use Knossos\Query\AbstractArchitectureQueryService;
use Knossos\Query\ProjectFile;
use Knossos\Query\ProjectRelativePath;
use Knossos\Result\ResultEnvelope;
use Knossos\Scan\Conventions\PathParts;
use Knossos\Scan\Conventions\TestPathPattern;
use Knossos\Store\FolderFactsStore;

/**
 * The `creation_brief` tool: what a file about to be created should look like.
 *
 * Reads only what a scan already stored: the folder facts, the companions, the
 * graph and the project's `knossos.json`. It never runs git and never calls a
 * model, so the same graph answers with the same brief. The path need not
 * exist; it is taken as `file_context` takes one, relative to the root or
 * absolute under it, and one that leaves the root is refused.
 *
 * The conventions come from the folder the path would sit in when that folder
 * holds at least {@see Conventions::MIN_FILES} files of the path's extension and
 * role, otherwise from the nearest ancestor that does, and the brief says so.
 */
final readonly class CreationBriefQueryService extends AbstractArchitectureQueryService
{
    /** The brief of a new file at `$path` in the project. */
    public function creationBrief(string $projectId, string $path): ResultEnvelope
    {
        $project = $this->project($projectId);
        $root = rtrim((string) $project['root_realpath'], '/');
        $relative = ProjectRelativePath::of($root, $path);
        if (str_ends_with($relative, '/')) {
            throw new InvalidArgumentException(sprintf('%s names a directory; a brief is for a file.', $path));
        }
        $directory = PathParts::directory($relative);
        $extension = PathParts::extension($relative);
        $facts = new FolderFactsStore($this->pdo);
        $role = (new PathRole($this->pdo, $facts))->of($projectId, $relative);

        $warnings = [];
        $readable = true;
        $configuration = new ProjectConfiguration();
        try {
            $configuration = ProjectConfigurationLoader::load($root, [$root]);
        } catch (DiscoveryException $error) {
            $readable = false;
            $warnings[] = sprintf('The project\'s knossos.json could not be read, so placement and gates are left out: %s', $error->getMessage());
        }

        $placement = $readable ? (new Placement($this->pdo))->of($projectId, $relative, $configuration->policies) : ['boundary' => null, 'may_not_use' => []];
        $basis = $this->basis($facts, $projectId, $directory, $extension, $role);
        $population = $basis === null ? null : $facts->of($projectId, $basis['directory'], $extension, $role);
        $neighbours = $basis === null ? [] : Neighbours::rank(
            PathParts::stem($relative),
            (new NeighbourCandidates($this->pdo))->of($projectId, $basis['directory'], $extension, $role, $relative),
        );
        $conventions = $population === null ? [] : Conventions::shown($population['files'], $population['facts']);
        $goesWith = $this->goesWith($facts, $projectId, $relative, $basis === null ? $directory : $basis['directory'], $role, $population);
        $gates = [];
        foreach ($configuration->gates as $gate) {
            if ($gate->appliesTo($relative)) {
                $gates[] = ['name' => $gate->name, 'command' => $gate->command];
            }
        }

        $data = [
            'path' => $relative,
            'exists' => ProjectFile::exists($root, $relative),
            'directory' => $directory,
            'extension' => $extension,
            'role' => $role,
            'placement' => $placement,
            'basis' => $basis,
            'neighbours' => $neighbours,
            'conventions' => $conventions,
            'goes_with' => $goesWith,
            'gates' => $gates,
            'notes' => $this->notes($projectId, $neighbours, $placement['boundary']),
            'signature' => $this->signature($placement, $conventions, $gates, $goesWith),
        ];

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('New file in %s: %d conventions, %d gates.', $placement['boundary'] ?? ($directory === '' ? '.' : $directory), count($conventions), count($gates)),
            $data,
            [],
            $warnings,
        );
    }

    /**
     * The folder the conventions come from: the path's own or the nearest ancestor with enough files.
     *
     * @return array{directory: string, files: int, borrowed: bool}|null
     */
    private function basis(FolderFactsStore $facts, string $projectId, string $directory, string $extension, string $role): ?array
    {
        for ($candidate = $directory;; $candidate = PathParts::directory($candidate)) {
            $population = $facts->of($projectId, $candidate, $extension, $role);
            if ($population !== null && $population['files'] >= Conventions::MIN_FILES) {
                return ['directory' => $candidate, 'files' => $population['files'], 'borrowed' => $candidate !== $directory];
            }
            if ($candidate === '') {
                return null;
            }
        }
    }

    /**
     * The expected test first, then the files that usually change with a new file in the folder.
     *
     * @param array{files: int, facts: array<string, mixed>}|null $population
     * @return list<array{path: string, commits?: int, of?: int, kind: 'test'|'companion'}>
     */
    private function goesWith(FolderFactsStore $facts, string $projectId, string $relative, string $directory, string $role, ?array $population): array
    {
        $goesWith = [];
        $testPath = $population['facts']['test_path'] ?? null;
        if ($role === 'source' && is_array($testPath) && is_string($testPath['pattern'] ?? null) && is_int($testPath['files'] ?? null) && $testPath['files'] * Conventions::SHARE_DENOMINATOR >= $population['files'] * Conventions::SHARE_NUMERATOR) {
            $test = TestPathPattern::apply($testPath['pattern'], $relative);
            if ($test !== $relative) {
                $goesWith[] = ['path' => $test, 'kind' => 'test'];
            }
        }
        $companions = $facts->companionsOf($projectId, $directory);
        usort($companions, static fn(array $a, array $b): int => [$b['commits'], $a['path']] <=> [$a['commits'], $b['path']]);
        foreach ($companions as $companion) {
            if ($companion['path'] !== $relative && $companion['path'] !== ($goesWith[0]['path'] ?? null)) {
                $goesWith[] = ['path' => $companion['path'], 'commits' => $companion['commits'], 'of' => $companion['of'], 'kind' => 'companion'];
            }
        }

        return $goesWith;
    }

    /**
     * How many notes sit on the components the neighbours declare and on the boundary.
     *
     * @param list<string> $neighbours
     */
    private function notes(string $projectId, array $neighbours, ?string $boundary): int
    {
        $marks = implode(',', array_fill(0, max(count($neighbours), 1), '?'));
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM annotations a WHERE a.project_id = ? AND (a.value <> '' OR a.kind = 'check') AND (
                a.canonical_name IN (SELECT n.canonical_name FROM nodes n JOIN files f ON f.id = n.file_id WHERE f.project_id = ? AND f.relative_path IN ($marks))
                OR a.canonical_name = ?)",
        );
        $statement->execute([$projectId, $projectId, ...($neighbours === [] ? [''] : $neighbours), $boundary ?? '']);

        return (int) $statement->fetchColumn();
    }

    /**
     * A hash of what the brief says that counts and neighbours do not change.
     *
     * @param array{boundary: ?string, may_not_use: list<string>} $placement
     * @param list<array{id: string}> $conventions
     * @param list<array{name: string}> $gates
     * @param list<array{path: string, kind: string}> $goesWith
     */
    private function signature(array $placement, array $conventions, array $gates, array $goesWith): string
    {
        return hash('sha256', json_encode([
            $placement,
            array_column($conventions, 'id'),
            array_column($gates, 'name'),
            array_column(array_filter($goesWith, static fn(array $entry): bool => $entry['kind'] === 'companion'), 'path'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}

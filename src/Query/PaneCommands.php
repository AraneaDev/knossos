<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use InvalidArgumentException;
use Knossos\Store\ProjectRepository;
use PDO;

/**
 * Every command the Claude Code mod calls, as one table: each one's options,
 * the positionals it takes, and how it is answered. {@see \Knossos\Cli\Command\BriefCommand}
 * is only the wrapper that finds the database and prints the answer.
 *
 * `turn-brief`, `rescan`, `dashboard`, `component-detail`, `file-detail`,
 * `session-changes`, `boundary-couplings`, `graph-search`, `file-context`
 * and `branch-diff` are the briefs and details the hooks and the pane read;
 * the architecture pane's reads that look past one component's detail, and
 * its writes, are `churn` (the files changed most that much depends on),
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
 * Each answers for the project owning a path; the command around it has
 * already checked that the database exists. `annotate` writes only when
 * `execute` is given, which the pane passes only after the person confirmed
 * the preview in it; `turn-brief` and `rescan` write only by scanning a
 * project that already exists.
 */
final readonly class PaneCommands
{
    /** The command reads a path, the working directory when none is given. */
    private const PATH = 'path';

    /** The command reads a file's own path, which it requires: the working directory is no file. */
    private const FILE = 'file';

    /** `[path] <name>`: a single positional is the name, read in the working directory. */
    private const NAMED = 'named';

    /**
     * @param PDO $pdo an existing, migrated graph database
     * @param string $databasePath where that database lives, for the commands that scan into it
     * @param string $installationRoot where Knossos is installed, for the scanner workers a scan starts
     */
    public function __construct(
        private PDO $pdo,
        private string $databasePath = '',
        private string $installationRoot = '',
    ) {}

    /**
     * Every command answered here.
     *
     * @return list<string>
     */
    public static function commands(): array
    {
        return array_keys(self::table());
    }

    /**
     * The options `$command` takes, beside `db` and `json`.
     *
     * @return list<string>
     */
    public static function options(string $command): array
    {
        return self::command($command)['options'];
    }

    /**
     * The path `$command` reads and, for `component-detail`, the component
     * name. One positional more than a command takes is refused, not ignored.
     *
     * @param list<string> $positionals
     * @return array{0: string, 1: string}
     */
    public static function target(string $command, array $positionals): array
    {
        $shape = self::command($command)['target'];
        if (count($positionals) > ($shape === self::NAMED ? 2 : 1)) {
            throw new InvalidArgumentException('Too many arguments.');
        }

        return match ($shape) {
            self::FILE => [$positionals[0] ?? throw new InvalidArgumentException('A file path is required.'), ''],
            self::NAMED => [
                count($positionals) > 1 ? $positionals[0] : (string) getcwd(),
                $positionals[count($positionals) - 1] ?? throw new InvalidArgumentException('A component name is required.'),
            ],
            default => [(string) ($positionals[0] ?? getcwd()), ''],
        };
    }

    /**
     * The answer to `$command` for the project owning `$path`. `$single`
     * reads an option's one value (null when absent); `$flag` whether a
     * flag was given. `$more` carries what only some commands read: the
     * component `name` {@see self::target()} returned, every value of a
     * repeated option (`values`), an integer option within bounds
     * (`integer`), and the policies a file holds (`policies`).
     *
     * @param Closure(string): ?string $single
     * @param Closure(string): bool $flag
     * @param array{name?: string, values?: Closure(string): list<string>, integer?: Closure(string, int, int, int): int, policies?: Closure(string): list<array<string, mixed>>} $more
     * @return array<string, mixed>
     */
    public function answer(string $command, string $path, Closure $single, Closure $flag, array $more = []): array
    {
        $required = static fn(string $name): string => $single($name) ?? throw new InvalidArgumentException(sprintf('--%s is required.', $name));

        return (self::command($command)['answer'])($this, $path, $single, $flag, $required, $more);
    }

    /**
     * One command's row, refusing a name that is none.
     *
     * @return array{options: list<string>, target: string, answer: Closure(self, string, Closure(string): ?string, Closure(string): bool, Closure(string): string, array<string, mixed>): array<string, mixed>}
     */
    private static function command(string $command): array
    {
        return self::table()[$command] ?? throw new InvalidArgumentException(sprintf('Unknown pane command %s.', $command));
    }

    /**
     * Every command, built once: its options, positional shape and answer.
     *
     * @return array<string, array{options: list<string>, target: string, answer: Closure(self, string, Closure(string): ?string, Closure(string): bool, Closure(string): string, array<string, mixed>): array<string, mixed>}>
     */
    private static function table(): array
    {
        /** @var array<string, array{options: list<string>, target: string, answer: Closure(self, string, Closure(string): ?string, Closure(string): bool, Closure(string): string, array<string, mixed>): array<string, mixed>}>|null $table */
        static $table = null;

        return $table ??= [...self::briefs(), ...self::paneReads()];
    }

    /**
     * The briefs and details the hooks and the pane read.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function briefs(): array
    {
        return [
            'turn-brief' => ['options' => ['files', 'policies', 'no-policies', 'since', 'reuse-scan'], 'target' => self::PATH, 'answer' => static function (self $p, string $path, Closure $single, Closure $flag, Closure $required, array $more): array {
                $policiesFile = $single('policies');

                return (new TurnBriefService($p->pdo, $p->databasePath, $p->installationRoot))->brief(
                    $path,
                    ($more['values'])('files'),
                    $policiesFile === null ? null : ($more['policies'])($policiesFile),
                    !$flag('no-policies'),
                    $single('since'),
                    $flag('reuse-scan'),
                );
            }],
            'rescan' => ['options' => [], 'target' => self::PATH, 'answer' => static fn(self $p, string $path): array => (new RescanService($p->pdo, $p->databasePath, $p->installationRoot))->rescan($path)],
            'dashboard' => ['options' => ['fan-in-threshold', 'boundaries'], 'target' => self::PATH, 'answer' => static fn(self $p, string $path, Closure $single, Closure $flag, Closure $required, array $more): array => (new DashboardService($p->pdo))->dashboard(
                $path,
                ($more['integer'])('fan-in-threshold', 20, 1, 100_000),
                ($more['integer'])('boundaries', DashboardService::BOUNDARY_AXES, 1, BoundaryMatrix::AXES),
            )],
            'component-detail' => ['options' => [], 'target' => self::NAMED, 'answer' => static fn(self $p, string $path, Closure $single, Closure $flag, Closure $required, array $more): array => (new ComponentDetailService($p->pdo))->detail($path, (string) ($more['name'] ?? ''))],
            'file-detail' => ['options' => [], 'target' => self::FILE, 'answer' => static fn(self $p, string $path): array => (new FileDetailService($p->pdo))->detail($path)],
            'file-context' => ['options' => [], 'target' => self::FILE, 'answer' => static fn(self $p, string $path): array => (new FileContextService($p->pdo))->context($path)],
            'session-changes' => ['options' => ['since', 'working-tree'], 'target' => self::PATH, 'answer' => static function (self $p, string $path, Closure $single, Closure $flag): array {
                $since = $single('since');
                $working = $flag('working-tree');
                if (($since === null) === !$working) {
                    throw new InvalidArgumentException('Exactly one of --since and --working-tree is required.');
                }

                return $working ? (new SessionChangesService($p->pdo))->workingChanges($path) : (new SessionChangesService($p->pdo))->changes($path, (string) $since);
            }],
            'boundary-couplings' => ['options' => ['from', 'to'], 'target' => self::PATH, 'answer' => static fn(self $p, string $path, Closure $single, Closure $flag, Closure $required): array => (new BoundaryCouplingsService($p->pdo))->couplings($path, $required('from'), $required('to'))],
            'graph-search' => ['options' => ['query'], 'target' => self::PATH, 'answer' => static fn(self $p, string $path, Closure $single): array => (new GraphSearchService($p->pdo))->search($path, $single('query') ?? '')],
            'branch-diff' => ['options' => [], 'target' => self::PATH, 'answer' => static fn(self $p, string $path): array => (new BranchDiffService($p->pdo))->diff($path)],
        ];
    }

    /**
     * The architecture pane's wider reads, and its notes.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function paneReads(): array
    {
        return [
            'churn' => ['options' => [], 'target' => self::PATH, 'answer' => static fn(self $p, string $path): array => (new ChurnService($p->pdo))->churn($path)],
            'blast-radius' => ['options' => ['component'], 'target' => self::PATH, 'answer' => static fn(self $p, string $path, Closure $single, Closure $flag, Closure $required): array => (new BlastRadiusService($p->pdo))->rings($path, $required('component'))],
            'path-between' => ['options' => ['from', 'to'], 'target' => self::PATH, 'answer' => static fn(self $p, string $path, Closure $single, Closure $flag, Closure $required): array => (new PathBetweenService($p->pdo))->routes($path, $required('from'), $required('to'))],
            'annotate' => ['options' => ['component', 'kind', 'value', 'remove', 'author', 'execute'], 'target' => self::PATH, 'answer' => static fn(self $p, string $path, Closure $single, Closure $flag, Closure $required): array => $p->annotate($path, $required('component'), $single('kind') ?? 'note', $single('value') ?? '', $flag('remove'), $flag('execute'), $single('author') ?? 'user')],
            'notes' => ['options' => ['filter', 'text'], 'target' => self::PATH, 'answer' => static fn(self $p, string $path, Closure $single): array => (new NotesService($p->pdo))->notes($path, $single('filter') ?? 'all', $single('text') ?? '')],
            'note-adopt' => ['options' => ['component', 'value', 'execute'], 'target' => self::PATH, 'answer' => static fn(self $p, string $path, Closure $single, Closure $flag, Closure $required): array => (new NotesService($p->pdo))->adopt($path, $required('component'), $single('value'), $flag('execute'))],
            'note-move' => ['options' => ['component', 'to', 'kind', 'author', 'execute'], 'target' => self::PATH, 'answer' => static fn(self $p, string $path, Closure $single, Closure $flag, Closure $required): array => (new NotesService($p->pdo))->move($path, $required('component'), $required('to'), $single('kind') ?? 'note', $single('author') ?? 'user', $flag('execute'))],
            'note-check' => ['options' => ['component', 'value', 'remove', 'execute'], 'target' => self::PATH, 'answer' => static fn(self $p, string $path, Closure $single, Closure $flag, Closure $required): array => (new NotesService($p->pdo))->check($path, $required('component'), $single('value') ?? '', $flag('remove'), $flag('execute'))],
            'note-confirm' => ['options' => ['component', 'execute'], 'target' => self::PATH, 'answer' => static fn(self $p, string $path, Closure $single, Closure $flag, Closure $required): array => (new NotesService($p->pdo))->confirm($path, $required('component'), execute: $flag('execute'))],
        ];
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
        $project = (new ProjectRepository($this->pdo))->resolvePath($absolute);
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

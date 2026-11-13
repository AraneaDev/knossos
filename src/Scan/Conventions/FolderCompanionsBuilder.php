<?php

declare(strict_types=1);

namespace Knossos\Scan\Conventions;

use Knossos\Git\GitCommand;
use Knossos\Git\GitListingRunner;
use Knossos\Git\GitLogRecords;
use Knossos\Git\GitProcessRunnerInterface;
use Knossos\Store\FolderFactsStore;
use Throwable;

/**
 * Records from git history which files change when a file is added to a folder.
 *
 * Adding a class often means touching the same registry, test list or doc every
 * time, and nothing in the graph says so. One `git log` over the last year
 * finds, per folder, the paths that changed in most of the commits that added a
 * file there. The result is stored under the commit it was computed at, so
 * git runs once per commit and a query never runs it.
 */
final readonly class FolderCompanionsBuilder
{
    /** What starts each commit in the log, after a NUL so no file name can forge it. */
    private const MARKER = "KNOSSOS_COMPANIONS\x1f";

    /** {@see self::MARKER} as `git log --format` writes it, followed by the commit id. */
    private const MARKER_FORMAT = '%x00KNOSSOS_COMPANIONS%x1f%H';

    private const GIT_TIMEOUT_MS = 3000;

    private const WINDOW_DAYS = 365;

    private const MAX_COMMITS = 2000;

    /** Fewest commits that added a file to a folder before any companion is claimed. */
    private const MIN_ADDITIONS = 3;

    /** A companion changed in at least 3 of every 5 such commits, compared in integers. */
    private const SHARE_NUMERATOR = 3;

    private const SHARE_DENOMINATOR = 5;

    private const PER_DIRECTORY = 5;

    /**
     * A commit touching more paths than this is a sweep (a rename, a format run), not
     * a file being added, and counting it would cost a pair per folder and path.
     */
    private const MAX_PATHS_PER_COMMIT = 200;

    private GitProcessRunnerInterface $runner;

    public function __construct(private FolderFactsStore $store, ?GitProcessRunnerInterface $runner = null)
    {
        $this->runner = $runner ?? GitListingRunner::create();
    }

    /**
     * Bring the stored companions up to `$gitHead`, running git only when that commit is new.
     *
     * Does nothing without a commit, and nothing when the stored companions are already
     * for this one. A git that fails is recorded as `unavailable` for the commit, so it is
     * not asked again until the commit changes, and never throws.
     */
    public function refresh(string $projectId, string $root, ?string $gitHead): void
    {
        if ($gitHead === null || $this->store->companionsHead($projectId) === $gitHead) {
            return;
        }
        try {
            $output = $this->runner->run(
                [...GitCommand::in($root), 'log', '--since=' . self::WINDOW_DAYS . '.days.ago', '--max-count=' . self::MAX_COMMITS, '--no-merges', '--no-renames', '-z', '--name-status', '--format=' . self::MARKER_FORMAT, '--relative', '--', '.'],
                self::GIT_TIMEOUT_MS,
                'folder companions',
            );
            $this->store->saveCompanions($projectId, $gitHead, 'ok', self::companions($output));
        } catch (Throwable $failure) {
            error_log('knossos folder facts: companions unavailable: ' . $failure->getMessage());
            $this->store->saveCompanions($projectId, $gitHead, 'unavailable', []);
        }
    }

    /**
     * The companions per directory found in one `git log --name-status` output.
     *
     * @return array<string, list<array{path: string, commits: int, of: int}>>
     */
    private static function companions(string $output): array
    {
        /** @var array<string, int> $additions commits that added a file, per directory */
        $additions = [];
        /** @var array<string, array<string, int>> $changed commits per directory in which each other path changed */
        $changed = [];
        foreach (GitLogRecords::parse($output, self::MARKER) as $record) {
            $entries = self::entries($record['paths']);
            if (count($entries) > self::MAX_PATHS_PER_COMMIT) {
                continue;
            }
            $added = [];
            foreach ($entries as [$status, $path]) {
                if ($status === 'A') {
                    $added[PathParts::directory($path)][$path] = true;
                }
            }
            foreach ($added as $directory => $paths) {
                $additions[$directory] = ($additions[$directory] ?? 0) + 1;
                foreach (array_unique(array_column($entries, 1)) as $path) {
                    if (!isset($paths[$path])) {
                        $changed[$directory][$path] = ($changed[$directory][$path] ?? 0) + 1;
                    }
                }
            }
        }

        $byDirectory = [];
        foreach ($changed as $directory => $counts) {
            $of = $additions[$directory];
            $companions = [];
            foreach ($counts as $path => $commits) {
                if ($of >= self::MIN_ADDITIONS && $commits * self::SHARE_DENOMINATOR >= $of * self::SHARE_NUMERATOR) {
                    $companions[] = ['path' => (string) $path, 'commits' => $commits, 'of' => $of];
                }
            }
            usort($companions, static fn(array $a, array $b): int => [$b['commits'], $a['path']] <=> [$a['commits'], $b['path']]);
            if ($companions !== []) {
                $byDirectory[(string) $directory] = array_slice($companions, 0, self::PER_DIRECTORY);
            }
        }
        ksort($byDirectory, SORT_STRING);

        return $byDirectory;
    }

    /**
     * The `[status, path]` pairs of one commit, from the alternating tokens `--name-status -z` writes.
     *
     * @param list<string> $tokens
     * @return list<array{string, string}>
     */
    private static function entries(array $tokens): array
    {
        $entries = [];
        for ($i = 0; $i + 1 < count($tokens); $i += 2) {
            $entries[] = [$tokens[$i], $tokens[$i + 1]];
        }

        return $entries;
    }
}

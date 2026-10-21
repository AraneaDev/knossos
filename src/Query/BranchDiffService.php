<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Git\GitProcessRunner;
use Knossos\Git\GitProcessRunnerInterface;
use Knossos\Result\PaneStatus;
use PDO;
use Throwable;

/**
 * What the checked-out branch did to the architecture, for the architecture
 * pane's Branch tab: the graph now against the snapshot taken where the
 * branch left its default branch.
 *
 * Git says where that is, locally and bounded, never over the network: the
 * default branch is the one `origin/HEAD` names (its local branch when there
 * is one), else `main` or `master`, local before remote; the merge base is
 * where HEAD left it. That commit is then matched to a retained snapshot by
 * the commit each scan was taken at (`git_head`): one taken at the merge
 * base itself (`exact`), else the nearest one taken before it (`before`,
 * with how many commits before). When none is retained, `status` is
 * `no-snapshot` and says so; the comparison is then made against the oldest
 * retained snapshot taken after the merge base, if any (`after`), which
 * already holds some of the branch's commits, and says how many.
 *
 * The comparison itself is {@see QualityGateQueryService::branchComparison()}.
 * Read-only: it never scans; git runs with the hardened runner, each call
 * bounded in time, and the snapshots it checks are bounded in number.
 */
final readonly class BranchDiffService
{
    /** Retained snapshots looked at, the newest first. */
    private const SNAPSHOTS = 50;

    /** Distinct commits checked for ancestry. */
    private const HEADS_CHECKED = 20;

    /** Items each list names. */
    public const LIMIT = 8;

    /** Touched files, the most lines changed first, whose dependents are counted. */
    private const FILES = 300;

    /** How long one git call may take. */
    private const GIT_TIMEOUT_MS = 3000;

    private GitProcessRunnerInterface $git;

    /**
     * @param PDO $pdo an existing, migrated graph database
     * @param GitProcessRunnerInterface|null $git how git is run; the hardened runner unless a test stands in
     * @param QueryServices|null $services the query services to read through; null wires them against `$pdo` per call
     */
    public function __construct(private PDO $pdo, ?GitProcessRunnerInterface $git = null, private ?QueryServices $services = null)
    {
        $this->git = $git ?? new GitProcessRunner();
    }

    /**
     * The branch's changes to the architecture of the project that owns
     * `$path`. `status`: `ok`, `no-snapshot` (with or without a partial
     * comparison), `on-default` (the default branch itself is checked out),
     * `no-git`, `no-default` (no default branch to compare with), `unscanned`.
     * The comparison's `violations` are null when the project declares no
     * policies, and `config_invalid` with the loader's `message` when its
     * `knossos.json` cannot be read.
     *
     * @return array<string, mixed>
     */
    public function diff(string $path): array
    {
        $envelope = ['project_id' => null, 'snapshot_id' => null, 'branch' => null, 'default_branch' => null, 'merge_base' => null, 'ahead' => null, 'base' => null, 'comparison' => null, 'files' => null];

        return PaneProject::answer($this->pdo, $path, $envelope, fn(array $project, array $envelope): array => $this->diffIn($project['id'], $project['root'], $envelope));
    }

    /**
     * The branch's changes in the scanned project `$id` at `$root`, in `$envelope`.
     *
     * @param array<string, mixed> $envelope
     *
     * @return array<string, mixed>
     */
    private function diffIn(string $id, string $root, array $envelope): array
    {
        $head = $this->git($root, ['rev-parse', '--verify', '-q', 'HEAD']);
        if ($head === null) {
            return ['status' => 'no-git'] + $envelope;
        }
        $branch = $this->git($root, ['symbolic-ref', '--short', '-q', 'HEAD']);
        $default = $this->defaultBranch($root);
        $envelope = ['branch' => $branch, 'default_branch' => $default] + $envelope;
        if ($default === null) {
            return ['status' => 'no-default'] + $envelope;
        }
        $mergeBase = $this->git($root, ['merge-base', 'HEAD', $default]);
        if ($mergeBase === null) {
            return ['status' => 'no-default'] + $envelope;
        }
        $ahead = (int) ($this->git($root, ['rev-list', '--count', $mergeBase . '..HEAD']) ?? 0);
        $envelope = ['merge_base' => ['rev' => $mergeBase, 'at' => (int) ($this->git($root, ['show', '-s', '--format=%ct', $mergeBase]) ?? 0)], 'ahead' => $ahead] + $envelope;
        if ($ahead === 0 || $branch === $default || $branch === preg_replace('#^[^/]+/#', '', $default)) {
            return ['status' => 'on-default'] + $envelope;
        }
        $envelope['files'] = $this->touched($root, $id, $mergeBase);
        $base = $this->baseSnapshot($root, $id, $mergeBase, (string) $envelope['snapshot_id']);
        $envelope['base'] = $base;
        if ($base === null) {
            return ['status' => 'no-snapshot'] + $envelope;
        }
        ['policies' => $policies, 'error' => $unreadable] = FileViolationQuery::policies($root, null);
        $comparison = ($this->services ?? QueryServices::wire($this->pdo))->gateQueries->branchComparison($id, $base['snapshot_id'], $policies, self::LIMIT);
        if ($unreadable !== null) {
            // Policies that could not be read were not checked: said so, never "no violations".
            $comparison['violations'] = ['status' => PaneStatus::POLICY_CONFIG_INVALID, 'message' => $unreadable, 'count' => 0, 'items' => [], 'truncated' => false];
        }

        return ['status' => $base['match'] === 'after' ? 'no-snapshot' : 'ok', 'comparison' => $comparison] + $envelope;
    }

    /**
     * The files the branch's commits touched since the merge base: how many,
     * each of them (up to {@see self::FILES}, `truncated` past it), the most
     * depended on first (then the most lines changed, then by path), with
     * the lines each added and deleted (zero for a binary file), its
     * dependents and boundary as the fan-in map counts them, and whether the
     * graph holds it at all; then the same files by boundary, each with the
     * files its changes reach counted once (`reach`), the most reaching
     * first and the files outside the graph (boundary null) last, and the
     * files the whole branch reaches. What is not committed is the Changes
     * tab's. Null when git cannot say.
     *
     * @return array{count: int, truncated: bool, added: int, deleted: int, reach: int, items: list<array{path: string, added: int, deleted: int, dependents: int, boundary: string|null, in_graph: bool}>, boundaries: list<array{boundary: string|null, files: int, added: int, deleted: int, reach: int, outside: bool}>}|null
     */
    private function touched(string $root, string $projectId, string $mergeBase): ?array
    {
        $out = $this->git($root, ['diff', '--numstat', '-z', '--no-renames', '--relative', $mergeBase, 'HEAD', '--', '.']);
        if ($out === null) {
            return null;
        }
        $lines = [];
        foreach (explode("\0", $out) as $record) {
            $parts = explode("\t", $record, 3);
            if (count($parts) === 3 && $parts[2] !== '') {
                $lines[$parts[2]] = [(int) $parts[0], (int) $parts[1]];
            }
        }
        $query = new FileFanInQuery($this->pdo);
        // The files the graph holds first, then the most lines changed: a cut past the cap drops docs and lockfiles, not source.
        $holds = array_flip($query->held($projectId, array_map('strval', array_keys($lines))));
        $changed = $lines;
        uksort($changed, static fn(string $a, string $b): int => [isset($holds[$b]), $lines[$b][0] + $lines[$b][1], $a] <=> [isset($holds[$a]), $lines[$a][0] + $lines[$a][1], $b]);
        $paths = array_slice(array_map('strval', array_keys($changed)), 0, self::FILES);
        $known = array_values(array_filter($paths, static fn(string $path): bool => isset($holds[$path])));
        $held = $known === [] ? [] : $query->forPaths($projectId, $known, 0);
        $items = [];
        foreach ($paths as $path) {
            $row = $held[$path] ?? null;
            $items[] = ['path' => $path, 'added' => $lines[$path][0], 'deleted' => $lines[$path][1], 'dependents' => $row === null ? 0 : $row['dependent_files'], 'boundary' => $row['boundary'] ?? null, 'in_graph' => $row !== null];
        }
        usort($items, static fn(array $a, array $b): int => [$b['dependents'], $b['added'] + $b['deleted'], $a['path']] <=> [$a['dependents'], $a['added'] + $a['deleted'], $b['path']]);
        $dependents = $query->dependentsOf($projectId, $known);

        return [
            'count' => count($lines),
            'truncated' => count($lines) > self::FILES,
            'added' => array_sum(array_column($lines, 0)),
            'deleted' => array_sum(array_column($lines, 1)),
            'reach' => self::reachOf($items, $dependents),
            'items' => $items,
            'boundaries' => self::byBoundary($items, $dependents),
        ];
    }

    /**
     * The touched files by boundary: how many, their lines, the files their
     * changes reach counted once; the most reaching first (then by name),
     * the files outside the graph last.
     *
     * @param list<array{path: string, added: int, deleted: int, dependents: int, boundary: string|null, in_graph: bool}> $items
     * @param array<string, list<string>> $dependents
     * @return list<array{boundary: string|null, files: int, added: int, deleted: int, reach: int, outside: bool}>
     */
    private static function byBoundary(array $items, array $dependents): array
    {
        $groups = [];
        foreach ($items as $item) {
            // A file the graph holds but no boundary claims is a group of its own, apart from the files outside the graph.
            $groups[$item['in_graph'] ? 'in:' . ($item['boundary'] ?? '') . ($item['boundary'] === null ? "\0" : '') : 'out'][] = $item;
        }
        $rows = [];
        foreach ($groups as $group) {
            $first = $group[0];
            $rows[] = [
                'boundary' => $first['in_graph'] ? $first['boundary'] : null,
                'files' => count($group),
                'added' => array_sum(array_column($group, 'added')),
                'deleted' => array_sum(array_column($group, 'deleted')),
                'reach' => self::reachOf($group, $dependents),
                'outside' => !$first['in_graph'],
            ];
        }
        // The most reaching first, then by name with the unclaimed after the named; the files outside the graph last.
        usort($rows, static fn(array $a, array $b): int => [$a['outside'], $b['reach'], $a['boundary'] === null, $a['boundary'] ?? ''] <=> [$b['outside'], $a['reach'], $b['boundary'] === null, $b['boundary'] ?? '']);

        return $rows;
    }

    /**
     * The files the changes to `$items` reach, each counted once.
     *
     * @param list<array{path: string}> $items
     * @param array<string, list<string>> $dependents
     */
    private static function reachOf(array $items, array $dependents): int
    {
        $reached = [];
        foreach ($items as $item) {
            foreach ($dependents[$item['path']] ?? [] as $path) {
                $reached[$path] = true;
            }
        }

        return count($reached);
    }

    /**
     * The default branch to compare with: what `origin/HEAD` names (its
     * local branch when one exists), else `main` or `master`, local before
     * remote; null when none exists.
     */
    private function defaultBranch(string $root): ?string
    {
        $named = $this->git($root, ['symbolic-ref', '--short', '-q', 'refs/remotes/origin/HEAD']);
        $candidates = $named === null ? [] : [(string) preg_replace('#^origin/#', '', $named), $named];
        foreach ([...$candidates, 'main', 'master', 'origin/main', 'origin/master'] as $candidate) {
            if ($this->git($root, ['rev-parse', '--verify', '-q', $candidate . '^{commit}']) !== null) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * The retained snapshot that stands for the merge base: one taken at it,
     * else the nearest taken before it, else the oldest taken after it; with
     * how it matched and how many commits lie between. Null when no retained
     * snapshot was taken on this line of history.
     *
     * @return array{snapshot_id: string, rev: string, at: string, match: string, commits: int}|null
     */
    private function baseSnapshot(string $root, string $projectId, string $mergeBase, string $active): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT s.id, s.git_head, s.started_at FROM scan_snapshots ss JOIN scans s ON s.id = ss.scan_id '
            . 'WHERE ss.project_id = :project AND ss.complete = 1 AND s.git_head IS NOT NULL AND s.id <> :active ORDER BY ss.rowid DESC LIMIT ' . self::SNAPSHOTS,
        );
        $statement->execute(['project' => $projectId, 'active' => $active]);
        $byHead = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // The newest scan of a commit stands for it: its graph is the most complete.
            $byHead[(string) $row['git_head']] ??= $row;
        }
        $pick = static fn(array $row, string $match, int $commits): array => ['snapshot_id' => (string) $row['id'], 'rev' => (string) $row['git_head'], 'at' => (string) $row['started_at'], 'match' => $match, 'commits' => $commits];
        if (isset($byHead[$mergeBase])) {
            return $pick($byHead[$mergeBase], 'exact', 0);
        }
        $before = $after = null;
        foreach (array_slice(array_keys($byHead), 0, self::HEADS_CHECKED) as $commit) {
            if ($this->git($root, ['merge-base', '--is-ancestor', $commit, $mergeBase]) !== null) {
                $distance = (int) ($this->git($root, ['rev-list', '--count', $commit . '..' . $mergeBase]) ?? PHP_INT_MAX);
                $before = $before === null || $distance < $before[1] ? [$commit, $distance] : $before;
            } elseif ($this->git($root, ['merge-base', '--is-ancestor', $mergeBase, $commit]) !== null) {
                $distance = (int) ($this->git($root, ['rev-list', '--count', $mergeBase . '..' . $commit]) ?? PHP_INT_MAX);
                $after = $after === null || $distance < $after[1] ? [$commit, $distance] : $after;
            }
        }
        if ($before !== null) {
            return $pick($byHead[$before[0]], 'before', $before[1]);
        }

        return $after === null ? null : $pick($byHead[$after[0]], 'after', $after[1]);
    }

    /**
     * A git command's trimmed output in `$root`, or null when it fails, times
     * out or says nothing (`--is-ancestor` answers by its exit status alone,
     * so its success is an empty string).
     *
     * @param list<string> $args
     */
    private function git(string $root, array $args): ?string
    {
        try {
            $out = trim($this->git->run(['git', '-c', 'core.quotePath=false', '--no-optional-locks', '--no-pager', '-C', $root, ...$args], self::GIT_TIMEOUT_MS, 'branch diff'));
        } catch (Throwable) {
            return null;
        }
        return $out === '' && !in_array('--is-ancestor', $args, true) ? null : $out;
    }
}

<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use InvalidArgumentException;
use Knossos\Git\GitWorkingTreeProvider;
use Knossos\Git\ProcessGitWorkingTreeProvider;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\TestFidelityQueryService;
use Knossos\Result\ResultEnvelope;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * `audit_tests` in scope `git-diff` over scratch git repositories the real
 * TypeScript worker scanned: `STALE_SINCE` and the narrowed stored findings
 * in each git state of Review Focus 4, and git's own failures as errors.
 * The git cases port Momus `packages/core/test/diff.test.ts:229-318`.
 *
 * The project is `ts-audit`, behind an `index.ts` that re-exports its one
 * class, so the target's file can move while the test file stays as it was:
 * `tests/ghost.test.ts` doubles `UserService` at line 6 (and holds a stored
 * ghost), and `tests/planted.test.ts` holds two stored tautologies.
 */
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class StaleSinceGitTest extends KnossosTestCase
{
    private const GHOST = 'tests/ghost.test.ts';

    private const STALE = 'stale since HEAD: UserService changed, tests/ghost.test.ts untouched; review: getProfil';

    /** `Wall`'s `impl`, in a file of its own, mocked by `automock` in a test build. */
    private const WALL_IMPL = "#[cfg(test)]\nuse mockall::automock;\n\nuse crate::wall::Wall;\n\n#[cfg_attr(test, automock)]\nimpl Wall {\n    pub fn tick(&self) -> u64 {\n        1\n    }\n}\n";

    private const SERVICE = "export class UserService {\n  getProfile(id: string): string {\n    return id;\n  }\n\n  touched(): void {}\n}\n";

    public function testAChangedTargetFileWithAnUntouchedTestFires(): void
    {
        $repo = $this->repository();
        file_put_contents($repo . '/src/UserService.ts', self::SERVICE);

        $result = $this->audit($repo, scope: 'git-diff');
        self::assertSame([[self::GHOST, 6, 'STALE_SINCE', 'warning', self::STALE]], self::stale($result));
        self::assertSame(['base_ref' => 'HEAD', 'changed_files' => 1], $result->data['git']);
        // scope git-diff implies tier all, and an explicit HEAD reads alike.
        self::assertSame(self::stale($result), self::stale($this->audit($repo, scope: 'git-diff', baseRef: 'HEAD')));
        // A tier given is kept: the scan tier has no STALE_SINCE.
        self::assertSame([], self::stale($this->audit($repo, scope: 'git-diff', tier: 'scan')));
    }

    public function testAChangedTestFileIsSilent(): void
    {
        $repo = $this->repository();
        file_put_contents($repo . '/src/UserService.ts', self::SERVICE);
        file_put_contents($repo . '/' . self::GHOST, (string) file_get_contents($repo . '/' . self::GHOST) . "// reviewed\n");

        $result = $this->audit($repo, scope: 'git-diff');
        self::assertSame([], self::stale($result));
        self::assertSame(2, $result->data['git']['changed_files']);
    }

    public function testAnUntrackedNewTestFileCountsAsChanged(): void
    {
        $repo = $this->repository();
        file_put_contents($repo . '/src/UserService.ts', self::SERVICE);
        // A new test of the same target, never added: changed, so not stale, as the ghost test is.
        file_put_contents($repo . '/tests/fresh.test.ts', "import { it, vi } from 'vitest';\nimport { UserService } from '../src/index';\n\nit('stubs', () => {\n  vi.spyOn(new UserService(), 'getProfile').mockReturnValue('x');\n});\n");

        foreach ([null, 'HEAD'] as $baseRef) {
            $result = $this->audit($repo, scope: 'git-diff', baseRef: $baseRef);
            self::assertSame([[self::GHOST, 6, 'STALE_SINCE', 'warning', self::STALE]], self::stale($result), (string) $baseRef);
            self::assertSame(2, $result->data['git']['changed_files'], (string) $baseRef);
        }
    }

    public function testARenamedTargetFileFires(): void
    {
        $repo = $this->repository();
        mkdir($repo . '/src/user');
        $this->git($repo, ['mv', 'src/UserService.ts', 'src/user/UserService.ts']);
        file_put_contents($repo . '/src/index.ts', "export { UserService } from './user/UserService';\n");

        $result = $this->audit($repo, scope: 'git-diff');
        // Both sides of the rename are changed; the target now lives on the new side.
        self::assertSame([[self::GHOST, 6, 'STALE_SINCE', 'warning', self::STALE]], self::stale($result));
        self::assertSame(3, $result->data['git']['changed_files']);
        // The stored ghost's target file changed: it is in scope, still definite.
        self::assertContains([self::GHOST, 6, 'GHOST_MEMBER', 'definite'], self::positions($result));
    }

    public function testAProjectInASubdirectoryReadsItsOwnPaths(): void
    {
        $repo = $this->repository('pkg');
        mkdir($repo . '/other');
        file_put_contents($repo . '/other/sibling.ts', "export const sibling = 2;\n");
        file_put_contents($repo . '/pkg/src/UserService.ts', self::SERVICE);

        $result = $this->audit($repo . '/pkg', scope: 'git-diff');
        // Paths relative to the project root, and nothing from the sibling package.
        self::assertSame([[self::GHOST, 6, 'STALE_SINCE', 'warning', self::STALE]], self::stale($result));
        self::assertSame(1, $result->data['git']['changed_files']);
    }

    public function testGitDiffNarrowsScanItemsToChangedFiles(): void
    {
        $repo = $this->repository();
        file_put_contents($repo . '/src/UserService.ts', self::SERVICE);

        // The ghost's target changed; the tautologies' file did not.
        $narrowed = $this->audit($repo, scope: 'git-diff', tier: 'scan');
        self::assertSame([[self::GHOST, 6, 'GHOST_MEMBER', 'definite']], self::positions($narrowed));
        self::assertSame(1, $narrowed->data['total']);
        // Out of scope git-diff, all three.
        self::assertSame(3, $this->audit($repo, tier: 'scan')->data['total']);

        // The tautologies' own file changed: they are in scope too, their confidence kept.
        file_put_contents($repo . '/tests/planted.test.ts', (string) file_get_contents($repo . '/tests/planted.test.ts') . "// touched\n");
        $both = $this->audit($repo, scope: 'git-diff', tier: 'scan');
        self::assertSame([[self::GHOST, 6, 'GHOST_MEMBER', 'definite'], ['tests/planted.test.ts', 4, 'TAUTOLOGY', 'definite'], ['tests/planted.test.ts', 10, 'TAUTOLOGY', 'warning']], self::positions($both));
        // Merged with STALE_SINCE in file and line order, and paged in memory.
        $all = $this->audit($repo, scope: 'git-diff', limit: 2, offset: 1);
        self::assertSame([[self::GHOST, 6, 'STALE_SINCE', 'warning'], ['tests/planted.test.ts', 4, 'TAUTOLOGY', 'definite']], self::positions($all));
        self::assertSame([4, 3], [$all->data['total'], $all->data['pagination']['next_offset']]);
        // G6-08a: by_language counts what the scope holds, as total does.
        foreach ([$narrowed, $both, $all] as $result) {
            self::assertSame($result->data['total'], self::counted($result));
        }
        self::assertSame(3, self::counted($this->audit($repo, tier: 'scan')));
    }

    public function testWithoutGitDiffNoStaleSince(): void
    {
        $repo = $this->repository();
        file_put_contents($repo . '/src/UserService.ts', self::SERVICE);

        $all = $this->audit($repo, tier: 'all');
        self::assertSame([[], ['STALE_SINCE is computed only in scope git-diff.']], [self::stale($all), $all->warnings]);
        // The scan tier alone lists no STALE_SINCE, so it has nothing to say about one.
        self::assertSame([], $this->audit($repo)->warnings);
        // Another on-request category asked for by name is not about STALE_SINCE either.
        self::assertSame([], $this->audit($repo, category: 'OVER_MOCKING')->warnings);
        $asked = $this->audit($repo, category: 'STALE_SINCE');
        self::assertSame([[], ['STALE_SINCE is computed only in scope git-diff.']], [$asked->data['items'], $asked->warnings]);
        self::assertArrayNotHasKey('git', $asked->data);
        // The same tree in scope git-diff has one (preflight F16): the silence above is the scope's.
        self::assertCount(1, self::stale($this->audit($repo, scope: 'git-diff')));
        self::assertSame([], $this->audit($repo, scope: 'git-diff', category: 'STALE_SINCE')->warnings);
    }

    public function testAnUnbornHeadReadsTheIndexAndAnExplicitRefFails(): void
    {
        $repo = $this->repository(commit: false);

        // The scan itself logs that HEAD names no commit; kept out of the suite's output.
        $logged = $this->errorLogOf(function () use ($repo): void {
            // Every file is new: every test file changed, so nothing is stale, and every stored finding is in scope.
            $result = $this->audit($repo, scope: 'git-diff');
            self::assertSame([], self::stale($result));
            self::assertSame(3, count(array_filter($result->data['items'], static fn(array $item): bool => $item['tier'] === 'scan')));
            self::assertSame(5, $result->data['git']['changed_files']);

            $this->assertFails($repo, 'HEAD', 'git-diff scope failed against HEAD: ');
        });
        self::assertStringContainsString('knossos scan head: git could not answer', $logged);
    }

    /** G6-08b: a Rust type whose `impl` lives in another file goes stale when only that file changed, through the real Rust worker. */
    #[Group('rust-scanner')]
    public function testARustImplInAnotherFileMakesItsMockStale(): void
    {
        if (!is_file(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker binary is not built.');
        }
        $repo = $this->rustRepository();
        // Only the impl's file changed; the struct's own file did not.
        file_put_contents($repo . '/src/wall_impl.rs', str_replace('        1', '        2', self::WALL_IMPL));

        $stale = 'stale since HEAD: Wall changed, src/wall_tests.rs untouched; review: tick';
        self::assertSame([['src/wall_tests.rs', 5, 'STALE_SINCE', 'warning', $stale]], self::stale($this->audit($repo, scope: 'git-diff')));
        // The impl's file as committed: nothing changed, nothing stale.
        file_put_contents($repo . '/src/wall_impl.rs', self::WALL_IMPL);
        $committed = $this->audit($repo, scope: 'git-diff');
        self::assertSame([], self::stale($committed));
        // The test file changed with the impl: reviewed, so silent.
        file_put_contents($repo . '/src/wall_impl.rs', str_replace('        1', '        3', self::WALL_IMPL));
        file_put_contents($repo . '/src/wall_tests.rs', "// reviewed\n", FILE_APPEND);
        $reviewed = $this->audit($repo, scope: 'git-diff');
        self::assertSame([], self::stale($reviewed));
    }

    public function testAStaleDoubleOnABranchWithNoCommitIsStaleSinceTheEmptyTree(): void
    {
        // G6-08a: with HEAD naming no commit, the changed set is read against the empty tree, and the message says so.
        $repo = $this->repository();
        $pdo = $this->freshTestDatabase();
        $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$repo]))->scan($repo)->projectId;
        $reader = new class implements GitWorkingTreeProvider {
            public bool $born = false;

            public function changes(string $projectRoot, ?string $baseRef, int $maxFiles, int $timeoutMs): array
            {
                return ['paths' => ['src/UserService.ts'], 'renames' => [], 'truncated' => false, 'born' => $this->born];
            }
        };
        $service = ArchitectureQueryService::forDatabase($pdo, gitWorkingTree: $reader)->services()->testFidelityQueries;

        $unborn = $service->auditTests($projectId, scope: 'git-diff');
        self::assertSame([[self::GHOST, 6, 'STALE_SINCE', 'warning', str_replace('since HEAD', 'since the empty tree', self::STALE)]], self::stale($unborn));
        self::assertSame(['base_ref' => 'HEAD', 'changed_files' => 1], $unborn->data['git']);
        $reader->born = true;
        self::assertSame([[self::GHOST, 6, 'STALE_SINCE', 'warning', self::STALE]], self::stale($service->auditTests($projectId, scope: 'git-diff')));
    }

    public function testABadBaseRefIsAnError(): void
    {
        $repo = $this->repository();

        $this->assertFails($repo, 'no-such-branch', 'git-diff scope failed against no-such-branch: Git working-tree query unavailable: fatal: ');
        $this->assertFails($repo, 'HEAD~9', 'git-diff scope failed against HEAD~9: ');
        $this->assertFails($repo, '-bad', 'git-diff scope failed against -bad: base_ref contains unsupported characters.');
    }

    public function testAShallowCloneReadsItsHeadAndRefusesARefBeyondIt(): void
    {
        $origin = $this->repository();
        file_put_contents($origin . '/src/UserService.ts', self::SERVICE);
        $this->git($origin, ['commit', '--quiet', '-am', 'second']);
        $clone = self::scratchPath('knossos-stale-shallow');
        $this->runFixtureCommand(['git', 'clone', '--quiet', '--depth', '1', 'file://' . $origin, $clone]);
        file_put_contents($clone . '/src/UserService.ts', self::SERVICE . "export const more = 1;\n");

        self::assertSame([[self::GHOST, 6, 'STALE_SINCE', 'warning', self::STALE]], self::stale($this->audit($clone, scope: 'git-diff')));
        // HEAD~1 is a commit the clone does not hold.
        $this->assertFails($clone, 'HEAD~1', 'git-diff scope failed against HEAD~1: ');
    }

    public function testARootOutsideAWorkTreeIsAnError(): void
    {
        $root = self::scratchPath('knossos-stale-plain');
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/test-fidelity/ts-audit', $root);

        $this->assertFails($root, null, 'git-diff scope failed against HEAD: Git working-tree query unavailable: fatal: not a git repository');
        // Without git-diff, the same root answers.
        self::assertSame(3, $this->audit($root)->data['total']);
    }

    /**
     * What the reader is asked and what its answer may not be: the git call
     * gets what is left of `timeout_ms`, at most the reader's 5000; a cut
     * change set and a timeout are errors, never `partial`.
     */
    public function testTheReadersBoundsAndFailuresAreErrors(): void
    {
        $repo = $this->repository();
        $pdo = $this->freshTestDatabase();
        $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$repo]))->scan($repo)->projectId;
        $reader = new class implements GitWorkingTreeProvider {
            /** @var list<array{?string, int, int}> */
            public array $asked = [];

            public ?\Throwable $failure = null;

            public bool $truncated = false;

            public function changes(string $projectRoot, ?string $baseRef, int $maxFiles, int $timeoutMs): array
            {
                $this->asked[] = [$baseRef, $maxFiles, $timeoutMs];
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                return ['paths' => ['src/UserService.ts'], 'renames' => [], 'truncated' => $this->truncated];
            }
        };
        // A clock that stands still: what is left of timeout_ms is all of it.
        $service = ArchitectureQueryService::forDatabase($pdo, static fn(): int => 0, gitWorkingTree: $reader)->services()->testFidelityQueries;

        $service->auditTests($projectId, scope: 'git-diff');
        $service->auditTests($projectId, scope: 'git-diff', baseRef: 'main', timeoutMs: 10_000);
        $service->auditTests($projectId, scope: 'git-diff', timeoutMs: 1);
        self::assertSame([[null, 1000, 2000], ['main', 1000, 5000], [null, 1000, 1]], $reader->asked);

        $reader->truncated = true;
        $this->assertRefused(fn() => $service->auditTests($projectId, scope: 'git-diff'), 'git-diff scope failed against HEAD: more than 1000 files changed; pick a nearer base_ref.');
        $reader->truncated = false;
        $reader->failure = new \RuntimeException('Git working-tree query timed out.');
        $this->assertRefused(fn() => $service->auditTests($projectId, scope: 'git-diff', baseRef: 'main'), 'git-diff scope failed against main: Git working-tree query timed out.');
    }

    public function testWithoutAGitReaderTheScopeIsUnavailable(): void
    {
        $repo = $this->repository();
        $pdo = $this->freshTestDatabase();
        $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$repo]))->scan($repo)->projectId;

        $this->expectExceptionObject(new InvalidArgumentException('scope git-diff is unavailable: this server reads no git working tree.'));
        ArchitectureQueryService::forDatabase($pdo)->services()->testFidelityQueries->auditTests($projectId, scope: 'git-diff');
    }

    public function testBaseRefWithoutGitDiffIsRefused(): void
    {
        $repo = $this->repository();

        $this->expectExceptionObject(new InvalidArgumentException('base_ref requires scope git-diff.'));
        $this->audit($repo, baseRef: 'HEAD');
    }

    /**
     * A scratch repository holding `ts-audit` behind an `index.ts`, in
     * `$subdirectory` when one is named, committed unless told not to.
     */
    private function repository(string $subdirectory = '', bool $commit = true): string
    {
        if (self::locateGit() === null) {
            self::markTestSkipped('git is not available on this host.');
        }
        $repo = self::scratchPath('knossos-stale-git');
        $project = $subdirectory === '' ? $repo : $repo . '/' . $subdirectory;
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/test-fidelity/ts-audit', $project);
        file_put_contents($project . '/src/index.ts', "export { UserService } from './UserService';\n");
        file_put_contents($project . '/' . self::GHOST, str_replace("'../src/UserService'", "'../src/index'", (string) file_get_contents($project . '/' . self::GHOST)));
        $this->git($repo, ['init', '--quiet']);
        if ($commit) {
            $this->git($repo, ['add', '-A']);
            $this->git($repo, ['commit', '--quiet', '-m', 'base']);
        }

        return (string) realpath($repo);
    }

    /**
     * A committed scratch crate: `Wall` in `src/wall.rs`, its `automock`ed
     * `impl` in `src/wall_impl.rs`, and a test doubling it at `src/wall_tests.rs:5`.
     */
    private function rustRepository(): string
    {
        if (self::locateGit() === null) {
            self::markTestSkipped('git is not available on this host.');
        }
        $repo = self::scratchPath('knossos-stale-rust');
        mkdir($repo . '/src', 0o777, true);
        file_put_contents($repo . '/Cargo.toml', "[package]\nname = \"walls\"\nversion = \"0.1.0\"\nedition = \"2021\"\n\n[dev-dependencies]\nmockall = \"0.13\"\n");
        file_put_contents($repo . '/src/lib.rs', "pub mod wall;\nmod wall_impl;\n#[cfg(test)]\nmod wall_tests;\n");
        file_put_contents($repo . '/src/wall.rs', "pub struct Wall;\n");
        file_put_contents($repo . '/src/wall_impl.rs', self::WALL_IMPL);
        file_put_contents($repo . '/src/wall_tests.rs', "use crate::wall_impl::MockWall;\n\n#[test]\nfn ticks() {\n    let mut wall = MockWall::new();\n    wall.expect_tick().returning(|| 1);\n    assert_eq!(wall.tick(), 1);\n}\n");
        $this->git($repo, ['init', '--quiet']);
        $this->git($repo, ['add', '-A']);
        $this->git($repo, ['commit', '--quiet', '-m', 'base']);

        return (string) realpath($repo);
    }

    /**
     * Scan `$root` afresh and ask `audit_tests` with a git reader.
     *
     * @param mixed ...$arguments named arguments of {@see TestFidelityQueryService::auditTests()}
     */
    private function audit(string $root, mixed ...$arguments): ResultEnvelope
    {
        $pdo = $this->freshTestDatabase();
        $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;
        $queries = ArchitectureQueryService::forDatabase($pdo, gitWorkingTree: new ProcessGitWorkingTreeProvider());

        return $queries->services()->testFidelityQueries->auditTests($projectId, ...$arguments);
    }

    /** Asking scope git-diff of `$root` against `$baseRef` is an error whose message starts with `$prefix`. */
    private function assertFails(string $root, ?string $baseRef, string $prefix): void
    {
        try {
            $this->audit($root, scope: 'git-diff', baseRef: $baseRef);
            self::fail('git-diff answered against ' . ($baseRef ?? 'no ref'));
        } catch (InvalidArgumentException $error) {
            self::assertStringStartsWith($prefix, $error->getMessage());
        }
    }

    /** `$call` is refused with exactly `$message`. */
    private function assertRefused(\Closure $call, string $message): void
    {
        try {
            $call();
            self::fail('Answered: ' . $message);
        } catch (InvalidArgumentException $error) {
            self::assertSame($message, $error->getMessage());
        }
    }

    /** @param list<string> $args */
    private function git(string $repo, array $args): void
    {
        $this->runFixtureCommand(['git', '-C', $repo, '-c', 'user.name=Knossos Test', '-c', 'user.email=test@example.test', '-c', 'commit.gpgsign=false', ...$args]);
    }

    /**
     * The STALE_SINCE items: file, line, category, confidence and message.
     *
     * @return list<array{mixed, mixed, mixed, mixed, mixed}>
     */
    private static function stale(ResultEnvelope $result): array
    {
        return array_values(array_map(
            static fn(array $item): array => [$item['file'], $item['line'], $item['category'], $item['confidence'], $item['message']],
            array_filter($result->data['items'], static fn(array $item): bool => $item['category'] === 'STALE_SINCE'),
        ));
    }

    /** The findings `summary.by_language` counts, every language and confidence. */
    private static function counted(ResultEnvelope $result): int
    {
        return array_sum(array_map(static fn(array $counts): int => (int) ($counts['definite'] ?? 0) + (int) ($counts['warning'] ?? 0), $result->data['summary']['by_language']));
    }

    /**
     * Each item's file, line, category and confidence.
     *
     * @return list<array{mixed, mixed, mixed, mixed}>
     */
    private static function positions(ResultEnvelope $result): array
    {
        return array_map(static fn(array $item): array => [$item['file'], $item['line'], $item['category'], $item['confidence']], $result->data['items']);
    }
}

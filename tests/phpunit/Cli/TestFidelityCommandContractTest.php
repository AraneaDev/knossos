<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use InvalidArgumentException;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\Command\QueryCommand;
use Knossos\Mcp\ToolArgumentSpec;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Scan\ProjectScanService;
use Knossos\TestFidelity\TestRuleCatalog;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The test-fidelity commands' CLI contract: `audit-tests`,
 * `list-test-rules`, `verify-test-doubles`, `check-fixtures` and `synthesize-mock`, their options against their
 * tools' schemas, and the text each prints. Kept apart from QueryCommandContractTest, which is at
 * its length cap (preflight F3).
 */
#[Group('cli')]
#[Group('test-fidelity')]
final class TestFidelityCommandContractTest extends KnossosTestCase
{
    /** The arguments each command takes as positionals rather than options. */
    private const POSITIONAL_ARGUMENTS = ['audit-tests' => ['project_id'], 'list-test-rules' => [], 'verify-test-doubles' => ['project_id', 'symbol'], 'check-fixtures' => ['project_id', 'paths'], 'synthesize-mock' => ['project_id', 'symbol']];

    public function testEveryToolArgumentHasACliOptionOrPositional(): void
    {
        foreach (self::POSITIONAL_ARGUMENTS as $command => $positionals) {
            $declared = ToolArgumentSpec::for(str_replace('-', '_', $command))->properties();
            $mapped = array_values(array_filter(QueryCommand::optionArguments($command), static fn(?string $argument): bool => $argument !== null));
            $taken = [...$mapped, ...$positionals];
            self::assertSame([], array_values(array_diff($taken, $declared)), $command . ': options naming no tool argument');
            self::assertSame([], array_values(array_diff($declared, $taken, ['verbosity', 'max_chars', 'refresh_if_stale'])), $command . ': tool arguments the CLI does not take');
            self::assertTrue((new QueryCommand())->supports($command));
        }
        self::assertSame(['path' => 'path_prefix', 'confidence' => 'confidence', 'category' => 'category', 'limit' => 'limit', 'offset' => 'offset', 'tier' => 'tier', 'scope' => 'scope', 'base-ref' => 'base_ref', 'timeout-ms' => 'timeout_ms'], QueryCommand::optionArguments('audit-tests'));
    }

    public function testAuditTestsTextLines(): void
    {
        $this->withScannedAudit(function (\Closure $run, string $projectId): void {
            self::assertSame(implode("\n", [
                'tests/ghost.test.ts:1  OVER_MOCKING warning  1/1 dependencies mocked, 1 production assertions: over-mocked',
                'tests/ghost.test.ts:6  GHOST_MEMBER definite  vi.spyOn UserService.getProfil stubs getProfil, which UserService no longer has; did you mean getProfile?',
                '4 test-fidelity findings.',
            ]) . "\n", $run('audit-tests', [$projectId], ['tier' => ['all'], 'limit' => ['2']]));
            // The default tier is the stored one, printed as before.
            self::assertSame(implode("\n", [
                'tests/ghost.test.ts:6  GHOST_MEMBER definite  vi.spyOn UserService.getProfil stubs getProfil, which UserService no longer has; did you mean getProfile?',
                'tests/planted.test.ts:4  TAUTOLOGY definite  true is compared with itself; the assertion cannot fail',
                'tests/planted.test.ts:10  TAUTOLOGY warning  the spy is not stubbed and the test runs no production code, so nothing could have called it',
                '3 test-fidelity findings.',
            ]) . "\n", $run('audit-tests', [$projectId], []));
            // The JSON envelope carries the tier on each item.
            $json = json_decode($run('audit-tests', [$projectId], ['tier' => ['on_request'], 'json' => ['true']]), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['on_request'], array_column($json['data']['items'], 'tier'));
        });
    }

    public function testAuditTestsSaysWhatItCouldNotJudge(): void
    {
        $this->withScannedAudit(function (\Closure $run, string $projectId, \PDO $pdo): void {
            // Made a PHP test file that doubles a class and imports nothing, as the PHP worker
            // sends it: no `imports` edge from any PHP test module, so OVER_MOCKING says so.
            $file = (string) $pdo->query('SELECT file_id FROM nodes WHERE canonical_name = \'tests/ghost.test.ts\' AND kind = \'module\'')->fetchColumn();
            $pdo->exec("UPDATE nodes SET language = 'php' WHERE file_id = '{$file}'");
            $pdo->exec("DELETE FROM edges WHERE kind = 'imports' AND source_id IN (SELECT id FROM nodes WHERE file_id = '{$file}')");
            self::assertSame(implode("\n", [
                'php: OVER_MOCKING: no imports from worker knossos.php',
                '0 test-fidelity findings.',
            ]) . "\n", $run('audit-tests', [$projectId], ['tier' => ['on_request']]));
        });
    }

    public function testAuditTestsReadsGitDiff(): void
    {
        $this->withScannedAudit(function (\Closure $run, string $projectId, \PDO $pdo, string $root): void {
            // Not yet a work tree: the CLI reads git, and git's failure is the error.
            try {
                $run('audit-tests', [$projectId], ['scope' => ['git-diff']]);
                self::fail('git-diff answered outside a work tree.');
            } catch (InvalidArgumentException $error) {
                self::assertStringStartsWith('git-diff scope failed against HEAD: Git working-tree query unavailable: fatal: not a git repository', $error->getMessage());
            }
            foreach ([['init', '--quiet'], ['add', '-A'], ['commit', '--quiet', '-m', 'base']] as $args) {
                $this->runFixtureCommand(['git', '-C', $root, '-c', 'user.name=Knossos Test', '-c', 'user.email=test@example.test', '-c', 'commit.gpgsign=false', ...$args]);
            }
            file_put_contents($root . '/src/UserService.ts', (string) file_get_contents($root . '/src/UserService.ts') . "// changed\n");

            self::assertSame(implode("\n", [
                'tests/ghost.test.ts:1  OVER_MOCKING warning  1/1 dependencies mocked, 1 production assertions: over-mocked',
                'tests/ghost.test.ts:6  GHOST_MEMBER definite  vi.spyOn UserService.getProfil stubs getProfil, which UserService no longer has; did you mean getProfile?',
                'tests/ghost.test.ts:6  STALE_SINCE warning  stale since HEAD: UserService changed, tests/ghost.test.ts untouched; review: getProfil',
                '3 test-fidelity findings.',
            ]) . "\n", $run('audit-tests', [$projectId], ['scope' => ['git-diff'], 'base-ref' => ['HEAD']]));
        });
    }

    public function testSynthesizeMockPrintsTheTemplate(): void
    {
        $this->withScannedAudit(function (\Closure $run, string $projectId): void {
            $golden = (string) file_get_contents(self::repositoryRoot() . '/tests/Fixtures/test-fidelity/synthesis-expected/ts-typed.txt');
            // The text is the template alone, as the golden holds it.
            self::assertSame($golden, $run('synthesize-mock', [$projectId, 'Typed'], []));
            self::assertSame((string) file_get_contents(self::repositoryRoot() . '/tests/Fixtures/test-fidelity/synthesis-expected/ts-typed-no-values.txt'), $run('synthesize-mock', [$projectId, 'Typed'], ['no-values' => ['true']]));
            self::assertSame((string) file_get_contents(self::repositoryRoot() . '/tests/Fixtures/test-fidelity/synthesis-expected/rs-store-hand-written.txt'), $run('synthesize-mock', [$projectId, 'Store'], ['language' => ['rust'], 'framework' => ['hand-written']]));
            $json = json_decode($run('synthesize-mock', [$projectId, 'Typed'], ['json' => ['true']]), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame([$golden, 'vitest', 'default', true], [$json['data']['template'], $json['data']['framework'], $json['data']['framework_source'], $json['data']['complete']]);
        }, 'synthesis');
        self::assertSame(['framework' => 'framework', 'language' => 'language', 'no-values' => 'include_values'], QueryCommand::optionArguments('synthesize-mock'));
    }

    public function testVerifyTestDoublesText(): void
    {
        $this->withScannedAudit(function (\Closure $run, string $projectId): void {
            self::assertSame(implode("\n", [
                'tests/ghost.test.ts:6  vi.spyOn UserService.getProfil  invalid  GHOST_MEMBER definite',
                '1 double of src/UserService.ts#UserService; 1 invalid.',
            ]) . "\n", $run('verify-test-doubles', [$projectId, 'UserService'], []));
            $json = json_decode($run('verify-test-doubles', [$projectId, 'UserService'], ['json' => ['true']]), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['doubles' => 1, 'invalid' => 1], $json['data']['summary']);
        });
    }

    public function testCheckFixturesText(): void
    {
        $this->withScannedAudit(function (\Closure $run, string $projectId): void {
            self::assertSame(implode("\n", [
                "fixtures/users.fixture.json:4  FIXTURE_DRIFT warning  Fixture 'users.fixture.json' is missing required field 'email' of UserRecord.",
                "fixtures/users.fixture.json:4  FIXTURE_DRIFT warning  Fixture 'users.fixture.json' is missing required field 'name' of UserRecord.",
                '4 fixture drift findings in 1 fixture file.',
            ]) . "\n", $run('check-fixtures', [$projectId], ['limit' => ['2'], 'offset' => ['1']]));
            $json = json_decode($run('check-fixtures', [$projectId], ['json' => ['true']]), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame([4, 1], [$json['data']['total'], $json['data']['scanned']]);
        }, 'fixtures/nemesis-data');
        // The paths are the positionals after the project; what was not checked, and why, comes before the summary.
        $this->withScannedAudit(function (\Closure $run, string $projectId): void {
            self::assertSame(implode("\n", [
                'unmatched: rust/fixtures/bundles.fixture.json',
                'unmatched: rust/fixtures/cartons.fixture.json',
                'unmatched: rust/fixtures/tracking.fixture.json',
                'unparsable: tests/fixtures/invalid.json (invalid JSON)',
                '0 fixture drift findings in 5 fixture files.',
            ]) . "\n", $run('check-fixtures', [$projectId, 'rust/fixtures', 'tests/fixtures/invalid.json'], []));
        }, 'fixtures/healthy');
    }

    public function testListTestRulesText(): void
    {
        $this->withScannedAudit(function (\Closure $run): void {
            $lines = explode("\n", rtrim($run('list-test-rules', [], []), "\n"));

            $rules = TestRuleCatalog::all();
            self::assertCount(count($rules) + 2, $lines);
            self::assertSame('test.fidelity.ghost_member.v1  GHOST_MEMBER  scan  definite  missing_member,missing_export,missing_module  A double stubs a member, export or module its target does not have.', $lines[0]);
            self::assertSame('test.fidelity.over_mocking.v1  OVER_MOCKING  on_request  warning  saturation  ' . $rules[6]['description'], $lines[6]);
            foreach ($rules as $at => $rule) {
                self::assertStringStartsWith($rule['id'] . '  ' . $rule['category'] . '  ' . $rule['tier'] . '  ' . implode(',', $rule['confidences']) . '  ', $lines[$at]);
            }
            self::assertSame('Suppress a finding with knossos.json test_finding_suppressions.', $lines[count($rules)]);
            self::assertSame('9 test-fidelity rules.', $lines[count($rules) + 1]);
        });
    }

    /**
     * Run `$test` against a scanned copy of a test-fidelity fixture (`ts-audit` by default) and a command runner that returns what it printed.
     *
     * @param \Closure(\Closure(string, list<string>, array<string, list<string>>): string, string, \PDO, string): void $test
     */
    private function withScannedAudit(\Closure $test, string $fixture = 'ts-audit'): void
    {
        $base = self::scratchPath('knossos-stale-test-fidelity-cli');
        $root = $base . '/project';
        $database = $base . '/data/knossos.sqlite';
        try {
            $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/test-fidelity/' . $fixture, $root);
            $runtime = new RuntimeFactory(self::repositoryRoot());
            $pdo = $runtime->database($database);
            $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;
            $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), $runtime, $database);
            $run = static function (string $command, array $positionals, array $options) use ($context): string {
                ob_start();
                try {
                    (new QueryCommand())->run($command, $positionals, $options, $context);

                    return (string) ob_get_contents();
                } finally {
                    ob_end_clean();
                }
            };
            $test($run, $projectId, $pdo, $root);
        } finally {
            $this->removeTempTree($base);
        }
    }
}

<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The coverage floors refuse a budget file they could not enforce.
 *
 * Every floor in coverage-budgets.json is handed to a tool that reads a missing
 * key as no floor: c8 compares against NaN and never fails, vitest skips the
 * threshold, and pcov-report read a missing component floor as 0 and a missing
 * file as an aggregate floor of 90. Each of those passed a run that compared
 * nothing. These tests plant each such budget and run the real tools.
 */
#[Group('quality')]
final class CoverageBudgetsTest extends KnossosTestCase
{
    /** A complete budget file for a run that measured the storage and transport components. */
    private const VALID = [
        'skipped_tests' => 4,
        'php' => ['aggregate_lines' => 50, 'components' => ['storage' => 50, 'transport' => 49.5]],
        'typescript' => ['lines' => 96.0, 'branches' => 88.8, 'functions' => 97.3, 'statements' => 96.0],
        'python' => ['lines' => 96.4],
        'rust' => ['lines' => 93.2],
        'mod' => ['lines' => 96.9, 'branches' => 88.2, 'functions' => 94.2, 'statements' => 95.4],
    ];

    private const MEASURED = ['storage', 'transport'];

    private string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();
        require_once self::guardTool('lib/coverage-budgets.php');
        $this->directory = sys_get_temp_dir() . '/knossos-coverage-budgets-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700, true);
    }

    protected function tearDown(): void
    {
        if ($this->directory !== '' && is_dir($this->directory)) {
            $this->runFixtureCommand(['rm', '-rf', $this->directory]);
        }
    }

    public function testCompleteBudgetsHaveNoErrors(): void
    {
        assertSame([], \coverageBudgetErrors(self::VALID, self::MEASURED));
        assertSame([], \coverageBudgetErrors(self::VALID, null));
    }

    /** The repository's own file must pass, or the guard would refuse every clean run. */
    public function testTheRepositoryBudgetsAreComplete(): void
    {
        [$budgets, $errors] = \coverageBudgetFile(self::repositoryRoot() . '/coverage-budgets.json', null);

        assertSame([], $errors);
        assertSame(true, is_array($budgets));
    }

    /** @return iterable<string, array{string}> */
    public static function floorKeys(): iterable
    {
        foreach (['php.aggregate_lines', 'typescript.lines', 'typescript.branches', 'typescript.functions', 'typescript.statements',
            'python.lines', 'rust.lines', 'mod.lines', 'mod.branches', 'mod.functions', 'mod.statements'] as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('floorKeys')]
    public function testAMissingFloorIsNamed(string $key): void
    {
        $budgets = self::VALID;
        [$runtime, $metric] = explode('.', $key);
        unset($budgets[$runtime][$metric]);

        assertSame([$key . ' is missing.'], \coverageBudgetErrors($budgets, self::MEASURED));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function unusableFloors(): iterable
    {
        yield 'NaN' => [NAN, 'typescript.lines is not a finite number.'];
        yield 'infinity' => [INF, 'typescript.lines is not a finite number.'];
        yield 'negative' => [-1, 'typescript.lines is -1; it must lie between 0 and 100.'];
        yield 'above 100' => [101, 'typescript.lines is 101; it must lie between 0 and 100.'];
        yield 'a numeric string' => ['95', 'typescript.lines is "95"; it must be a number.'];
        yield 'a boolean' => [true, 'typescript.lines is true; it must be a number.'];
        yield 'null' => [null, 'typescript.lines is missing.'];
    }

    #[DataProvider('unusableFloors')]
    public function testAFloorThatCannotActIsRefused(mixed $floor, string $error): void
    {
        $budgets = self::VALID;
        $budgets['typescript']['lines'] = $floor;

        assertSame([$error], \coverageBudgetErrors($budgets, self::MEASURED));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function unusableSkipBudgets(): iterable
    {
        yield 'missing' => [null, 'skipped_tests is missing.'];
        yield 'negative' => [-1, 'skipped_tests is -1; it must be at least 0.'];
        yield 'a fraction' => [1.5, 'skipped_tests is 1.5; it must be a whole number of tests.'];
        yield 'a numeric string' => ['4', 'skipped_tests is "4"; it must be a number.'];
        yield 'NaN' => [NAN, 'skipped_tests is not a finite number.'];
    }

    /** The skip budget is a count of tests, so it must be a whole number from 0 up. */
    #[DataProvider('unusableSkipBudgets')]
    public function testASkipBudgetThatCannotActIsRefused(mixed $budget, string $error): void
    {
        $budgets = self::VALID;
        if ($budget === null) {
            unset($budgets['skipped_tests']);
        } else {
            $budgets['skipped_tests'] = $budget;
        }

        assertSame([$error], \coverageBudgetErrors($budgets, self::MEASURED));
    }

    public function testAComponentFloorThatCannotActIsRefused(): void
    {
        $budgets = self::VALID;
        $budgets['php']['components']['storage'] = '98';

        assertSame(['php.components.storage is "98"; it must be a number.'], \coverageBudgetErrors($budgets, null));
    }

    public function testAMeasuredComponentWithoutAFloorIsNamed(): void
    {
        $budgets = self::VALID;
        unset($budgets['php']['components']['transport']);

        assertSame(
            ['php.components.transport is missing: the component is measured but has no floor.'],
            \coverageBudgetErrors($budgets, self::MEASURED),
        );
    }

    public function testAFloorNoComponentAnswersToIsNamed(): void
    {
        assertSame(
            ['php.components.transport has a floor, but no measured file belongs to that component.'],
            \coverageBudgetErrors(self::VALID, ['storage']),
        );
    }

    public function testMissingComponentFloorsAreRefused(): void
    {
        $budgets = self::VALID;
        unset($budgets['php']['components']);

        assertSame([
            'php.components is missing; it must map each PHP component to its floor.',
            'php.components.storage is missing: the component is measured but has no floor.',
            'php.components.transport is missing: the component is measured but has no floor.',
        ], \coverageBudgetErrors($budgets, self::MEASURED));
    }

    public function testAFileThatIsMissingOrNotAnObjectIsRefused(): void
    {
        $missing = $this->directory . '/absent.json';
        assertSame([null, ['the budget file ' . $missing . ' does not exist.']], \coverageBudgetFile($missing, null));

        file_put_contents($this->directory . '/list.json', '[1, 2]');
        assertSame(
            [null, ['the budget file ' . $this->directory . '/list.json must hold a JSON object.']],
            \coverageBudgetFile($this->directory . '/list.json', null),
        );

        file_put_contents($this->directory . '/broken.json', '{"php":');
        [$budgets, $errors] = \coverageBudgetFile($this->directory . '/broken.json', null);
        assertSame(null, $budgets);
        assertContains('is not valid JSON', $errors[0]);
    }

    /**
     * The profile stops before any report when a floor is missing. Before the
     * check, c8 was handed `--lines=undefined` and passed whatever it measured.
     */
    public function testTheCoverageProfileRefusesAMissingFloorBeforeAnyReportRuns(): void
    {
        $budgets = json_decode((string) file_get_contents(self::repositoryRoot() . '/coverage-budgets.json'), true, 512, JSON_THROW_ON_ERROR);
        unset($budgets['typescript']['lines']);
        $file = $this->writeBudgets($budgets);

        [$exit, $output, $errors] = $this->runFixtureCommandOutput([
            'sh', '-c', 'cd "$1" && exec sh "$2" report --budgets="$3"', 'sh',
            self::repositoryRoot(), self::guardTool('coverage'), $file,
        ]);

        assertSame(1, $exit, $output . $errors);
        assertContains('coverage budgets ' . $file . ': typescript.lines is missing.', $errors);
        // Refused up front: not one report ran, so nothing was measured against the gap.
        assertSame(false, str_contains($output . $errors, 'PHP aggregate line coverage'));
    }

    public function testTheCoverageProfileTakesOnlyABudgetsOption(): void
    {
        [$exit, , $errors] = $this->runFixtureCommandOutput([
            'sh', '-c', 'cd "$1" && exec sh "$2" report --budget=x', 'sh', self::repositoryRoot(), self::guardTool('coverage'),
        ]);

        assertSame(64, $exit);
        assertContains('usage: tools/coverage', $errors);
    }

    public function testPcovReportPassesWhenEveryMeasuredComponentHasAFloor(): void
    {
        $root = $this->fixtureTree();

        [$exit, $output, $errors] = $this->pcovReport($root, $this->writeBudgets(self::VALID));

        assertSame(0, $exit, $output . $errors);
        assertContains('PHP component storage', $output);
        assertContains('PHP component transport', $output);
        assertSame(true, is_file($root . '/coverage/php/summary.json'));
    }

    /** The same tree under a component floor it does not meet is refused, so the pass above is the floor's doing. */
    public function testPcovReportFailsAComponentBelowItsFloor(): void
    {
        $budgets = self::VALID;
        $budgets['php']['components']['transport'] = 100;

        [$exit, $output, $errors] = $this->pcovReport($this->fixtureTree(), $this->writeBudgets($budgets));

        assertSame(1, $exit, $output . $errors);
        assertSame(1, preg_match('/^PHP component transport +50\.00% \(1\/2\), floor 100\.00% FAIL$/m', $output), $output);
        assertSame(1, preg_match('/^PHP component storage .* PASS$/m', $output), $output);
    }

    /** With every component floor at 0, the aggregate floor alone refuses the run. */
    public function testPcovReportFailsAnAggregateBelowItsFloor(): void
    {
        $budgets = self::VALID;
        $budgets['php']['aggregate_lines'] = 100;
        $budgets['php']['components'] = ['storage' => 0, 'transport' => 0];

        [$exit, $output, $errors] = $this->pcovReport($this->fixtureTree(), $this->writeBudgets($budgets));

        assertSame(1, $exit, $output . $errors);
        assertContains("PHP aggregate 50.00%, floor 100.00% FAIL\n", $output);
        assertSame(0, preg_match('/^PHP component .* FAIL$/m', $output), $output);
    }

    public function testPcovReportRefusesAMeasuredComponentWithoutAFloor(): void
    {
        $budgets = self::VALID;
        unset($budgets['php']['components']['transport']);
        $file = $this->writeBudgets($budgets);
        $root = $this->fixtureTree();

        [$exit, $output, $errors] = $this->pcovReport($root, $file);

        assertSame(1, $exit, $output . $errors);
        assertContains('coverage budgets ' . $file . ': php.components.transport is missing: the component is measured but has no floor.', $errors);
        assertSame(false, is_file($root . '/coverage/php/summary.json'));
    }

    public function testPcovReportRefusesAMissingBudgetFile(): void
    {
        $root = $this->fixtureTree();

        [$exit, $output, $errors] = $this->pcovReport($root, $root . '/absent.json');

        assertSame(1, $exit, $output . $errors);
        assertContains('the budget file ' . $root . '/absent.json does not exist.', $errors);
    }

    public function testPcovReportReadsTheRootsOwnBudgetsByDefault(): void
    {
        $root = $this->fixtureTree();
        $budgets = self::VALID;
        $budgets['php']['aggregate_lines'] = '50';
        file_put_contents($root . '/coverage-budgets.json', json_encode($budgets, JSON_THROW_ON_ERROR));

        [$exit, , $errors] = $this->runFixtureCommandOutput([PHP_BINARY, self::guardTool('pcov-report.php'), '--root=' . $root]);

        assertSame(1, $exit, $errors);
        assertContains('php.aggregate_lines is "50"; it must be a number.', $errors);
    }

    public function testPcovReportTakesOnlyItsOwnOptions(): void
    {
        [$exit, , $errors] = $this->runFixtureCommandOutput([PHP_BINARY, self::guardTool('pcov-report.php'), '--budget=x']);

        assertSame(2, $exit);
        assertContains('usage: php tools/pcov-report.php', $errors);
    }

    /** @param array<mixed> $budgets */
    private function writeBudgets(array $budgets): string
    {
        $file = $this->directory . '/budgets-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($file, json_encode($budgets, JSON_THROW_ON_ERROR));

        return $file;
    }

    /** A tree with one storage and one transport file, each half covered by one recorded process. */
    private function fixtureTree(): string
    {
        $root = $this->directory . '/tree';
        mkdir($root . '/src/Store', 0o700, true);
        mkdir($root . '/src/Mcp', 0o700, true);
        mkdir($root . '/coverage/php', 0o700, true);
        file_put_contents($root . '/src/Store/Thing.php', "<?php\n\$a = 1;\n\$b = 2;\n");
        file_put_contents($root . '/src/Mcp/Tool.php', "<?php\n\$a = 1;\n\$b = 2;\n");
        file_put_contents($root . '/coverage/php/pcov-1.json', json_encode([
            $root . '/src/Store/Thing.php' => [2 => 1, 3 => 0],
            $root . '/src/Mcp/Tool.php' => [2 => 1, 3 => 0],
        ], JSON_THROW_ON_ERROR));

        return $root;
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function pcovReport(string $root, string $budgets): array
    {
        return $this->runFixtureCommandOutput([PHP_BINARY, self::guardTool('pcov-report.php'), '--root=' . $root, '--budgets=' . $budgets]);
    }
}

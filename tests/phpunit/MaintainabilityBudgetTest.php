<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Each maintainability budget refuses the tree once its limit is below what the
 * tree measures, and a limit that is missing or not a number is refused before
 * anything is measured. The tests run the real report against this repository
 * with a budget file of their own.
 */
#[Group('quality')]
final class MaintainabilityBudgetTest extends KnossosTestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/knossos-maintainability-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700, true);
    }

    protected function tearDown(): void
    {
        if ($this->directory !== '' && is_dir($this->directory)) {
            $this->runFixtureCommand(['rm', '-rf', $this->directory]);
        }
    }

    public function testTheRepositoryIsWithinItsOwnBudgets(): void
    {
        [$exit, $output, $errors] = $this->report(self::repositoryRoot() . '/maintainability-budgets.json');

        assertSame(0, $exit, $errors);
        assertContains('Maintainability report:', $output);
        $report = json_decode((string) file_get_contents($this->directory . '/maintainability.json'), true, 512, JSON_THROW_ON_ERROR);
        assertSame(1, $report['schema_version']);
    }

    /** @return iterable<string, array{string}> */
    public static function metrics(): iterable
    {
        foreach (['cross_file_duplicate_blocks', 'max_php_function_complexity', 'max_php_function_lines', 'max_dependency_fanout'] as $metric) {
            yield $metric => [$metric];
        }
    }

    #[DataProvider('metrics')]
    public function testABudgetOfZeroRefusesTheTreeAndNamesTheMetric(string $metric): void
    {
        $budgets = $this->repositoryBudgets();
        $budgets[$metric] = 0;

        [$exit, , $errors] = $this->report($this->write($budgets));

        assertSame(1, $exit, $errors);
        assertSame(1, preg_match('/^' . $metric . ' is [1-9]\d*; budget is 0\.$/m', $errors), $errors);
    }

    public function testAMissingBudgetIsRefusedBeforeAnythingIsMeasured(): void
    {
        $budgets = $this->repositoryBudgets();
        unset($budgets['max_dependency_fanout']);
        $file = $this->write($budgets);

        [$exit, $output, $errors] = $this->report($file);

        assertSame(1, $exit, $errors);
        assertContains('maintainability budgets ' . $file . ': max_dependency_fanout is missing.', $errors);
        assertSame('', $output);
    }

    public function testAQuotedBudgetIsRefusedRatherThanCast(): void
    {
        $budgets = $this->repositoryBudgets();
        $budgets['max_php_function_lines'] = '205';

        [$exit, , $errors] = $this->report($this->write($budgets));

        assertSame(1, $exit, $errors);
        assertContains('max_php_function_lines is "205"; it must be a number.', $errors);
    }

    public function testItTakesOnlyItsOwnOptions(): void
    {
        [$exit, , $errors] = $this->runFixtureCommandOutput([PHP_BINARY, self::guardTool('maintainability-report.php'), '--budget=x']);

        assertSame(2, $exit);
        assertContains('usage: php tools/maintainability-report.php', $errors);
    }

    /** @return array<string, mixed> */
    private function repositoryBudgets(): array
    {
        return json_decode((string) file_get_contents(self::repositoryRoot() . '/maintainability-budgets.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $budgets */
    private function write(array $budgets): string
    {
        $file = $this->directory . '/budgets.json';
        file_put_contents($file, json_encode($budgets, JSON_THROW_ON_ERROR));

        return $file;
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function report(string $budgets): array
    {
        return $this->runFixtureCommandOutput([
            PHP_BINARY, self::guardTool('maintainability-report.php'), '--root=' . self::repositoryRoot(), '--budgets=' . $budgets,
            // Written beside the budgets, so a test run leaves the checkout's report alone.
            '--report=' . $this->directory . '/maintainability.json',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The benchmark refuses a run over its limits, a run that measured nothing, and
 * a budget file it could not hold a run to. The end-to-end tests run the real
 * benchmark on its smallest corpus, one file per language, which takes a few
 * seconds.
 */
#[Group('quality')]
final class BenchmarkBudgetTest extends KnossosTestCase
{
    /** Limits a one-file-per-language corpus stays well inside. */
    private const GENEROUS = [
        'files_per_language' => 1,
        'cold_scan_seconds' => 60,
        'incremental_scan_seconds' => 60,
        'query_seconds' => 30,
        'peak_rss_mb' => 4096,
        'sqlite_mb' => 256,
    ];

    private string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();
        require_once self::guardTool('lib/benchmark-budgets.php');
        $this->directory = self::scratchPath('knossos-benchmark-budget');
        mkdir($this->directory . '/data', 0o700, true);
        mkdir($this->directory . '/tmp', 0o700);
    }

    public function testARunWithinItsLimitsPasses(): void
    {
        [$exit, $output, $errors] = $this->benchmark(['schema_version' => 1, 'sizes' => ['tiny' => self::GENEROUS]]);

        assertSame(0, $exit, $output . $errors);
        assertContains('tiny: cold=', $output);
        assertSame(true, $this->report()['passed']);
        // Where /proc exists, peak memory is read from it and a 0 would be refused;
        // only a system without it may say the peak was not measured.
        assertSame(!is_dir('/proc/self'), str_contains($errors, 'peak_rss_mb NOT measured'), $errors);
    }

    /** The same run against a limit of 0 is refused, so the pass above is the limits' doing. */
    public function testALimitOfZeroIsExceededAndNamed(): void
    {
        $limits = self::GENEROUS;
        $limits['query_seconds'] = 0;

        [$exit, $output, $errors] = $this->benchmark(['schema_version' => 1, 'sizes' => ['tiny' => $limits]]);

        assertSame(1, $exit, $output . $errors);
        assertSame(1, preg_match('/^tiny\.query_seconds \d+\.\d\d exceeds 0\.00$/m', $errors), $errors);
        assertSame(false, $this->report()['passed']);
        // exit() inside the try skipped its finally, and the corpus stayed behind.
        assertSame([], array_values(array_diff((array) scandir($this->directory . '/tmp'), ['.', '..'])));
    }

    /** A file with no sizes ran no scan and passed; a run that measured nothing proves nothing. */
    public function testABudgetFileWithNoSizesIsRefused(): void
    {
        [$exit, $output, $errors] = $this->benchmark(['schema_version' => 1, 'sizes' => []]);

        assertSame(1, $exit, $output . $errors);
        assertContains('no sizes measured', $errors);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidSizes(): iterable
    {
        $missing = self::GENEROUS;
        unset($missing['sqlite_mb']);
        yield 'a missing limit' => [$missing, 'sizes.tiny.sqlite_mb is missing.'];
        yield 'a quoted limit' => [['cold_scan_seconds' => '10'] + self::GENEROUS, 'sizes.tiny.cold_scan_seconds is "10"; it must be a number.'];
        yield 'a negative limit' => [['peak_rss_mb' => -1] + self::GENEROUS, 'sizes.tiny.peak_rss_mb is -1; it must be at least 0.'];
        yield 'no files to scan' => [['files_per_language' => 0] + self::GENEROUS, 'sizes.tiny.files_per_language must be a whole number at least 1.'];
    }

    /** @param array<string, mixed> $limits */
    #[DataProvider('invalidSizes')]
    public function testASizeWhoseLimitCannotActIsRefusedBeforeAnyScan(array $limits, string $error): void
    {
        [$exit, $output, $errors] = $this->benchmark(['schema_version' => 1, 'sizes' => ['tiny' => $limits]]);

        assertSame(2, $exit, $output . $errors);
        assertContains($error, $errors);
        assertSame('', $output);
    }

    /** A scenario runs its own corpus after the sizes and is held to its own limit. */
    public function testAScenarioOverItsLimitIsNamed(): void
    {
        [$exit, $output, $errors] = $this->benchmark([
            'schema_version' => 1,
            'sizes' => ['tiny' => self::GENEROUS],
            'scenarios' => ['test_fidelity_rescan_one_file' => ['incremental_scan_seconds' => 0]],
        ]);

        assertSame(1, $exit, $output . $errors);
        assertContains('test_fidelity_rescan_one_file: cold=', $output);
        assertSame(1, preg_match('/^scenarios\.test_fidelity_rescan_one_file\.incremental_scan_seconds \d+\.\d\d exceeds 0\.00$/m', $errors), $errors);
        $actual = $this->report()['scenarios']['test_fidelity_rescan_one_file']['actual'];
        assertSame(5, count($actual['incremental_rounds_seconds']));
        assertSame(true, in_array($actual['incremental_scan_seconds'], $actual['incremental_rounds_seconds'], true));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalidScenarios(): iterable
    {
        yield 'an unknown scenario' => [['rescan_everything' => ['incremental_scan_seconds' => 1]], 'scenarios.rescan_everything is not a scenario the benchmark runs (test_fidelity_rescan_one_file).'];
        yield 'a missing limit' => [['test_fidelity_rescan_one_file' => []], 'scenarios.test_fidelity_rescan_one_file.incremental_scan_seconds is missing.'];
        yield 'a quoted limit' => [['test_fidelity_rescan_one_file' => ['incremental_scan_seconds' => '2']], 'scenarios.test_fidelity_rescan_one_file.incremental_scan_seconds is "2"; it must be a number.'];
        yield 'a list' => [[['incremental_scan_seconds' => 1]], 'scenarios must be an object of scenarios.'];
    }

    #[DataProvider('invalidScenarios')]
    public function testAScenarioWhoseLimitCannotActIsRefused(mixed $scenarios, string $error): void
    {
        assertSame([$error], \benchmarkBudgetErrors(['schema_version' => 1, 'sizes' => ['tiny' => self::GENEROUS], 'scenarios' => $scenarios]));
    }

    public function testAScenarioWithinItsLimitBreaksNothing(): void
    {
        assertSame([], \benchmarkScenarioViolations('test_fidelity_rescan_one_file', 2.08, 2.08));
        assertSame(
            ['scenarios.test_fidelity_rescan_one_file.incremental_scan_seconds 2.09 exceeds 2.08'],
            \benchmarkScenarioViolations('test_fidelity_rescan_one_file', 2.08, 2.09),
        );
    }

    public function testMeasurementsWithinTheirLimitsBreakNone(): void
    {
        assertSame([], \benchmarkViolations('tiny', self::GENEROUS, $this->measured(['peak_rss_mb' => 300.5]), true));
    }

    public function testAMeasurementOverItsLimitIsNamed(): void
    {
        assertSame(
            ['tiny.sqlite_mb 300.00 exceeds 256.00'],
            \benchmarkViolations('tiny', self::GENEROUS, $this->measured(['sqlite_mb' => 300]), true),
        );
    }

    /** A peak of 0 where /proc exists is a failed reading, not a frugal scan. */
    public function testAPeakMemoryOfZeroIsRefusedWhereProcExists(): void
    {
        assertSame(
            ['tiny.peak_rss_mb was not measured: /proc reported no resident memory for the scans'],
            \benchmarkViolations('tiny', self::GENEROUS, $this->measured(['peak_rss_mb' => 0.0]), true),
        );
        // Without /proc there is nothing to read; the runner says so on stderr instead.
        assertSame([], \benchmarkViolations('tiny', self::GENEROUS, $this->measured(['peak_rss_mb' => 0.0]), false));
    }

    /**
     * @param array<string, int|float> $overrides
     * @return array<string, int|float>
     */
    private function measured(array $overrides): array
    {
        return $overrides + ['cold_scan_seconds' => 1.0, 'incremental_scan_seconds' => 1.0, 'query_seconds' => 0.05, 'peak_rss_mb' => 300.0, 'sqlite_mb' => 0.4];
    }

    /**
     * @param array<string, mixed> $budgets
     * @return array{0: int, 1: string, 2: string}
     */
    private function benchmark(array $budgets): array
    {
        $file = $this->directory . '/budgets.json';
        file_put_contents($file, json_encode($budgets, JSON_THROW_ON_ERROR));

        return $this->runFixtureCommandOutput([
            'env', 'KNOSSOS_DATA_DIR=' . $this->directory . '/data', 'TMPDIR=' . $this->directory . '/tmp',
            PHP_BINARY, self::guardTool('benchmark.php'), '--budgets=' . $file, '--report=' . $this->directory . '/report.json',
        ]);
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return json_decode((string) file_get_contents($this->directory . '/report.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}

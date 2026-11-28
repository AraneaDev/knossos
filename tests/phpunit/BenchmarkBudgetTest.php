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
    #[Group('slow')]
    public function testAScenarioOverItsLimitIsNamed(): void
    {
        [$exit, $output, $errors] = $this->benchmark([
            'schema_version' => 1,
            'sizes' => ['tiny' => self::GENEROUS],
            'scenarios' => ['test_fidelity_rescan_one_file' => ['incremental_scan_seconds' => 0], 'test_fidelity_rescan_one_file_php' => ['incremental_scan_seconds' => 0], 'test_fidelity_rescan_one_file_python' => ['incremental_scan_seconds' => 0], 'test_fidelity_rescan_one_file_rust' => ['incremental_scan_seconds' => 0]],
        ]);

        assertSame(1, $exit, $output . $errors);
        assertContains('test_fidelity_rescan_one_file: cold=', $output);
        assertSame(1, preg_match('/^scenarios\.test_fidelity_rescan_one_file\.incremental_scan_seconds \d+\.\d\d exceeds 0\.00$/m', $errors), $errors);
        assertSame(1, preg_match('/^scenarios\.test_fidelity_rescan_one_file_php\.incremental_scan_seconds \d+\.\d\d exceeds 0\.00$/m', $errors), $errors);
        assertSame(1, preg_match('/^scenarios\.test_fidelity_rescan_one_file_python\.incremental_scan_seconds \d+\.\d\d exceeds 0\.00$/m', $errors), $errors);
        assertSame(1, preg_match('/^scenarios\.test_fidelity_rescan_one_file_rust\.incremental_scan_seconds \d+\.\d\d exceeds 0\.00$/m', $errors), $errors);
        $scenarios = $this->report()['scenarios'];
        $actual = $scenarios['test_fidelity_rescan_one_file']['actual'];
        assertSame(5, count($actual['incremental_rounds_seconds']));
        // Every round parsed the production file and its five test files again.
        assertSame(6, $actual['incremental_parsed_files']);
        assertSame(true, in_array($actual['incremental_scan_seconds'], $actual['incremental_rounds_seconds'], true));
        // A PHP round parses the production class alone; its five test files are reused and judged against it.
        assertSame([5, 1], [count($scenarios['test_fidelity_rescan_one_file_php']['actual']['incremental_rounds_seconds']), $scenarios['test_fidelity_rescan_one_file_php']['actual']['incremental_parsed_files']]);
        // A Python round parses the class and its five test files again, as each reads the class's module to resolve its patches.
        assertSame([5, 6], [count($scenarios['test_fidelity_rescan_one_file_python']['actual']['incremental_rounds_seconds']), $scenarios['test_fidelity_rescan_one_file_python']['actual']['incremental_parsed_files']]);
        // A Rust round parses the trait's module and its five test files again, as each imports its mock from that module.
        assertSame([5, 6], [count($scenarios['test_fidelity_rescan_one_file_rust']['actual']['incremental_rounds_seconds']), $scenarios['test_fidelity_rescan_one_file_rust']['actual']['incremental_parsed_files']]);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalidScenarios(): iterable
    {
        yield 'an unknown scenario' => [['rescan_everything' => ['incremental_scan_seconds' => 1]], 'scenarios.rescan_everything is not a scenario the benchmark runs (test_fidelity_rescan_one_file, test_fidelity_rescan_one_file_php, test_fidelity_rescan_one_file_python, test_fidelity_rescan_one_file_rust, audit_tests_on_request_medium, check_fixtures_medium).'];
        yield 'a query scenario with a rescan limit' => [['check_fixtures_medium' => ['incremental_scan_seconds' => 1]], 'scenarios.check_fixtures_medium.query_seconds is missing.'];
        yield 'a quoted query limit' => [['audit_tests_on_request_medium' => ['query_seconds' => '2']], 'scenarios.audit_tests_on_request_medium.query_seconds is "2"; it must be a number.'];
        yield 'a rescan scenario with a query limit' => [['test_fidelity_rescan_one_file_php' => ['query_seconds' => 1]], 'scenarios.test_fidelity_rescan_one_file_php.incremental_scan_seconds is missing.'];
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
        assertSame([], \benchmarkScenarioViolations('test_fidelity_rescan_one_file', 2.07, 2.07));
        assertSame(
            ['scenarios.test_fidelity_rescan_one_file.incremental_scan_seconds 2.08 exceeds 2.07'],
            \benchmarkScenarioViolations('test_fidelity_rescan_one_file', 2.07, 2.08),
        );
    }

    public function testAQueryScenarioIsHeldToItsQueryLimit(): void
    {
        assertSame([], \benchmarkScenarioViolations('check_fixtures_medium', 3.0, 3.0, 'query_seconds'));
        assertSame(
            ['scenarios.check_fixtures_medium.query_seconds 3.01 exceeds 3.00'],
            \benchmarkScenarioViolations('check_fixtures_medium', 3.0, 3.01, 'query_seconds'),
        );
    }

    /** A query that answered less than its corpus gives would time a cheaper answer, and pass for it. */
    public function testAQueryScenarioRoundAnsweringLessIsRefused(): void
    {
        assertSame(null, \benchmarkQueryScenarioError('audit_tests_on_request_medium', 0, 24, null));
        assertSame(null, \benchmarkQueryScenarioError('check_fixtures_medium', 4, 50, 500));
        assertSame(
            'scenarios.audit_tests_on_request_medium round 3 reported 23 items, fewer than the 24 its corpus gives; it would not measure the query it is for.',
            \benchmarkQueryScenarioError('audit_tests_on_request_medium', 2, 23, null),
        );
        assertSame(
            'scenarios.check_fixtures_medium round 1 reported an unknown number of items, fewer than the 50 its corpus gives; it would not measure the query it is for.',
            \benchmarkQueryScenarioError('check_fixtures_medium', 0, null, 500),
        );
        assertSame(
            'scenarios.check_fixtures_medium round 2 checked 499 fixture files, not the 500 its corpus holds; it would not measure the query it is for.',
            \benchmarkQueryScenarioError('check_fixtures_medium', 1, 50, 499),
        );
        assertSame(
            'scenarios.check_fixtures_medium round 1 checked an unknown number of fixture files, not the 500 its corpus holds; it would not measure the query it is for.',
            \benchmarkQueryScenarioError('check_fixtures_medium', 0, 50, null),
        );
    }

    /**
     * The on-request corpus is the size profile byte for byte, with the tests
     * and fixtures it adds on top: each of those, and nothing else, differs.
     */
    public function testTheOnRequestCorpusIsTheSizeProfileWithTestsAndFixturesAdded(): void
    {
        $profile = $this->directory . '/profile';
        $onRequest = $this->directory . '/on-request';
        [$exit, $profileOut] = $this->runFixtureCommandOutput([PHP_BINARY, self::guardTool('generate-benchmark-corpus.php'), $profile, '6']);
        assertSame(0, $exit, $profileOut);
        [$exit, $output, $errors] = $this->runFixtureCommandOutput([PHP_BINARY, self::guardTool('generate-on-request-corpus.php'), $onRequest, '6', '--tests', '--fixtures=12']);
        assertSame(0, $exit, $output . $errors);
        $generated = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        assertSame([6, 18, 12], [$generated['files_per_language'], $generated['generated_source_files'], $generated['fixture_files']]);
        assertSame(18, count($generated['test_files']));

        $before = self::tree($profile);
        $after = self::tree($onRequest);
        // The size profile's files are untouched, but the tsconfig, which now includes the tests.
        assertSame(['typescript/tsconfig.json'], array_keys(array_diff_assoc(array_intersect_key($after, $before), $before)));
        $added = array_keys(array_diff_key($after, $before));
        $fixtures = array_values(array_filter($added, static fn(string $path): bool => str_starts_with($path, 'fixtures/')));
        assertSame(12, count($fixtures));
        assertSame(3, count(array_filter($fixtures, static fn(string $path): bool => str_ends_with($path, '.yaml'))));
        $expected = [...$generated['test_files'], ...$fixtures, 'php/src/Invoice.php', 'pyproject.toml', 'python/app/parcel.py', 'typescript/src/shipment.ts'];
        sort($expected);
        assertSame($expected, $added);
        // The first and every fifth TypeScript and Python test doubles its own service; the others its dependency.
        assertContains("vi.spyOn(service, 'dependency')", (string) file_get_contents($onRequest . '/typescript/tests/service-0004.test.ts'));
        assertContains("vi.spyOn(dependency, 'dependency')", (string) file_get_contents($onRequest . '/typescript/tests/service-0003.test.ts'));
        assertContains('patch.object(Service0000, "dependency"', (string) file_get_contents($onRequest . '/python/tests/test_service_0000.py'));
        assertContains('patch.object(Service0000, "dependency"', (string) file_get_contents($onRequest . '/python/tests/test_service_0001.py'));
        // Every tenth fixture misses a required field of its first record.
        $drifted = json_decode((string) file_get_contents($onRequest . '/fixtures/invoices/invoices-0009.json'), true, 512, JSON_THROW_ON_ERROR);
        assertSame([['id', 'number'], ['id', 'number', 'paid']], [array_keys($drifted['invoices'][0]), array_keys($drifted['invoices'][1])]);
        assertSame(64, strlen((string) $generated['corpus_sha256']));
    }

    /** A rescan that parsed fewer files than its edit reaches would time something else, and pass for it. */
    public function testAScenarioRoundParsingOtherFilesIsRefused(): void
    {
        assertSame(null, \benchmarkScenarioParseError('test_fidelity_rescan_one_file', 0, 6, 6));
        assertSame(
            'scenarios.test_fidelity_rescan_one_file round 2 parsed 1 files, not the 6 its edit reaches; it would not measure the rescan it is for.',
            \benchmarkScenarioParseError('test_fidelity_rescan_one_file', 1, 6, 1),
        );
        assertSame(
            'scenarios.test_fidelity_rescan_one_file round 1 parsed an unknown number of files, not the 6 its edit reaches; it would not measure the rescan it is for.',
            \benchmarkScenarioParseError('test_fidelity_rescan_one_file', 0, 6, null),
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

    /**
     * Each file's sha256 under `$root`, by relative path.
     *
     * @return array<string, string>
     */
    private static function tree(string $root): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $files[substr($entry->getPathname(), strlen($root) + 1)] = (string) hash_file('sha256', $entry->getPathname());
        }
        ksort($files);

        return $files;
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return json_decode((string) file_get_contents($this->directory . '/report.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}

<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The gate lane says which budget it failed on. `knossos quality-gate` prints
 * one line, "Architecture quality budgets failed.", and a CI log that says only
 * that leaves the reader to reproduce the whole lane to learn which budget
 * broke. tools/gate-report.php reads the gate's JSON and prints every check's
 * value against its limit; CommandsTest feeds it the command's real output.
 */
#[Group('quality')]
final class GateReportTest extends KnossosTestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = sys_get_temp_dir() . '/knossos-gate-report-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testEachFailingCheckIsNamedWithItsValueAndLimit(): void
    {
        [$exit, $output, $errors] = $this->report(false, [
            ['metric' => 'new_cycles', 'actual' => 0, 'limit' => 0, 'passed' => true],
            ['metric' => 'unreferenced_candidates', 'actual' => 158, 'limit' => 110, 'passed' => false],
            ['metric' => 'warning_diagnostics', 'actual' => 9, 'limit' => 4, 'passed' => false],
        ]);

        assertSame(1, $exit, $errors);
        assertContains("gate: new_cycles 0, limit 0: pass\n", $output);
        assertContains("gate: unreferenced_candidates 158, limit 110: FAIL\n", $output);
        assertContains("gate: warning_diagnostics 9, limit 4: FAIL\n", $output);
        assertContains('gate: 2 of 3 budgets failed: unreferenced_candidates, warning_diagnostics.', $errors);
    }

    public function testAnIndeterminateCheckFailsAndSaysWhy(): void
    {
        [$exit, $output] = $this->report(false, [
            ['metric' => 'boundary_violations', 'actual' => 0, 'limit' => 5, 'passed' => false, 'indeterminate' => true, 'indeterminate_reason' => 'boundary_violation_scan_truncated'],
        ]);

        assertSame(1, $exit);
        assertContains('gate: boundary_violations 0, limit 5: FAIL (indeterminate: boundary_violation_scan_truncated)', $output);
    }

    public function testAPassingGatePasses(): void
    {
        [$exit, $output, $errors] = $this->report(true, [
            ['metric' => 'new_cycles', 'actual' => 0, 'limit' => 0, 'passed' => true],
            ['metric' => 'hub_degree_growth', 'actual' => 1, 'limit' => 3, 'passed' => true],
        ]);

        assertSame(0, $exit, $errors);
        assertContains('gate: all 2 budgets passed.', $output);
    }

    public function testAGateThatCheckedNothingIsRefused(): void
    {
        [$exit, , $errors] = $this->report(true, []);

        assertSame(1, $exit);
        assertContains('no budget was checked', $errors);
    }

    public function testAFailureNoCheckAccountsForIsStillAFailure(): void
    {
        [$exit, , $errors] = $this->report(false, [['metric' => 'new_cycles', 'actual' => 0, 'limit' => 0, 'passed' => true]]);

        assertSame(1, $exit);
        assertContains('no check accounts for', $errors);
    }

    public function testAFileThatIsNotAGateResultIsAnError(): void
    {
        file_put_contents($this->file, 'Architecture quality budgets failed.');
        [$exit, , $errors] = $this->runFixtureCommandOutput([PHP_BINARY, self::guardTool('gate-report.php'), $this->file]);
        assertSame(2, $exit);
        assertContains('is not a gate result', $errors);

        file_put_contents($this->file, '{"data": {}}');
        [$exit, , $errors] = $this->runFixtureCommandOutput([PHP_BINARY, self::guardTool('gate-report.php'), $this->file]);
        assertSame(2, $exit);
        assertContains('holds no data.passed and data.checks', $errors);
    }

    /**
     * @param list<array<string, mixed>> $checks
     * @return array{0: int, 1: string, 2: string}
     */
    private function report(bool $passed, array $checks): array
    {
        file_put_contents($this->file, json_encode([
            'summary' => $passed ? 'Architecture quality budgets passed.' : 'Architecture quality budgets failed.',
            'data' => ['passed' => $passed, 'checks' => $checks],
        ], JSON_THROW_ON_ERROR));

        return $this->runFixtureCommandOutput([PHP_BINARY, self::guardTool('gate-report.php'), $this->file]);
    }
}

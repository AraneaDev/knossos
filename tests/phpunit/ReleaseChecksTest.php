<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit;

use PHPUnit\Framework\Attributes\Group;

/**
 * The release lane's report-only steps now judge what they report.
 *
 * Three steps passed on an exit status that said nothing about the result:
 * the MCP Inspector's `tools/list` (the server answered), the lifecycle
 * rollback (the copy ran) and the quality image's Trivy scan (`--exit-code 0`).
 * Each now hands its output to a check, and these tests plant the violation
 * that check exists to catch. The steps themselves need Docker and the
 * network, so the checks are driven with recorded output.
 */
#[Group('quality')]
final class ReleaseChecksTest extends KnossosTestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = self::scratchPath('knossos-release-checks');
        mkdir($this->directory, 0o700, true);
    }

    protected function tearDown(): void
    {
        if ($this->directory !== '' && is_dir($this->directory)) {
            $this->runFixtureCommand(['rm', '-rf', $this->directory]);
        }
    }

    /** The server must advertise every catalogued tool and nothing else. */
    public function testTheInspectorToolListIsHeldToTheCatalogue(): void
    {
        $catalog = $this->write('catalog.md', "# MCP tool reference\n\n## `list_projects`\n\nText.\n\n## `scan_project`\n\nText.\n");

        [$exit, $output, $errors] = $this->inspect($catalog, ['tools' => [['name' => 'scan_project'], ['name' => 'list_projects']]]);
        assertSame(0, $exit, $errors);
        assertContains('advertised all 2 catalogued tools', $output);

        [$exit, , $errors] = $this->inspect($catalog, ['tools' => [['name' => 'list_projects']]]);
        assertSame(1, $exit, 'a server short of a tool passed');
        assertContains('the server advertised 1 tools, the catalogue has 2; not advertised: scan_project', $errors);

        [$exit, , $errors] = $this->inspect($catalog, ['tools' => [['name' => 'list_projects'], ['name' => 'scan_project'], ['name' => 'stray']]]);
        assertSame(1, $exit);
        assertContains('not in the catalogue: stray', $errors);

        [$exit, , $errors] = $this->inspect($catalog, ['tools' => []]);
        assertSame(1, $exit, 'a server with no tools passed');

        [$exit, , $errors] = $this->inspect($catalog, 'Error: server exited');
        assertSame(1, $exit);
        assertContains('is not JSON', $errors);
    }

    /** The repository's own catalogue names every tool the server can offer. */
    public function testTheInspectorCheckReadsTheRepositoryCatalogue(): void
    {
        preg_match_all('/^## `([a-z0-9_]+)`$/m', (string) file_get_contents(self::repositoryRoot() . '/docs/reference/mcp-tools.md'), $names);
        $tools = array_map(static fn(string $name): array => ['name' => $name], $names[1]);
        file_put_contents($this->directory . '/answer.json', json_encode(['tools' => $tools], JSON_THROW_ON_ERROR));

        [$exit, $output, $errors] = $this->runFixtureCommandOutput([PHP_BINARY, self::guardTool('inspector-tools-check.php'), $this->directory . '/answer.json']);

        assertSame(0, $exit, $errors);
        assertContains(sprintf('advertised all %d catalogued tools', count($tools)), $output);
        self::assertGreaterThan(30, count($tools));
    }

    /** A rollback that left the upgraded database in place is refused, not passed for having run. */
    public function testTheLifecycleRollbackMustRestoreTheBackup(): void
    {
        $before = $this->snapshots('before', ['scan_a' => true]);
        $upgraded = $this->snapshots('upgraded', ['scan_b' => true, 'scan_a' => false]);

        [$exit, $output, $errors] = $this->restoreCheck($before, $upgraded, $this->snapshots('restored', ['scan_a' => true]));
        assertSame(0, $exit, $errors);
        assertContains("restored the backup's 1 snapshot(s), scan_a active", $output);

        [$exit, , $errors] = $this->restoreCheck($before, $upgraded, $this->snapshots('unrestored', ['scan_b' => true, 'scan_a' => false]));
        assertSame(1, $exit, 'a rollback that changed nothing passed');
        assertContains('the restored database is not the backup: it holds 2 snapshots with scan_b active, the backup 1 with scan_a active', $errors);

        [$exit, , $errors] = $this->restoreCheck($before, $before, $this->snapshots('same', ['scan_a' => true]));
        assertSame(1, $exit, 'an upgrade that added nothing proves no rollback');
        assertContains('the upgrade scan added no snapshot', $errors);

        [$exit, , $errors] = $this->restoreCheck($before, $upgraded, $this->write('broken.json', '{"data": {}}'));
        assertSame(1, $exit);
        assertContains('the restored snapshot listing holds no data.snapshots', $errors);
    }

    /** A HIGH or CRITICAL finding that is not on the allowlist fails the scan it used to pass. */
    public function testANewQualityImageFindingIsRefused(): void
    {
        $allowlist = $this->write('allowlist.txt', "# known\nCVE-2026-0001\tstdlib\nCVE-2026-0002\tundici\n");
        $known = [['VulnerabilityID' => 'CVE-2026-0001', 'PkgName' => 'stdlib', 'Severity' => 'HIGH']];

        [$exit, $output, $errors] = $this->scanCheck($allowlist, [['Target' => 'usr/local/bin/trivy', 'Vulnerabilities' => $known]]);
        assertSame(0, $exit, $errors);
        assertContains('1 finding(s), all on the allowlist', $output);
        assertContains('no longer found, remove from the allowlist: CVE-2026-0002 undici', $output);

        $planted = [...$known, ['VulnerabilityID' => 'CVE-2026-9999', 'PkgName' => 'openssl', 'Severity' => 'CRITICAL']];
        [$exit, , $errors] = $this->scanCheck($allowlist, [['Target' => 'debian', 'Vulnerabilities' => $planted]]);
        assertSame(1, $exit, 'a new CRITICAL finding passed');
        assertContains('1 finding(s) not on', $errors);
        assertContains('CRITICAL CVE-2026-9999 in openssl', $errors);

        [$exit, , $errors] = $this->scanCheck($allowlist, null);
        assertSame(1, $exit, 'an empty report passed');
        assertContains('it has no Results', $errors);
    }

    /**
     * On a pull request a new finding is reported, clearly, without failing:
     * it arrives with a database update, not with the change. Every other run
     * fails on it, which is the test above.
     */
    public function testANewQualityImageFindingIsOnlyReportedOnAPullRequest(): void
    {
        $allowlist = $this->write('allowlist.txt', "CVE-2026-0001\tstdlib\n");
        $planted = [
            ['VulnerabilityID' => 'CVE-2026-0001', 'PkgName' => 'stdlib', 'Severity' => 'HIGH'],
            ['VulnerabilityID' => 'CVE-2026-9999', 'PkgName' => 'openssl', 'Severity' => 'CRITICAL'],
        ];

        [$exit, $output, $errors] = $this->scanCheck($allowlist, [['Target' => 'debian', 'Vulnerabilities' => $planted]], true);
        assertSame(0, $exit, 'a pull request failed on a finding it did not cause: ' . $errors);
        assertContains('NEW FINDINGS (report only on PRs): 1 finding(s) not on', $output);
        assertContains('CRITICAL CVE-2026-9999 in openssl', $output);

        [$exit, , $errors] = $this->scanCheck($allowlist, [['Target' => 'debian', 'Vulnerabilities' => $planted]]);
        assertSame(1, $exit, 'a push to main passed a new finding');

        $supplyChain = (string) file_get_contents(self::guardTool('supply-chain'));
        assertContains('if [ "${KNOSSOS_QUALITY_IMAGE_FINDINGS:-fail}" = "report" ]; then', $supplyChain);
        $workflow = (string) file_get_contents(self::repositoryRoot() . '/.github/workflows/quality.yml');
        assertContains("QUALITY_IMAGE_FINDINGS: \${{ github.event_name == 'pull_request' && 'report' || 'fail' }}", $workflow);
    }

    /** The committed allowlist is well formed, so the release lane reads it rather than refusing it. */
    public function testTheRepositoryAllowlistIsWellFormed(): void
    {
        [$exit, $output, $errors] = $this->runFixtureCommandOutput([PHP_BINARY, self::guardTool('trivy-allowlist.php'), $this->write('empty.json', '{"Results": []}')]);

        assertSame(0, $exit, $errors);
        assertContains('0 finding(s), all on the allowlist', $output);
    }

    /**
     * An npm advisory not on the allowlist fails, as `--audit-level=high` did;
     * a listed one passes until its date and fails after it; one below HIGH
     * or only depended on is not counted twice.
     */
    public function testAnNpmAdvisoryIsHeldToADatedAllowlist(): void
    {
        $allowlist = $this->write('npm-allowlist.txt', "# known\nGHSA-aaaa-bbbb-cccc\tpicomatch\t2026-12-18\nGHSA-gone-gone-gone\tminimist\t2026-12-18\n");
        $known = $this->npmReport('root.json', ['GHSA-aaaa-bbbb-cccc' => ['picomatch', 'high'], 'GHSA-low0-low0-low0' => ['glob', 'moderate']]);
        $worker = $this->npmReport('worker.json', ['GHSA-aaaa-bbbb-cccc' => ['picomatch', 'high']]);

        [$exit, $output, $errors] = $this->auditCheck($allowlist, '2026-11-18', $known, $worker);
        assertSame(0, $exit, $errors);
        assertContains('1 HIGH or CRITICAL advisory(ies), all accepted by the allowlist', $output);
        assertContains('no longer found, remove from the allowlist: GHSA-gone-gone-gone minimist', $output);

        [$exit, , $errors] = $this->auditCheck($allowlist, '2026-12-19', $known);
        assertSame(1, $exit, 'an advisory past its date passed');
        assertContains('accepted until 2026-12-18, which has passed', $errors);

        $planted = $this->npmReport('planted.json', ['GHSA-aaaa-bbbb-cccc' => ['picomatch', 'high'], 'GHSA-new0-new0-new0' => ['undici', 'critical']]);
        [$exit, , $errors] = $this->auditCheck($allowlist, '2026-11-18', $known, $planted);
        assertSame(1, $exit, 'a new CRITICAL advisory passed');
        assertContains('1 advisory(ies) not accepted by', $errors);
        assertContains('CRITICAL GHSA-new0-new0-new0 in undici', $errors);

        [$exit, , $errors] = $this->auditCheck($allowlist, '2026-11-18', $this->write('offline.json', '{"error": {"summary": "request to registry failed"}}'));
        assertSame(1, $exit, 'an audit that never reached the registry passed');
        assertContains('it has no vulnerabilities: request to registry failed', $errors);
    }

    /** The committed npm allowlist is well formed, so the release lane reads it rather than refusing it. */
    public function testTheRepositoryNpmAllowlistIsWellFormed(): void
    {
        [$exit, $output, $errors] = $this->runFixtureCommandOutput([PHP_BINARY, self::guardTool('npm-audit-allowlist.php'), $this->npmReport('empty.json', [])]);

        assertSame(0, $exit, $errors);
        assertContains('0 HIGH or CRITICAL advisory(ies)', $output);
        $quality = (string) file_get_contents(self::guardTool('quality'));
        assertContains('php tools/npm-audit-allowlist.php "$audit_dir/root.json" "$audit_dir/typescript-worker.json"', $quality);
    }

    private function write(string $name, string $contents): string
    {
        file_put_contents($this->directory . '/' . $name, $contents);

        return $this->directory . '/' . $name;
    }

    /**
     * @param array<mixed>|string $answer
     * @return array{0: int, 1: string, 2: string}
     */
    private function inspect(string $catalog, array|string $answer): array
    {
        $file = $this->write('answer.json', is_string($answer) ? $answer : json_encode($answer, JSON_THROW_ON_ERROR));

        return $this->runFixtureCommandOutput([PHP_BINARY, self::guardTool('inspector-tools-check.php'), '--catalog=' . $catalog, $file]);
    }

    /** @param array<string, bool> $snapshots scan id => whether it is the active one */
    private function snapshots(string $name, array $snapshots): string
    {
        $listing = [];
        foreach ($snapshots as $id => $active) {
            $listing[] = ['scan_id' => $id, 'active' => $active, 'retained' => !$active, 'status' => 'complete'];
        }

        return $this->write($name . '.json', json_encode(['project_id' => 'project_x', 'data' => ['snapshots' => $listing]], JSON_THROW_ON_ERROR));
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function restoreCheck(string $before, string $upgraded, string $restored): array
    {
        return $this->runFixtureCommandOutput([PHP_BINARY, self::guardTool('lifecycle-restore-check.php'), $before, $upgraded, $restored]);
    }

    /**
     * @param list<array<string, mixed>>|null $results
     * @return array{0: int, 1: string, 2: string}
     */
    private function scanCheck(string $allowlist, ?array $results, bool $reportOnly = false): array
    {
        $report = $this->write('report.json', json_encode($results === null ? ['SchemaVersion' => 2] : ['Results' => $results], JSON_THROW_ON_ERROR));

        return $this->runFixtureCommandOutput([PHP_BINARY, self::guardTool('trivy-allowlist.php'), '--allowlist=' . $allowlist, ...($reportOnly ? ['--report-only'] : []), $report]);
    }

    /**
     * An `npm audit --json` report: each advisory under the package it names,
     * plus a dependent that lists it by name only, as npm does.
     *
     * @param array<string, array{0: string, 1: string}> $advisories GHSA id => [package, severity]
     */
    private function npmReport(string $name, array $advisories): string
    {
        $vulnerabilities = [];
        foreach ($advisories as $id => [$package, $severity]) {
            $vulnerabilities[$package] = ['name' => $package, 'severity' => $severity, 'via' => [
                ['source' => 1, 'name' => $package, 'title' => 'planted', 'url' => 'https://github.com/advisories/' . $id, 'severity' => $severity],
            ]];
            $vulnerabilities['dependent-of-' . $package] = ['name' => 'dependent-of-' . $package, 'severity' => $severity, 'via' => [$package]];
        }

        return $this->write($name, json_encode(['auditReportVersion' => 2, 'vulnerabilities' => (object) $vulnerabilities], JSON_THROW_ON_ERROR));
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function auditCheck(string $allowlist, string $today, string ...$reports): array
    {
        return $this->runFixtureCommandOutput([PHP_BINARY, self::guardTool('npm-audit-allowlist.php'), '--allowlist=' . $allowlist, '--today=' . $today, ...$reports]);
    }
}

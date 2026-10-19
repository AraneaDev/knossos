<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit;

use PHPUnit\Framework\Attributes\Group;

/**
 * tools/quality says which checks it did not run.
 *
 * Outside the container a check whose tool was missing used to be skipped in
 * silence, so a run that never reached ShellCheck, cargo or the mod's
 * type-check ended as green as one that did. Now each prints `NOT run`, the
 * run ends with a list of them, and in the quality container, which is built
 * to hold every tool, a missing one is fatal. These tests run the skip helper
 * tools/quality uses with a PATH that holds none of the tools, in both modes.
 */
#[Group('quality')]
final class QualitySkipsTest extends KnossosTestCase
{
    /** The tools tools/quality probes for, with the check each one stands for. */
    private const TOOLS = [
        'cargo' => 'cargo fmt, clippy and test of the Rust worker',
        'shellcheck' => 'ShellCheck',
        'hadolint' => 'Hadolint',
        'pre-commit' => 'pre-commit validate-config',
        'cargo-audit' => 'cargo audit of the Rust worker',
        'claude' => 'claude plugin validate, claude plugin test and the staged register.tsx type-check',
    ];

    private string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/knossos-quality-skips-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/empty-path', 0o700, true);
    }

    protected function tearDown(): void
    {
        if ($this->directory !== '' && is_dir($this->directory)) {
            $this->runFixtureCommand(['rm', '-rf', $this->directory]);
        }
    }

    public function testMissingToolsAreListedLocallyAndFatalInTheContainer(): void
    {
        [$exit, $output, $errors] = $this->probe('0');

        assertSame(0, $exit, 'a local run without the tools must still finish: ' . $errors);
        assertContains('ran past every probe', $output);
        foreach (self::TOOLS as $tool => $check) {
            assertContains("NOT run: {$check} ({$tool} not found)", $errors);
            assertContains("  - {$check} ({$tool} not found)", $errors);
        }
        assertContains('NOT run: the external link check (KNOSSOS_EXTERNAL_LINKS=0)', $errors);
        assertContains(sprintf('tools/quality: %d check(s) NOT run:', count(self::TOOLS) + 2), $errors);
        assertContains('  - the staged register.tsx type-check against the Claude Code API (types not provided)', $errors);

        [$exit, $output, $errors] = $this->probe('1');

        assertSame(1, $exit, 'the container went on without a tool it must have');
        self::assertStringNotContainsString('ran past every probe', $output);
        assertContains('NOT run: cargo fmt, clippy and test of the Rust worker (cargo not found); the quality container must run it', $errors);
    }

    /** An opt-out is listed but never fatal, in the container too: the run was told to skip it. */
    public function testADeliberateOptOutIsListedButNotFatal(): void
    {
        $script = '. "$1"; quality_skips_begin "$2"; quality_opted_out "the external link check" "KNOSSOS_EXTERNAL_LINKS=0"; echo done; quality_skips_end';
        [$exit, $output, $errors] = $this->runFixtureCommandOutput([
            '/bin/sh', '-c', 'exec env -i PATH="$3" KNOSSOS_QUALITY_CONTAINER=1 /bin/sh -c "$0" sh "$@"', $script,
            self::guardTool('lib/quality-skips.sh'), $this->directory . '/skips', $this->directory . '/empty-path',
        ]);

        assertSame(0, $exit, $errors);
        assertContains('done', $output);
        assertContains('  - the external link check (KNOSSOS_EXTERNAL_LINKS=0)', $errors);
    }

    /** A failing run keeps its status through the summary the EXIT trap prints. */
    public function testTheSummaryKeepsTheRunsExitStatus(): void
    {
        $script = '. "$1"; quality_skips_begin "$2"; trap quality_skips_end EXIT; quality_has shellcheck ShellCheck || :; exit 3';
        [$exit, , $errors] = $this->runFixtureCommandOutput([
            '/bin/sh', '-c', 'exec env -i PATH="$3" /bin/sh -c "$0" sh "$@"', $script,
            self::guardTool('lib/quality-skips.sh'), $this->directory . '/skips', $this->directory . '/empty-path',
        ]);

        assertSame(3, $exit, $errors);
        assertContains('  - ShellCheck (shellcheck not found)', $errors);
    }

    /**
     * Every probe in tools/quality goes through the helper. A bare
     * `command -v` there would be a skip the summary never hears of.
     */
    public function testToolsQualityProbesOnlyThroughTheHelper(): void
    {
        $quality = (string) file_get_contents(self::guardTool('quality'));

        self::assertDoesNotMatchRegularExpression('/command -v/', $quality, 'tools/quality probes for a tool without tools/lib/quality-skips.sh');
        assertContains('. "$root/tools/lib/quality-skips.sh"', $quality);
        foreach (self::TOOLS as $tool => $check) {
            assertContains(sprintf("quality_has %s '%s'", $tool, $check), $quality);
        }
        assertContains("quality_not_run 'scanner conformance of the Rust worker'", $quality);
        assertContains("quality_not_run 'docker compose config'", $quality);
        assertContains("quality_not_run 'the staged register.tsx type-check against the Claude Code API'", $quality);
        assertContains("quality_not_run 'the lint list freshness check", $quality);
        assertContains("quality_opted_out 'the external link check'", $quality);
    }

    /** CI's PHPUnit hand-off is only meaningful where the coverage lane runs, so `fast` refuses it. */
    public function testThePhpunitHandOffIsRefusedOutsideTheFullProfile(): void
    {
        [$exit, $output, $errors] = $this->runFixtureCommandOutput([
            'env', 'KNOSSOS_PHPUNIT_IN_COVERAGE=1', 'sh', self::guardTool('quality'), 'fast', 'tests',
        ]);

        assertSame(64, $exit, $output . $errors);
        assertContains('KNOSSOS_PHPUNIT_IN_COVERAGE=1 hands PHPUnit to the coverage lane, which only the full profile runs', $errors);
    }

    /**
     * Runs each of tools/quality's probes with an empty PATH, then prints a
     * marker, then the summary. KNOSSOS_QUALITY_CONTAINER is `$mode`.
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function probe(string $mode): array
    {
        $script = '. "$1"; quality_skips_begin "$2"; trap quality_skips_end EXIT;';
        foreach (self::TOOLS as $tool => $check) {
            $script .= sprintf(' quality_has %s %s || :;', escapeshellarg($tool), escapeshellarg($check));
        }
        $script .= ' quality_not_run "the staged register.tsx type-check against the Claude Code API" "types not provided";';
        $script .= ' quality_opted_out "the external link check" "KNOSSOS_EXTERNAL_LINKS=0";';
        $script .= ' echo "ran past every probe"';

        return $this->runFixtureCommandOutput([
            '/bin/sh', '-c', 'exec env -i PATH="$3" KNOSSOS_QUALITY_CONTAINER="$4" /bin/sh -c "$0" sh "$1" "$2"', $script,
            self::guardTool('lib/quality-skips.sh'), $this->directory . '/skips', $this->directory . '/empty-path', $mode,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit;

use PHPUnit\Framework\Attributes\Group;

/**
 * The linters read the files git tracks, not a list someone wrote down.
 *
 * tools/quality, composer.json and package.json each named the files their
 * linter read, and every list had fallen behind the tree: ShellCheck never saw
 * three tools, `php -l` a dozen PHP tools, ESLint six modules. tools/lint-files.php
 * derives the lists from `git ls-files` into a committed file the image can
 * read, and refuses a committed file that differs from what git tracks. These
 * tests run it in a repository of their own, add a script the list does not
 * name, and hold the linter's arguments to the derived list.
 */
#[Group('quality')]
final class LintFilesTest extends KnossosTestCase
{
    /** The tracked files of the scratch repository, with the lists they belong to. */
    private const TREE = [
        'tools/check.sh' => ["#!/bin/sh\necho check\n", 'shell'],
        'tools/run' => ["#!/usr/bin/env bash\necho run\n", 'shell'],
        'tools/report.php' => ["<?php\necho 1;\n", 'php'],
        'tools/serve' => ["#!/usr/bin/env php\n<?php\necho 1;\n", 'php'],
        'tools/notes.mjs' => ["export const a = 1;\n", 'js'],
        'web/page.ts' => ["export const b = 2;\n", 'js'],
        'tests/Fixtures/scanner/Broken.php' => ["<?php class {\n", null],
        'tests/Fixtures/scanner/input.js' => ["let\n", null],
        'tests/Fixtures/workers/fake-worker.php' => ["<?php\necho 1;\n", 'php'],
        'README.md' => ["# Readme\n", null],
        'tools/data.json' => ["{}\n", null],
    ];

    private string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/knossos-lint-files-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/repo/tools', 0o700, true);
        foreach (self::TREE as $path => [$contents]) {
            $this->writeFile($path, $contents);
        }
        $this->git('init', '-q', '-b', 'main');
        $this->commitAll('the tree');
    }

    protected function tearDown(): void
    {
        if ($this->directory !== '' && is_dir($this->directory)) {
            $this->runFixtureCommand(['rm', '-rf', $this->directory]);
        }
    }

    /** Each list holds what git tracks of its kind, by extension or `#!` line, and no scanner fixture. */
    public function testTheListsAreDerivedFromWhatGitTracks(): void
    {
        [$exit, , $errors] = $this->lintFiles('--write');
        assertSame(0, $exit, $errors);

        foreach (['shell', 'php', 'js'] as $kind) {
            $expected = array_keys(array_filter(self::TREE, static fn(array $entry): bool => $entry[1] === $kind));
            sort($expected, SORT_STRING);
            [$exit, $output, $errors] = $this->lintFiles('--print=' . $kind);
            assertSame(0, $exit, $errors);
            assertSame(implode("\n", $expected) . "\n", $output, "the {$kind} list");
        }
    }

    /** The committed list passes its own check; a script git tracks that the list misses is refused by name. */
    public function testAFileGitTracksButTheListMissesIsRefused(): void
    {
        $this->lintFiles('--write');
        $this->commitAll('the list');
        [$exit, $output, $errors] = $this->lintFiles('--check');
        assertSame(0, $exit, $errors);
        assertContains('matches what git tracks', $output);

        $this->writeFile('tools/new-tool.sh', "#!/bin/sh\necho new\n");
        $this->commitAll('a new tool');

        [$exit, , $errors] = $this->lintFiles('--check');
        assertNotSame(0, $exit, 'a stale list passed the freshness check');
        assertContains('is not what git tracks', $errors);
        assertContains("missing  shell\ttools/new-tool.sh", $errors);
    }

    /** A list naming a file git no longer tracks is refused too. */
    public function testAListedFileGitNoLongerTracksIsRefused(): void
    {
        $this->lintFiles('--write');
        $this->git('rm', '-q', 'tools/run');
        $this->commitAll('drop a tool');

        [$exit, , $errors] = $this->lintFiles('--check');
        assertNotSame(0, $exit);
        assertContains("stale    shell\ttools/run", $errors);
    }

    /**
     * The linter is handed exactly the derived list, in one call or one call
     * per file, so a list that passes the check is the list that is linted.
     */
    public function testTheLinterIsHandedExactlyTheDerivedList(): void
    {
        $this->lintFiles('--write');
        $record = $this->directory . '/args';
        $recorder = $this->directory . '/record';
        file_put_contents($recorder, "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> \"\$RECORD\"\n");
        chmod($recorder, 0o755);

        [$exit, , $errors] = $this->runFixtureCommandOutput(['env', 'RECORD=' . $record, PHP_BINARY, $this->tool(), '--list=' . $this->list(), '--run=shell', '--', $recorder, '-x']);
        assertSame(0, $exit, $errors);
        assertSame("-x tools/check.sh tools/run\n", (string) file_get_contents($record));

        unlink($record);
        [$exit, , $errors] = $this->runFixtureCommandOutput(['env', 'RECORD=' . $record, PHP_BINARY, $this->tool(), '--list=' . $this->list(), '--each=php', '--', $recorder]);
        assertSame(0, $exit, $errors);
        assertSame("tests/Fixtures/workers/fake-worker.php\ntools/report.php\ntools/serve\n", (string) file_get_contents($record));
    }

    /** One failing file fails the run, and is named. */
    public function testAFailingFileFailsTheRun(): void
    {
        $this->lintFiles('--write');

        [$exit, , $errors] = $this->runFixtureCommandOutput([PHP_BINARY, $this->tool(), '--list=' . $this->list(), '--each=php', '--', PHP_BINARY, '-l']);
        assertSame(0, $exit, $errors);

        $this->writeFile('tools/report.php', "<?php\necho ;\n");
        [$exit, , $errors] = $this->runFixtureCommandOutput([PHP_BINARY, $this->tool(), '--list=' . $this->list(), '--each=php', '--', PHP_BINARY, '-l']);
        assertNotSame(0, $exit, 'a file with a syntax error passed');
        assertContains('1 of 3 php files failed: tools/report.php', $errors);
    }

    /** A linter given no files, or a list naming files the tree lacks, would pass without reading anything. */
    public function testAnEmptyOrUnbackedListIsRefused(): void
    {
        file_put_contents($this->list(), "# nothing\nphp\ttools/report.php\n");
        [$exit, , $errors] = $this->runFixtureCommandOutput([PHP_BINARY, $this->tool(), '--list=' . $this->list(), '--run=shell', '--', 'true']);
        assertNotSame(0, $exit);
        assertContains('no files of kind shell', $errors);

        file_put_contents($this->list(), "shell\ttools/gone.sh\n");
        [$exit, , $errors] = $this->runFixtureCommandOutput([PHP_BINARY, $this->tool(), '--list=' . $this->list(), '--run=shell', '--', 'true']);
        assertNotSame(0, $exit);
        assertContains('lists files this tree does not have: tools/gone.sh', $errors);
    }

    /**
     * Every linter of the static lane reads the derived list: none of them is
     * handed paths by hand any more, and ESLint fails on a warning.
     */
    public function testTheQualityGateLintsFromTheDerivedList(): void
    {
        $root = self::repositoryRoot();
        $quality = (string) file_get_contents($root . '/tools/quality');
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $package = json_decode((string) file_get_contents($root . '/package.json'), true, 512, JSON_THROW_ON_ERROR);

        assertContains('php tools/lint-files.php --check --repo=', $quality);
        assertContains('php tools/lint-files.php --run=shell -- shellcheck', $quality);
        assertContains('php tools/lint-files.php --run=php -- vendor/bin/phpstan analyse', $quality);
        assertSame('php tools/lint-files.php --each=php -- php -l', $composer['scripts']['lint']);
        assertSame('php tools/lint-files.php --run=js -- eslint --max-warnings 0', $package['scripts']['lint:js']);
        assertContains('tools/lint-files.txt', (string) file_get_contents($root . '/.php-cs-fixer.dist.php'));
        self::assertDoesNotMatchRegularExpression('/^\s*paths:/m', (string) file_get_contents($root . '/phpstan.neon'), 'phpstan.neon names its own paths again');
    }

    private function writeFile(string $path, string $contents): void
    {
        $file = $this->directory . '/repo/' . $path;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o700, true);
        }
        file_put_contents($file, $contents);
    }

    private function commitAll(string $message): void
    {
        $this->git('add', '-A');
        $this->git('commit', '-q', '--allow-empty', '-m', $message);
    }

    private function git(string ...$arguments): void
    {
        $this->runFixtureCommand([
            'env', 'GIT_CONFIG_NOSYSTEM=1', 'GIT_CONFIG_GLOBAL=/dev/null',
            'GIT_AUTHOR_NAME=lint', 'GIT_AUTHOR_EMAIL=lint@example.invalid',
            'GIT_COMMITTER_NAME=lint', 'GIT_COMMITTER_EMAIL=lint@example.invalid',
            'git', '-C', $this->directory . '/repo', ...$arguments,
        ]);
    }

    private function tool(): string
    {
        return self::guardTool('lint-files.php');
    }

    private function list(): string
    {
        return $this->directory . '/repo/tools/lint-files.txt';
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function lintFiles(string $mode): array
    {
        return $this->runFixtureCommandOutput([
            'env', 'GIT_CONFIG_NOSYSTEM=1', 'GIT_CONFIG_GLOBAL=/dev/null',
            PHP_BINARY, $this->tool(), $mode, '--repo=' . $this->directory . '/repo', '--list=' . $this->list(),
        ]);
    }
}

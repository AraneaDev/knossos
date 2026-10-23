<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery\Manifest;

use Knossos\Discovery\Manifest\ShellManifest;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * What a shell script runs by path, with the line naming it: the source files
 * it names, and the programs it starts through an interpreter or as a command.
 */
#[Group('discovery')]
final class ShellManifestTest extends KnossosTestCase
{
    public function testItListsWhatTheScriptLaunchesWithTheLineNamingIt(): void
    {
        $contents = implode("\n", [
            '#!/usr/bin/env bash',
            'set -euo pipefail',
            '# php bin/commented is only mentioned',
            'php bin/tool "$@"',
            'cd web && node src/build.mjs',
            './tools/check --fast | tee out.log',
            'FOO=1 exec bin/serve',
            'python3 -u scripts/report.py',
            'echo bin/not-run',
            'bash "$ROOT/bin/variable"',
            'php bin/tool again',
            'if php bin/guarded; then',
            'while php bin/looped; do',
            '! php bin/negated',
            'sudo php bin/privileged',
            '',
        ]);

        $launches = (new ShellManifest())->read('scripts/run.sh', '/unused', $contents)['launches'];

        $byPath = array_column($launches, 'line', 'path');
        self::assertSame(4, $byPath['bin/tool'] ?? null, 'The first line naming a path is its evidence.');
        self::assertSame(4, $byPath['scripts/bin/tool'] ?? null, 'A path is read from the script directory too.');
        self::assertSame(5, $byPath['web/src/build.mjs'] ?? null);
        self::assertSame(6, $byPath['tools/check'] ?? null);
        self::assertSame(7, $byPath['bin/serve'] ?? null);
        self::assertSame(8, $byPath['scripts/report.py'] ?? null);
        self::assertSame(12, $byPath['bin/guarded'] ?? null, 'A command after `if` is read.');
        self::assertSame(13, $byPath['bin/looped'] ?? null, 'A command after `while` is read.');
        self::assertSame(14, $byPath['bin/negated'] ?? null, 'A negated command is read.');
        self::assertSame(15, $byPath['bin/privileged'] ?? null, 'A command run through `sudo` is read.');
        self::assertArrayNotHasKey('bin/commented', $byPath);
        self::assertArrayNotHasKey('bin/not-run', $byPath);
        self::assertArrayNotHasKey('out.log', $byPath);
        foreach (array_keys($byPath) as $path) {
            self::assertStringNotContainsString('$', $path);
        }
    }

    /** `source` and `.` run the script they name in the current shell, and `xargs` runs the command after it. */
    public function testItReadsSourcedScriptsAndXargsCommandsAsLaunches(): void
    {
        $contents = implode("\n", [
            '#!/bin/sh',
            'source lib/common.sh',
            '. lib/env.sh',
            'find src -name "*.php" | xargs php bin/lint',
            'git ls-files | xargs -n1 -P4 bin/format',
            'cd . && echo done',
            '',
        ]);

        $byPath = array_column((new ShellManifest())->read('run.sh', '/unused', $contents)['launches'], 'line', 'path');

        self::assertSame(2, $byPath['lib/common.sh'] ?? null);
        self::assertSame(3, $byPath['lib/env.sh'] ?? null);
        self::assertSame(4, $byPath['bin/lint'] ?? null);
        self::assertSame(5, $byPath['bin/format'] ?? null);
    }

    /** A heredoc body is text handed to a command, so a path in it is not run by the script. */
    public function testItDoesNotReadHeredocBodiesAsCommands(): void
    {
        $contents = implode("\n", [
            '#!/usr/bin/env bash',
            'cat <<EOF > out.txt',
            'php bin/plain-body',
            'EOF',
            "cat <<-'END'",
            "\tphp bin/quoted-body",
            "\tEND",
            'cat <<"DONE" | php bin/after-pipe',
            './bin/double-quoted-body',
            'DONE',
            'read -r line <<< "bin/herestring"',
            'php bin/after-heredocs',
            '',
        ]);

        $byPath = array_column((new ShellManifest())->read('run.sh', '/unused', $contents)['launches'], 'line', 'path');

        self::assertArrayNotHasKey('bin/plain-body', $byPath);
        self::assertArrayNotHasKey('bin/quoted-body', $byPath);
        self::assertArrayNotHasKey('bin/double-quoted-body', $byPath);
        self::assertSame(8, $byPath['bin/after-pipe'] ?? null, 'The line opening a heredoc is still a command line.');
        self::assertSame(12, $byPath['bin/after-heredocs'] ?? null, 'Reading resumes after the terminator.');
    }

    /** A backslash quotes a delimiter as quote marks do, and the delimiter is the whole word, hyphens and all. */
    public function testABackslashedOrHyphenatedDelimiterClosesItsOwnHeredoc(): void
    {
        $contents = implode("\n", [
            'cat <<\\EOF',
            'php bin/escaped-body',
            'EOF',
            'cat <<END-OF-TEXT',
            'php bin/hyphen-body',
            'END-OF-TEXT',
            'php bin/after',
            '',
        ]);

        $byPath = self::launches($contents);

        self::assertArrayNotHasKey('bin/escaped-body', $byPath);
        self::assertArrayNotHasKey('bin/hyphen-body', $byPath);
        self::assertSame(7, $byPath['bin/after'] ?? null);
    }

    /**
     * A `<<` in arithmetic or inside quotes opens no heredoc, and one that
     * never closes is no heredoc either: none of them hides the lines after it.
     */
    #[DataProvider('notHeredocs')]
    public function testANonHeredocShiftHidesNothing(string $opener, string $closer): void
    {
        $byPath = self::launches(implode("\n", [$opener, 'php bin/next', $closer, 'php bin/last', '']));

        self::assertSame(2, $byPath['bin/next'] ?? null, $opener);
        self::assertSame(4, $byPath['bin/last'] ?? null, $opener);
    }

    /** @return iterable<string, array{0: string, 1: string}> the opener, and a line that would close it were it a heredoc */
    public static function notHeredocs(): iterable
    {
        yield 'arithmetic' => ['x=$(( 1 << n ))', 'n'];
        yield 'arithmetic command' => ['(( x = 1 << n ))', 'n'];
        yield 'double quotes' => ['echo "usage: cat <<EOF"', 'EOF'];
        yield 'single quotes' => ["echo 'cat <<EOF'", 'EOF'];
        yield 'never closed' => ['cat <<NEVER', 'NEVERMORE'];
    }

    /** `xargs` options that take a separate value, such as `-n 1`, are not the command it runs. */
    public function testXargsOptionValuesAreSkipped(): void
    {
        $byPath = self::launches(implode("\n", ['ls | xargs -n 1 php bin/one', 'ls | xargs -I {} -P 4 bin/each {}', '']));

        self::assertSame(1, $byPath['bin/one'] ?? null);
        self::assertSame(2, $byPath['bin/each'] ?? null);
    }

    /** @return array<string, int> each launched path's first line, for a script at the root */
    private static function launches(string $contents): array
    {
        return array_column((new ShellManifest())->read('run.sh', '/unused', $contents)['launches'], 'line', 'path');
    }
}

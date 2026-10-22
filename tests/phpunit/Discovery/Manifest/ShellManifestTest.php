<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery\Manifest;

use Knossos\Discovery\Manifest\ShellManifest;
use Knossos\Tests\Phpunit\KnossosTestCase;
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
        self::assertArrayNotHasKey('bin/commented', $byPath);
        self::assertArrayNotHasKey('bin/not-run', $byPath);
        self::assertArrayNotHasKey('out.log', $byPath);
        foreach (array_keys($byPath) as $path) {
            self::assertStringNotContainsString('$', $path);
        }
    }
}

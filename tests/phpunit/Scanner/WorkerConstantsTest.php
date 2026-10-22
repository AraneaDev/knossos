<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Discovery\IgnoreMatcher;
use Knossos\Scanner\Worker\WorkerLimits;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Constants every worker needs its own copy of, because the core reads their
 * effects as one protocol whichever worker produced them. They come from
 * schemas/worker-constants.json, and tools/worker-constants.php writes each
 * copy; a copy edited by hand is refused by name.
 */
#[Group('scan')]
final class WorkerConstantsTest extends KnossosTestCase
{
    /** The directories and files that hold a generated copy, besides the source itself. */
    private const COPIED = ['schemas', 'workers/php/src', 'workers/typescript/src', 'workers/python/bin', 'workers/rust/src', 'tools/scanner-conformance'];

    public function testEveryCopyInTheTreeIsCurrent(): void
    {
        [$exit, $output, $errors] = $this->runFixtureCommandOutput([PHP_BINARY, self::guardTool('worker-constants.php'), '--check']);

        self::assertSame(0, $exit, $errors);
        self::assertStringContainsString('Worker constants are current.', $output);
    }

    /** One interpreter dropped from one worker is a stale copy, named, and writing the copies restores it. */
    public function testACopyEditedByHandIsRefusedByName(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-worker-constants-' . bin2hex(random_bytes(6));
        try {
            foreach (self::COPIED as $path) {
                $from = self::repositoryRoot() . '/' . $path;
                if (is_dir($from)) {
                    $this->copyTree($from, $root . '/' . $path);
                } else {
                    @mkdir(dirname($root . '/' . $path), 0o700, true);
                    copy($from, $root . '/' . $path);
                }
            }
            $check = [PHP_BINARY, self::guardTool('worker-constants.php'), '--check', '--root=' . $root];
            [$exit, , $errors] = $this->runFixtureCommandOutput($check);
            self::assertSame(0, $exit, $errors);

            $launches = $root . '/workers/python/bin/knossos_python/launches.py';
            $source = (string) file_get_contents($launches);
            file_put_contents($launches, str_replace(', "zsh"', '', $source));
            self::assertNotSame($source, file_get_contents($launches));

            [$exit, , $errors] = $this->runFixtureCommandOutput($check);
            self::assertSame(1, $exit);
            self::assertStringContainsString('workers/python/bin/knossos_python/launches.py', $errors);
            self::assertStringNotContainsString('workers/typescript', $errors);

            [$written] = $this->runFixtureCommandOutput([PHP_BINARY, self::guardTool('worker-constants.php'), '--root=' . $root]);
            self::assertSame(0, $written);
            self::assertSame($source, file_get_contents($launches));
        } finally {
            if (is_dir($root)) {
                $this->removeTempTree($root);
            }
        }
    }

    /** A part has to fit in one line the core reads, with room for the frame around it. */
    public function testThePartSizeStaysWellBelowTheLineCap(): void
    {
        self::assertLessThan((new WorkerLimits())->maxLineBytes / 4, self::constants()['input_hashes_part_bytes']);
    }

    /** The core leaves out what a worker without the core's rules leaves out. */
    public function testTheCoreBuiltInRulesAreTheSource(): void
    {
        self::assertSame(self::constants()['built_in_exclusions'], (new IgnoreMatcher([]))->workerRules());
    }

    /** @return array<string, mixed> */
    private static function constants(): array
    {
        $constants = json_decode((string) file_get_contents(self::repositoryRoot() . '/schemas/worker-constants.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($constants);

        /** @var array<string, mixed> $constants */
        return $constants;
    }
}

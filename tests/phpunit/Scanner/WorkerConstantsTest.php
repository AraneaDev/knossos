<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Discovery\IgnoreMatcher;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Worker\{ProcessScannerClient, WorkerLimits};
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\ScanLimits;
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

    /**
     * A worker holds its copy as a literal, so one copied without the rest of
     * the installation, and without schemas/, still knows its interpreters.
     */
    public function testAPhpWorkerCopiedWithoutTheSourceStillLaunches(): void
    {
        $installation = sys_get_temp_dir() . '/knossos-stale-worker-only-' . bin2hex(random_bytes(6));
        try {
            $this->copyTree(self::repositoryRoot() . '/workers/php', $installation . '/workers/php');
            self::assertDirectoryDoesNotExist($installation . '/schemas');
            // An interpreter named by string, not PHP_BINARY: only the list decides it.
            mkdir($installation . '/project/src', 0o700, true);
            file_put_contents($installation . '/project/src/Launch.php', "<?php\nnamespace App;\nfinal class Launch\n{\n    public function go(): void\n    {\n        proc_open(['python3', __DIR__ . '/../bin/tool'], [], \$pipes);\n    }\n}\n");
            $client = new ProcessScannerClient([PHP_BINARY, $installation . '/workers/php/bin/worker'], new WorkerLimits(requestTimeoutMs: 10_000));
            $contributions = iterator_to_array($client->scan([
                'limits' => ScanLimits::DEFAULT,
                'root' => $installation . '/project',
                'files' => ['src/Launch.php'],
            ]));
            $client->shutdown();

            self::assertSame([], $contributions[0]->diagnostics);
            $launches = array_values(array_filter($contributions[0]->edges, static fn(EdgeFact $edge): bool => $edge->kind === 'launches'));
            self::assertCount(1, $launches);
            self::assertStringEndsWith('bin/tool', $launches[0]->targetReference);
        } finally {
            if (is_dir($installation)) {
                $this->removeTempTree($installation);
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

    /**
     * A rule missing from the source, or one holding the wrong type, refuses
     * the file: read as an empty list it would scan what it is there to skip.
     */
    public function testAMalformedBuiltInRuleIsRefused(): void
    {
        $read = new \ReflectionMethod(IgnoreMatcher::class, 'readBuiltIn');
        $path = (string) tempnam(sys_get_temp_dir(), 'knossos-rules-');
        $rules = self::constants()['built_in_exclusions'];
        self::assertIsArray($rules);
        try {
            $cases = [
                'segments is missing' => array_diff_key($rules, ['segments' => true]),
                'prefixes holds a number' => ['prefixes' => ['.knossos-', 7]] + $rules,
                'suffixes is not a list' => ['suffixes' => ['a' => '.min.js']] + $rules,
                'a sequence is not a pair' => ['sequences' => [['.vitepress']]] + $rules,
            ];
            foreach ($cases as $case => $broken) {
                file_put_contents($path, (string) json_encode(['built_in_exclusions' => $broken]));
                $error = captureThrows(static fn() => $read->invoke(null, $path), \RuntimeException::class);
                self::assertStringContainsString($path, $error->getMessage(), $case);
            }
            file_put_contents($path, (string) json_encode(['built_in_exclusions' => $rules]));
            self::assertSame(array_diff_key($rules, ['anchor_roots' => true, 'patterns' => true]), $read->invoke(null, $path));
        } finally {
            @unlink($path);
        }
    }

    /** A value of the wrong type in the source is the tool's own refusal, not a crash. */
    public function testAWrongTypedSourceValueIsRefusedByTheTool(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-worker-constants-' . bin2hex(random_bytes(6));
        try {
            mkdir($root . '/schemas', 0o700, true);
            $constants = self::constants();
            foreach (['input_hashes_part_bytes' => '256000', 'launch_interpreters' => ['php', 3], 'invalid_limits' => 1, 'built_in_exclusions' => ['segments' => []]] as $key => $value) {
                file_put_contents($root . '/schemas/worker-constants.json', (string) json_encode([$key => $value] + $constants));
                [$exit, , $errors] = $this->runFixtureCommandOutput([PHP_BINARY, self::guardTool('worker-constants.php'), '--check', '--root=' . $root]);

                self::assertSame(1, $exit, $key);
                self::assertStringContainsString('schemas/worker-constants.json: ' . $key . ' must be', $errors, $key);
                self::assertStringNotContainsString('TypeError', $errors, $key);
            }
        } finally {
            $this->removeTempTree($root);
        }
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

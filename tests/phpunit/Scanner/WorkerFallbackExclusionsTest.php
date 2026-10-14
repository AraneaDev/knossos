<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Discovery\IgnoreMatcher;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A worker asked to scan without the core's rules falls back on its own
 * built-in list. That list leaves out what the core leaves out, so a worker
 * run by hand reads the same tree a scan does.
 */
#[Group('typescript-scanner')]
final class WorkerFallbackExclusionsTest extends KnossosTestCase
{
    public function testThePythonWorkerFallsBackOnTheCoreBuiltInRules(): void
    {
        $script = <<<'PY'
            import json, sys
            sys.path.insert(0, sys.argv[1])
            from knossos_python.exclusions import Exclusions
            rules = Exclusions()
            print(json.dumps({
                "segments": sorted(rules.segments),
                "anchored_segments": sorted(rules.anchored_segments),
                "prefixes": list(rules.prefixes),
                "sequences": [list(pair) for pair in rules.sequences],
                "suffixes": list(rules.suffixes),
                "path_prefixes": rules.path_prefixes,
            }))
            PY;

        $this->assertSameRules($this->rulesFrom(
            ['python3', '-c', $script, self::repositoryRoot() . '/workers/python/bin'],
        ));
    }

    public function testTheTypeScriptWorkerFallsBackOnTheCoreBuiltInRules(): void
    {
        $module = self::repositoryRoot() . '/workers/typescript/src/exclusions.js';
        $script = 'const { BUILT_IN_EXCLUSIONS } = await import(process.argv[1]);'
            . ' console.log(JSON.stringify(BUILT_IN_EXCLUSIONS));';

        $this->assertSameRules($this->rulesFrom(['node', '--input-type=module', '-e', $script, $module]));
    }

    /** @param array<string, mixed> $worker */
    private function assertSameRules(array $worker): void
    {
        $core = (new IgnoreMatcher([]))->workerRules();
        foreach (['segments', 'anchored_segments', 'prefixes', 'sequences', 'suffixes', 'path_prefixes'] as $field) {
            self::assertArrayHasKey($field, $worker);
            self::assertIsArray($worker[$field]);
            self::assertEqualsCanonicalizing($core[$field], $worker[$field], $field);
        }
    }

    /**
     * @param list<string> $command
     * @return array<string, mixed>
     */
    private function rulesFrom(array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}

<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Constants every worker keeps its own copy of, which must agree: the core
 * reads their effects as one protocol, whichever worker produced them.
 */
#[Group('scan')]
final class WorkerConstantAgreementTest extends KnossosTestCase
{
    /**
     * Each constant: per worker source, the pattern whose first group holds its value.
     *
     * @return array<string, array{0: array<string, string>, 1: bool}> the sources, and whether the value is a list of quoted names
     */
    public static function constants(): array
    {
        return [
            // A launches edge means the same in every language.
            'launch interpreters' => [[
                'workers/php/src/FactCollector.php' => '/INTERPRETERS\s*=\s*\[(.*?)\];/s',
                'workers/typescript/src/launch-facts.js' => '/INTERPRETERS\s*=\s*new Set\(\[(.*?)\]\)/s',
                'workers/python/bin/knossos_python/launches.py' => '/^INTERPRETERS\s*=\s*\{(.*?)\}/ms',
                'workers/rust/src/visit/launches.rs' => '/INTERPRETERS:\s*\[&str;\s*\d+\]\s*=\s*\[(.*?)\];/s',
            ], true],
            // The core reassembles input_hashes parts as one stream.
            'input_hashes part size' => [[
                'workers/php/src/WorkerServer.php' => '/INPUT_HASHES_PART_BYTES\s*=\s*([\d_]+)/',
                'workers/typescript/src/input-hashes-parts.js' => '/INPUT_HASHES_PART_BYTES\s*=\s*([\d_]+)/',
                'workers/python/bin/worker.py' => '/INPUT_HASHES_PART_BYTES\s*=\s*([\d_]+)/',
                'workers/rust/src/server.rs' => '/INPUT_HASHES_PART_BYTES:\s*usize\s*=\s*([\d_]+)/',
            ], false],
            // A link chain one worker follows, every worker that walks links follows (ELOOP's 40).
            'symlink hops' => [[
                'workers/typescript/src/project-paths.js' => '/MAX_SYMLINK_HOPS\s*=\s*([\d_]+)/',
                'workers/python/bin/knossos_python/safe_io.py' => '/^MAX_SYMLINK_HOPS\s*=\s*([\d_]+)/m',
            ], false],
            // Every worker refuses missing or malformed limits in the same words.
            'invalid limits refusal' => [[
                'workers/php/src/WorkerServer.php' => "/INVALID_LIMITS = '([^']+)'/",
                'workers/typescript/src/request-validation.js' => '/INVALID_LIMITS =\s*"([^"]+)"/',
                'workers/python/bin/worker.py' => '/^INVALID_LIMITS = "([^"]+)"/m',
                'workers/rust/src/params.rs' => '/INVALID_LIMITS: &str =\s*"([^"]+)"/',
                'tools/scanner-conformance' => "/SCAN_LIMITS_INVALID = '([^']+)'/",
            ], false],
        ];
    }

    /** @param array<string, string> $sources */
    #[DataProvider('constants')]
    public function testEveryWorkerDeclaresTheSameValue(array $sources, bool $names): void
    {
        $values = [];
        foreach ($sources as $path => $pattern) {
            self::assertSame(1, preg_match($pattern, (string) file_get_contents(self::repositoryRoot() . '/' . $path), $match), $path . ' does not declare it.');
            if ($names) {
                preg_match_all('/["\']([^"\'\s]+)["\']/', $match[1], $quoted);
                $list = $quoted[1];
                sort($list);
                self::assertNotSame([], $list, $path);
                $values[$path] = implode(',', $list);
            } else {
                $values[$path] = str_replace('_', '', $match[1]) === (string) (int) str_replace('_', '', $match[1])
                    ? str_replace('_', '', $match[1])
                    : $match[1];
            }
        }

        self::assertCount(1, array_unique($values), 'Values differ: ' . json_encode($values));
    }
}

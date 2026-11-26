<?php

declare(strict_types=1);

/*
 *   generate-on-request-corpus.php OUTPUT FILES_PER_LANGUAGE [--tests] [--fixtures=N]
 *
 * The corpus of an on-request benchmark scenario: the size profile
 * `generate-benchmark-corpus.php` writes for FILES_PER_LANGUAGE, byte for byte,
 * and on top of it what the on-request tools read.
 *
 * `--tests` adds one test file per service and language. Each doubles the
 * service's dependency (`vi.spyOn`, PHPUnit's `createMock`, `patch.object`
 * with autospec) and asserts on the real service, as a healthy test does;
 * the first and every fifth TypeScript and Python one instead doubles its own
 * service and asserts only on the double, so `OVER_MOCKING` has findings to
 * report. A root `pyproject.toml` makes `python/` the Python source root, so
 * the tests' `app.*` imports resolve from the scanned root.
 *
 * `--fixtures=N` adds one DTO per language (a PHP class with promoted
 * properties, a TypeScript interface and a Python dataclass) and N fixture
 * files under `fixtures/`, dealt to the three DTOs in turn, each holding three
 * records under a key that names its type. Every fourth is YAML, the others
 * JSON, and every tenth misses a required field, so `FIXTURE_DRIFT` has
 * findings to report.
 *
 * Prints the counts, the files `--tests` adds, and a SHA-256 digest of the
 * whole corpus as JSON.
 */

require_once __DIR__ . '/lib/cli-arguments.php';
$argv = cliArguments();
$usage = 'usage: generate-on-request-corpus.php OUTPUT FILES_PER_LANGUAGE [--tests] [--fixtures=N]';
$positional = array_values(array_filter(array_slice($argv, 1), static fn(string $argument): bool => !str_starts_with($argument, '--')));
$withTests = false;
$fixtures = 0;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--tests') {
        $withTests = true;
    } elseif (preg_match('/^--fixtures=([1-9][0-9]*)$/', $argument, $match) === 1 && (int) $match[1] <= 1000) {
        $fixtures = (int) $match[1];
    } elseif (str_starts_with($argument, '--')) {
        fwrite(STDERR, $usage . "\n");
        exit(2);
    }
}
if (count($positional) !== 2 || preg_match('/^[1-9][0-9]*$/', $positional[1]) !== 1) {
    fwrite(STDERR, $usage . "\n");
    exit(2);
}
[$root, $count] = [rtrim($positional[0], '/'), (int) $positional[1]];

$base = proc_open([PHP_BINARY, __DIR__ . '/generate-benchmark-corpus.php', $root, (string) $count], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($base)) {
    fwrite(STDERR, "Unable to run generate-benchmark-corpus.php.\n");
    exit(2);
}
$baseOut = (string) stream_get_contents($pipes[1]);
$baseErr = (string) stream_get_contents($pipes[2]);
if (proc_close($base) !== 0) {
    fwrite(STDERR, $baseErr);
    exit(2);
}
$profile = json_decode($baseOut, true, 512, JSON_THROW_ON_ERROR);

/** Write one corpus file, making its directory. */
function put(string $root, string $path, string $contents): void
{
    $directory = dirname($root . '/' . $path);
    if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create on-request corpus directory.');
    }
    file_put_contents($root . '/' . $path, $contents);
}

$testFiles = [];
if ($withTests) {
    put($root, 'typescript/tsconfig.json', "{\n  \"compilerOptions\": {\"strict\": true},\n  \"include\": [\"src/**/*.ts\", \"tests/**/*.ts\"]\n}\n");
    put($root, 'pyproject.toml', "[project]\nname = \"knossos-benchmark-on-request\"\nversion = \"0.0.0\"\n\n[tool.setuptools.packages.find]\nwhere = [\"python\"]\n");
    for ($index = 0; $index < $count; ++$index) {
        $previous = max(0, $index - 1);
        $healthy = $index > 0 && $index % 5 !== 4;
        $testFiles[] = $path = sprintf('typescript/tests/service-%04d.test.ts', $index);
        put($root, $path, $healthy ? sprintf(<<<'TS'
            import { describe, expect, it, vi } from 'vitest';
            import { Service%2$04d } from '../src/service-%2$04d.js';
            import { Service%1$04d } from '../src/service-%1$04d.js';

            describe('service %1$d', () => {
              it('builds its dependency', () => {
                const dependency = new Service%2$04d();
                const spy = vi.spyOn(dependency, 'dependency');
                expect(new Service%1$04d().dependency()).toBeInstanceOf(Service%2$04d);
                expect(spy).not.toHaveBeenCalled();
              });
            });

            TS, $index, $previous) : sprintf(<<<'TS'
            import { describe, expect, it, vi } from 'vitest';
            import { Service%1$04d } from '../src/service-%1$04d.js';

            describe('service %1$d', () => {
              it('is called once', () => {
                const service = new Service%1$04d();
                const spy = vi.spyOn(service, 'dependency');
                service.dependency();
                expect(spy).toHaveBeenCalledTimes(1);
              });
            });

            TS, $index));
        $testFiles[] = $path = sprintf('php/tests/Service%04dTest.php', $index);
        put($root, $path, sprintf(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Benchmark\Tests;

            use Benchmark\Service%1$04d;
            use Benchmark\Service%2$04d;
            use PHPUnit\Framework\TestCase;

            final class Service%1$04dTest extends TestCase
            {
                public function testItBuildsItsDependency(): void
                {
                    $dependency = $this->createMock(Service%2$04d::class);
                    $dependency->method('dependency');
                    self::assertInstanceOf(Service%2$04d::class, (new Service%1$04d())->dependency());
                }
            }

            PHP, $index, $previous));
        $testFiles[] = $path = sprintf('python/tests/test_service_%04d.py', $index);
        put($root, $path, $healthy ? sprintf(<<<'PY'
            from unittest.mock import patch

            from app.service_%1$04d import Service%1$04d
            from app.service_%2$04d import Service%2$04d


            def test_service_%1$04d_builds_its_dependency() -> None:
                with patch.object(Service%2$04d, "dependency", autospec=True) as dependency:
                    assert isinstance(Service%1$04d().dependency(), Service%2$04d)
                    dependency.assert_not_called()

            PY, $index, $previous) : sprintf(<<<'PY'
            from unittest.mock import patch

            from app.service_%1$04d import Service%1$04d


            def test_service_%1$04d_is_called_once() -> None:
                with patch.object(Service%1$04d, "dependency", autospec=True) as dependency:
                    Service%1$04d().dependency()
                    dependency.assert_called_once()

            PY, $index));
    }
}

if ($fixtures > 0) {
    put($root, 'php/src/Invoice.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Benchmark;\n\nfinal class Invoice\n{\n    public function __construct(public int \$id, public string \$number, public bool \$paid) {}\n}\n");
    put($root, 'typescript/src/shipment.ts', "export interface Shipment {\n  id: number;\n  carrier: string;\n  express: boolean;\n}\n");
    put($root, 'python/app/parcel.py', "from dataclasses import dataclass\n\n\n@dataclass\nclass Parcel:\n    id: int\n    label: str\n    fragile: bool\n");
    $types = [
        ['invoices', ['id' => 1, 'number' => 'INV-1', 'paid' => true], 'paid'],
        ['shipments', ['id' => 1, 'carrier' => 'post', 'express' => false], 'express'],
        ['parcels', ['id' => 1, 'label' => 'box', 'fragile' => true], 'fragile'],
    ];
    for ($index = 0; $index < $fixtures; ++$index) {
        [$key, $record, $dropped] = $types[$index % 3];
        $records = [];
        for ($row = 0; $row < 3; ++$row) {
            $one = ['id' => $index * 3 + $row] + $record;
            if ($index % 10 === 9 && $row === 0) {
                unset($one[$dropped]);
            }
            $records[] = $one;
        }
        if ($index % 4 === 3) {
            $yaml = $key . ":\n";
            foreach ($records as $one) {
                $lines = [];
                foreach ($one as $field => $value) {
                    $lines[] = sprintf('%s: %s', $field, is_bool($value) ? ($value ? 'true' : 'false') : (is_int($value) ? (string) $value : json_encode($value)));
                }
                $yaml .= '  - ' . implode("\n    ", $lines) . "\n";
            }
            put($root, sprintf('fixtures/%s/%s-%04d.yaml', $key, $key, $index), $yaml);
        } else {
            put($root, sprintf('fixtures/%s/%s-%04d.json', $key, $key, $index), json_encode([$key => $records], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        }
    }
}

$files = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $entry) {
    if ($entry->isFile()) {
        $files[str_replace($root . '/', '', $entry->getPathname())] = hash_file('sha256', $entry->getPathname());
    }
}
ksort($files, SORT_STRING);

fwrite(STDOUT, json_encode([
    'files_per_language' => $count,
    'generated_source_files' => $profile['generated_source_files'],
    'test_files' => $testFiles,
    'fixture_files' => $fixtures,
    'corpus_sha256' => hash('sha256', json_encode($files, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

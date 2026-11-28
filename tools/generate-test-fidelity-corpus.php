<?php

declare(strict_types=1);

/*
 *   generate-test-fidelity-corpus.php OUTPUT [typescript|php]
 *
 * The corpus of a test-fidelity benchmark scenario: one production class and
 * five test files that hold twenty doubles of it, four each, so editing the
 * production file makes a scan judge every double again. TypeScript
 * (`test_fidelity_rescan_one_file`, the default) reads all five test files
 * again; PHP (`test_fidelity_rescan_one_file_php`) reuses them and judges
 * their doubles against the changed class. Prints the production file's
 * path, relative to OUTPUT, the files each rescan parses, the edit to append
 * (a format taking the round), and a SHA-256 digest of the corpus as JSON.
 */

require_once __DIR__ . '/lib/cli-arguments.php';
// $argv is only a global where register_argc_argv is on (see tools/lib/cli-arguments.php).
$argv = cliArguments();
$language = $argv[2] ?? 'typescript';
if (count($argv) < 2 || count($argv) > 3 || !in_array($language, ['typescript', 'php'], true)) {
    fwrite(STDERR, "usage: generate-test-fidelity-corpus.php OUTPUT [typescript|php]\n");
    exit(2);
}
$root = rtrim($argv[1], '/');
if (file_exists($root) && (!is_dir($root) || count(scandir($root) ?: []) > 2)) {
    fwrite(STDERR, "OUTPUT must be absent or an empty directory.\n");
    exit(2);
}

/**
 * The TypeScript corpus, by path.
 *
 * @return array<string, string>
 */
function typescriptCorpus(): array
{
    $files = [
        'typescript/package.json' => "{\n  \"name\": \"knossos-benchmark-test-fidelity\",\n  \"private\": true\n}\n",
        'typescript/tsconfig.json' => "{\n  \"compilerOptions\": {\"strict\": true},\n  \"include\": [\"src/**/*.ts\", \"tests/**/*.ts\"]\n}\n",
        'typescript/src/ledger.ts' => <<<'TS'
            export class Ledger {
              total(): number {
                return 1;
              }

              label(): string {
                return 'ledger';
              }

              async load(id: string): Promise<number> {
                return id.length;
              }

              reset(): void {}
            }

            TS,
    ];
    for ($index = 0; $index < 5; ++$index) {
        $files[sprintf('typescript/tests/ledger-%d.test.ts', $index)] = sprintf(<<<'TS'
            import { describe, expect, it, vi } from 'vitest';
            import { Ledger } from '../src/ledger.js';

            describe('ledger %1$d', () => {
              it('stubs every member', async () => {
                const ledger = new Ledger();
                vi.spyOn(ledger, 'total').mockReturnValue(%1$d);
                vi.spyOn(ledger, 'label').mockReturnValue('ledger %1$d');
                vi.spyOn(ledger, 'load').mockResolvedValue(%1$d);
                vi.spyOn(ledger, 'reset').mockImplementation(() => undefined);
                expect(ledger.total()).toBe(%1$d);
                expect(ledger.label()).toBe('ledger %1$d');
                expect(await ledger.load('id')).toBe(%1$d);
                ledger.reset();
              });
            });

            TS, $index);
    }

    return $files;
}

/**
 * The PHP corpus, by path: PHPUnit and Mockery doubles of one class, each
 * test also running the class itself, as a healthy suite does.
 *
 * @return array<string, string>
 */
function phpCorpus(): array
{
    $files = [
        'php/composer.json' => "{\n  \"name\": \"knossos/benchmark-test-fidelity\",\n  \"autoload\": {\"psr-4\": {\"App\\\\\": \"src/\"}},\n  \"autoload-dev\": {\"psr-4\": {\"Tests\\\\\": \"tests/\"}}\n}\n",
        'php/src/Ledger.php' => <<<'PHP'
            <?php

            namespace App;

            class Ledger
            {
                public function total(): int
                {
                    return 1;
                }

                public function label(): string
                {
                    return 'ledger';
                }

                public function load(string $id): int
                {
                    return strlen($id);
                }

                public function reset(): void
                {
                }
            }

            PHP,
    ];
    for ($index = 0; $index < 5; ++$index) {
        $files[sprintf('php/tests/Ledger%dTest.php', $index)] = sprintf(<<<'PHP'
            <?php

            namespace Tests;

            use App\Ledger;
            use Mockery;
            use PHPUnit\Framework\TestCase;

            final class Ledger%1$dTest extends TestCase
            {
                public function testStubsEveryMember(): void
                {
                    $total = $this->createMock(Ledger::class);
                    $total->method('total')->willReturn(%1$d);
                    $label = $this->createStub(Ledger::class);
                    $label->method('label')->willReturn('ledger %1$d');
                    $load = Mockery::mock(Ledger::class);
                    $load->shouldReceive('load')->with('id')->andReturn(%1$d);
                    $reset = $this->createMock(Ledger::class);
                    $reset->expects($this->once())->method('reset');
                    $ledger = new Ledger();
                    self::assertSame(1, $ledger->total());
                    self::assertSame(%1$d, $total->total() + $load->load('id') - %1$d);
                    self::assertSame('ledger %1$d', $label->label());
                    $reset->reset();
                }
            }

            PHP, $index);
    }

    return $files;
}

$corpora = [
    'typescript' => ['files' => typescriptCorpus(), 'production' => 'typescript/src/ledger.ts', 'parsed' => 6, 'edit' => "\nexport const benchmarkChange%d = true;\n"],
    'php' => ['files' => phpCorpus(), 'production' => 'php/src/Ledger.php', 'parsed' => 1, 'edit' => "\nfunction benchmarkChange%d(): bool\n{\n    return true;\n}\n"],
];
$corpus = $corpora[$language];
$files = $corpus['files'];
foreach (array_keys($files) as $path) {
    $directory = dirname($root . '/' . $path);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create test-fidelity corpus directory.');
    }
}

$hashes = [];
foreach ($files as $path => $contents) {
    file_put_contents($root . '/' . $path, $contents);
    $hashes[$path] = hash('sha256', $contents);
}
ksort($hashes, SORT_STRING);

fwrite(STDOUT, json_encode([
    'production_file' => $corpus['production'],
    'parsed_files' => $corpus['parsed'],
    'edit' => $corpus['edit'],
    'test_files' => 5,
    'doubles' => 20,
    'corpus_sha256' => hash('sha256', json_encode($hashes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
], JSON_THROW_ON_ERROR) . "\n");

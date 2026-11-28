<?php

declare(strict_types=1);

/*
 *   generate-test-fidelity-corpus.php OUTPUT [typescript|php|python|rust]
 *
 * The corpus of a test-fidelity benchmark scenario: one production class and
 * five test files that hold twenty doubles of it, four each, so editing the
 * production file makes a scan judge every double again. TypeScript
 * (`test_fidelity_rescan_one_file`, the default) reads all five test files
 * again; PHP (`test_fidelity_rescan_one_file_php`) reuses them and judges
 * their doubles against the changed class. Python
 * (`test_fidelity_rescan_one_file_python`) reads all five again too, as each
 * test file reads the class's module to resolve what it patches. Rust
 * (`test_fidelity_rescan_one_file_rust`) reads all five again as well: each
 * test file imports its mock from the trait's module, and reads every file
 * of its package besides (G5-05). Prints the production file's path,
 * relative to OUTPUT, the files each rescan parses, the edit to append (a
 * format taking the round), and a SHA-256 digest of the corpus as JSON.
 */

require_once __DIR__ . '/lib/cli-arguments.php';
// $argv is only a global where register_argc_argv is on (see tools/lib/cli-arguments.php).
$argv = cliArguments();
$language = $argv[2] ?? 'typescript';
if (count($argv) < 2 || count($argv) > 3 || !in_array($language, ['typescript', 'php', 'python', 'rust'], true)) {
    fwrite(STDERR, "usage: generate-test-fidelity-corpus.php OUTPUT [typescript|php|python|rust]\n");
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

/**
 * The Python corpus, by path: spec'd `unittest.mock` doubles of one class and
 * patches of its methods, each test also running the class itself, as a
 * healthy suite does. It sits at OUTPUT itself, not under `python/`: the
 * Python worker reads source roots from the scan root's `pyproject.toml`
 * and `src/`, so a nested project would name the class `python.src.ledger`.
 *
 * @return array<string, string>
 */
function pythonCorpus(): array
{
    $files = [
        'pyproject.toml' => "[project]\nname = \"knossos-benchmark-test-fidelity\"\nversion = \"0.0.0\"\n",
        'src/ledger.py' => <<<'PY'
            class Ledger:
                def total(self) -> int:
                    return 1

                def label(self) -> str:
                    return "ledger"

                def load(self, id: str) -> int:
                    return len(id)

                def reset(self) -> None:
                    pass

            PY,
    ];
    for ($index = 0; $index < 5; ++$index) {
        $files[sprintf('tests/test_ledger_%d.py', $index)] = sprintf(<<<'PY'
            from unittest.mock import Mock, patch

            from ledger import Ledger


            def test_stubs_every_member_%1$d():
                total = Mock(spec=Ledger)
                total.total.return_value = %1$d
                label = Mock(spec=Ledger)
                label.label.return_value = "ledger %1$d"
                with patch.object(Ledger, "load", return_value=%1$d) as load, patch("ledger.Ledger.reset") as reset:
                    ledger = Ledger()
                    assert ledger.total() == 1
                    ledger.load("id")
                    ledger.reset()
                    load.assert_called_once_with("id")
                    reset.assert_called_once_with()
                assert total.total.call_count + label.label.call_count == 0

            PY, $index);
    }

    return $files;
}

/**
 * The Rust corpus, by path: `MockLedger` doubles that mockall's
 * `#[automock]` generates for one trait, each test handing them to code that
 * takes a `Ledger` and asserting on `Book`, the trait's production
 * implementation, as a healthy suite does. Like the Python one, it sits at
 * OUTPUT itself, where the Rust worker finds its Cargo manifest.
 *
 * @return array<string, string>
 */
function rustCorpus(): array
{
    $files = [
        'Cargo.toml' => "[package]\nname = \"knossos-benchmark-test-fidelity\"\nversion = \"0.0.0\"\nedition = \"2021\"\n\n[dependencies]\nmockall = \"0.13\"\n",
        'src/lib.rs' => "pub mod ledger;\n",
        'src/ledger.rs' => <<<'RS'
            use mockall::automock;

            #[automock]
            pub trait Ledger {
                fn total(&self) -> u32;
                fn label(&self) -> String;
                fn load(&self, id: &str) -> u32;
                fn reset(&self);
            }

            pub struct Book;

            impl Ledger for Book {
                fn total(&self) -> u32 {
                    1
                }

                fn label(&self) -> String {
                    "ledger".to_owned()
                }

                fn load(&self, id: &str) -> u32 {
                    id.len() as u32
                }

                fn reset(&self) {}
            }

            RS,
    ];
    for ($index = 0; $index < 5; ++$index) {
        $files[sprintf('tests/ledger_%d.rs', $index)] = sprintf(<<<'RS'
            use knossos_benchmark_test_fidelity::ledger::{Book, Ledger, MockLedger};

            /// What a caller reads from a ledger, beside the book's own total.
            fn audit(ledger: &dyn Ledger, book: &Book) -> u32 {
                ledger.total() + ledger.load("id") + book.total()
            }

            #[test]
            fn stubs_every_member_%1$d() {
                let mut total = MockLedger::new();
                total.expect_total().returning(|| %1$d);
                total.expect_load().returning(|_| 0);
                let mut label = MockLedger::new();
                label.expect_label().returning(|| "ledger %1$d".to_owned());
                let mut load = MockLedger::new();
                load.expect_total().returning(|| 0);
                load.expect_load().returning(|id| id.len() as u32);
                let mut reset = MockLedger::new();
                reset.expect_reset().returning(|| ());
                let book = Book;
                audit(&total, &book);
                audit(&load, &book);
                label.label();
                reset.reset();
                assert_eq!(book.total(), 1);
                assert_eq!(book.load("id"), 2);
                assert_eq!(book.label(), "ledger");
            }

            RS, $index);
    }

    return $files;
}

$corpora = [
    'typescript' => ['files' => typescriptCorpus(), 'production' => 'typescript/src/ledger.ts', 'parsed' => 6, 'edit' => "\nexport const benchmarkChange%d = true;\n"],
    'php' => ['files' => phpCorpus(), 'production' => 'php/src/Ledger.php', 'parsed' => 1, 'edit' => "\nfunction benchmarkChange%d(): bool\n{\n    return true;\n}\n"],
    'python' => ['files' => pythonCorpus(), 'production' => 'src/ledger.py', 'parsed' => 6, 'edit' => "\n\ndef benchmark_change_%d():\n    return True\n"],
    'rust' => ['files' => rustCorpus(), 'production' => 'src/ledger.rs', 'parsed' => 6, 'edit' => "\npub fn benchmark_change_%d() -> bool { true }\n"],
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

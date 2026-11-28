<?php

declare(strict_types=1);

/*
 *   generate-test-fidelity-corpus.php OUTPUT
 *
 * The corpus of the `test_fidelity_rescan_one_file` benchmark scenario: one
 * TypeScript production file and five test files that hold twenty doubles of
 * it, four each, so editing the production file makes a scan read all five
 * again and judge every double. Prints the production file's path, relative
 * to OUTPUT, and a SHA-256 digest of the corpus as JSON.
 */

require_once __DIR__ . '/lib/cli-arguments.php';
// $argv is only a global where register_argc_argv is on (see tools/lib/cli-arguments.php).
$argv = cliArguments();
if (count($argv) !== 2) {
    fwrite(STDERR, "usage: generate-test-fidelity-corpus.php OUTPUT\n");
    exit(2);
}
$root = rtrim($argv[1], '/');
if (file_exists($root) && (!is_dir($root) || count(scandir($root) ?: []) > 2)) {
    fwrite(STDERR, "OUTPUT must be absent or an empty directory.\n");
    exit(2);
}
foreach ([$root . '/typescript/src', $root . '/typescript/tests'] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create test-fidelity corpus directory.');
    }
}

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

$hashes = [];
foreach ($files as $path => $contents) {
    file_put_contents($root . '/' . $path, $contents);
    $hashes[$path] = hash('sha256', $contents);
}
ksort($hashes, SORT_STRING);

fwrite(STDOUT, json_encode([
    'production_file' => 'typescript/src/ledger.ts',
    'test_files' => 5,
    'doubles' => 20,
    'corpus_sha256' => hash('sha256', json_encode($hashes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
], JSON_THROW_ON_ERROR) . "\n");

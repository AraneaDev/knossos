<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Synthesis;

use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * Every golden template compiles where it is meant to (Review Focus 3: no
 * template that silently fits the wrong shape; one that does not even
 * compile is caught here first). Knossos's own suite lints them on scratch
 * copies; the tool itself never compiles or runs one.
 *
 * - TypeScript: each vitest template, with its target's types imported,
 *   type-checks with the worker's own `tsc --noEmit` against a copy of its
 *   fixture project under `strict`, vitest's types from the worker's
 *   `node_modules`. The jest templates are golden-compared here (`tools/compile-mock-goldens`
 *   type-checks them against jest's own types, in CI): jest's
 *   types would be a new dependency (preflight F17, G6-06).
 * - PHP: each template, as the body of a test method, passes `php -l`.
 * - Python: each passes `python3 -I -m py_compile`.
 * - Rust: golden-compared here, since mockall is not available offline;
 *   `tools/compile-mock-goldens` builds them against mockall, in CI (G6-03).
 */
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class TemplatesLintTest extends KnossosTestCase
{
    /**
     * The golden templates of one language prefix (`ts-`, `php-`, `py-`), by case name.
     *
     * @return array<string, string>
     */
    private static function goldens(string $prefix): array
    {
        $goldens = [];
        foreach (array_keys(SynthesisGoldenTest::cases()) as $case) {
            if (str_starts_with($case, $prefix)) {
                $goldens[$case] = (string) file_get_contents(self::repositoryRoot() . SynthesisGoldenTest::EXPECTED . $case . '.txt');
            }
        }
        self::assertNotSame([], $goldens, $prefix);

        return $goldens;
    }

    public function testEveryVitestTemplateTypeChecks(): void
    {
        $root = self::scratchPath('knossos-stale-templates-lint');
        try {
            $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/test-fidelity/synthesis/typescript', $root);
            $modules = self::repositoryRoot() . '/workers/typescript/node_modules';
            symlink($modules, $root . '/node_modules');
            mkdir($root . '/lint');
            // The one import the fixture cannot resolve offline.
            file_put_contents($root . '/lint/shims.d.ts', "declare module 'remote-lib' { export class Remote {} }\n");
            $linted = 0;
            foreach (self::goldens('ts-') as $case => $template) {
                if (str_contains($template, '(jest)')) {
                    continue;
                }
                file_put_contents($root . '/lint/' . $case . '.ts', self::typeImports($root, $template) . $template);
                $linted++;
            }
            file_put_contents($root . '/tsconfig.lint.json', (string) json_encode(['compilerOptions' => [
                'target' => 'ES2022', 'module' => 'ESNext', 'moduleResolution' => 'Bundler', 'strict' => true, 'noEmit' => true, 'skipLibCheck' => true, 'types' => [],
            ], 'include' => ['src', 'lint']]));
            exec(sprintf('NODE_DISABLE_COMPILE_CACHE=1 node %s -p %s 2>&1', escapeshellarg($modules . '/typescript/bin/tsc'), escapeshellarg($root . '/tsconfig.lint.json')), $output, $status);

            assertSame([0, ''], [$status, implode("\n", $output)]);
            self::assertGreaterThan(10, $linted);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testEveryPhpTemplatePassesPhpLint(): void
    {
        $root = self::scratchPath('knossos-stale-templates-lint-php');
        try {
            mkdir($root, 0o777, true);
            foreach (self::goldens('php-') as $case => $template) {
                $file = $root . '/' . $case . '.php';
                file_put_contents($file, "<?php\n\nfinal class TemplateTest extends \\PHPUnit\\Framework\\TestCase\n{\n    public function testTemplate(): void\n    {\n" . $template . "    }\n}\n");
                exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($file)), $output, $status);
                assertSame(0, $status, $case . ': ' . implode("\n", $output));
            }
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testEveryPythonTemplateCompiles(): void
    {
        $root = self::scratchPath('knossos-stale-templates-lint-python');
        try {
            mkdir($root, 0o777, true);
            foreach (self::goldens('py-') as $case => $template) {
                $file = $root . '/' . str_replace('-', '_', $case) . '.py';
                file_put_contents($file, $template);
                exec(sprintf('python3 -I -m py_compile %s 2>&1', escapeshellarg($file)), $output, $status);
                assertSame(0, $status, $case . ': ' . implode("\n", $output));
            }
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * An `import type` of every name the template's target file exports or
     * imports, so the template's types resolve as they would in a test of it.
     */
    private static function typeImports(string $root, string $template): string
    {
        preg_match('/^\/\/ knossos synthesize_mock: typescript\/(\S+?)\.ts#/', $template, $match);
        self::assertNotEmpty($match, $template);
        $module = $match[1];
        $source = (string) file_get_contents($root . '/' . $module . '.ts');
        $imports = [];
        preg_match_all('/^export (?:abstract )?(?:class|interface|type) (\w+)/m', $source, $exported);
        $imports['../' . $module] = $exported[1];
        preg_match_all('/^import \{([^}]+)\} from \'(\.[^\']+)\';/m', $source, $imported, PREG_SET_ORDER);
        foreach ($imported as [, $names, $from]) {
            $imports['../' . dirname($module) . '/' . substr($from, 2)] = array_map('trim', explode(',', $names));
        }
        $lines = '';
        foreach ($imports as $from => $names) {
            $lines .= sprintf("import type { %s } from '%s';\n", implode(', ', $names), $from);
        }

        return $lines;
    }
}

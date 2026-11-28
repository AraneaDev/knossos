<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use InvalidArgumentException;
use Knossos\Mcp\ToolCatalog;
use Knossos\Mcp\ToolService;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * `synthesize_mock` through the tool (Decision 5): the output shape, the
 * framework choice, and every name it cannot answer fully (Review Focus 3):
 * an ambiguous, unknown or external symbol, a function, an enum, a PHP
 * trait or final class are errors; a template is deterministic.
 */
#[Group('mcp')]
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
#[Group('php-scanner')]
#[Group('python-scanner')]
#[Group('rust-scanner')]
final class SynthesizeMockToolTest extends KnossosTestCase
{
    public function testTheToolIsListed(): void
    {
        $tool = array_column(ToolCatalog::definitions(), null, 'name')['synthesize_mock'] ?? null;

        self::assertNotNull($tool);
        assertSame('Synthesize mock', $tool['title']);
        assertSame(['project_id', 'symbol'], $tool['inputSchema']['required']);
        assertFalse($tool['inputSchema']['additionalProperties']);
        assertSame(['vitest', 'jest', 'phpunit', 'mockery', 'prophecy', 'unittest.mock', 'pytest-mock', 'mockall', 'hand-written'], $tool['inputSchema']['properties']['framework']['enum']);
        assertSame(['boolean', true], [$tool['inputSchema']['properties']['include_values']['type'], $tool['inputSchema']['properties']['include_values']['default']]);
    }

    public function testTheOutputShapeAndErrors(): void
    {
        $this->withScan(function (ToolService $tools, string $projectId): void {
            $result = $tools->call('synthesize_mock', ['project_id' => $projectId, 'symbol' => 'typescript/src/svc.ts#Svc']);
            $data = $result->data;

            assertSame(['symbol', 'file', 'language', 'framework', 'framework_source', 'complete', 'template', 'members', 'omitted', 'notes'], array_keys($data));
            assertSame(['typescript/src/svc.ts#Svc', 'typescript/src/svc.ts', 'typescript', 'vitest', 'default', false], [$data['symbol'], $data['file'], $data['language'], $data['framework'], $data['framework_source'], $data['complete']]);
            assertSame([['name' => 'totalFor', 'signature' => 'totalFor(id: string, opts?: number): number', 'rendered' => "  // totalFor(id: string, opts?: number): number\n  totalFor: vi.fn<(id: string, opts?: number) => number>().mockReturnValue(0),", 'unknown' => []]], $data['members']);
            assertSame([['name' => 'label', 'reason' => 'no signature recorded: an accessor, a docblock @method, or a member the scan gives no node']], $data['omitted']);
            assertSame(['Import Svc, and the types its members name, from typescript/src/svc.ts.'], $data['notes']);
            assertSame('vitest mock of typescript/src/svc.ts#Svc (default): 1 member, 1 omitted, incomplete.', $result->summary);
            // Deterministic: the same graph gives the same bytes, and the template is not empty.
            self::assertStringContainsString('totalFor', $data['template']);
            assertSame(json_encode($data), json_encode($tools->call('synthesize_mock', ['project_id' => $projectId, 'symbol' => 'typescript/src/svc.ts#Svc'])->data));

            $errors = [
                // Several types share the name: each is listed, with its file and line (Momus took the first).
                [['symbol' => 'Named'], '"Named" names 2 classes, interfaces or traits; name one by its canonical name: synth::focus::Named (rust/src/focus.rs:22), typescript/src/named.ts#Named (typescript/src/named.ts:5).'],
                [['symbol' => 'Nope'], 'No class, interface or trait is named "Nope".'],
                [['symbol' => 'helper'], 'No class, interface or trait is named "helper".'],
                [['symbol' => 'App\FixturesEnum\Lane'], 'No class, interface or trait is named "App\FixturesEnum\Lane".'],
                // A Rust or Python enum is a class node; its `cases` make it one (preflight F7).
                [['symbol' => 'synth::focus::Mode'], '"synth::focus::Mode" names an enum, which is no class, interface or trait.'],
                // A Rust enum that derives nothing sends no `cases`; its `rust_item` marks it, as it marks a union.
                [['symbol' => 'synth::focus::Plain'], '"synth::focus::Plain" names an enum, which is no class, interface or trait.'],
                [['symbol' => 'synth::focus::Bits'], '"synth::focus::Bits" names a union, which is no class, interface or trait.'],
                [['symbol' => 'python.app.models.Color'], '"python.app.models.Color" names an enum, which is no class, interface or trait.'],
                [['symbol' => 'App\Loggable'], 'App\Loggable is a PHP trait: PHPUnit 12 cannot double a trait, and a template would fail when run; mock a class that uses it.'],
                [['symbol' => 'App\FinalService'], 'App\FinalService is final: PHPUnit, Mockery and Prophecy cannot double a final class; mock an interface it implements.'],
                [['symbol' => 'Other', 'framework' => 'phpunit'], 'Framework "phpunit" writes php templates, and the symbol is typescript: use vitest or jest.'],
                [['symbol' => 'Engine', 'framework' => 'hand-written'], 'synth::focus::Engine is a struct: a hand-written fake implements a trait, and a struct has none to implement; use mockall, or fake a trait it implements.'],
                [['symbol' => 'Widget', 'language' => 'cobol'], 'language must be php, python, rust, or typescript.'],
            ];
            foreach ($errors as [$arguments, $message]) {
                try {
                    $tools->call('synthesize_mock', ['project_id' => $projectId, ...$arguments]);
                    self::fail(json_encode($arguments) . ' gave a template.');
                } catch (InvalidArgumentException $error) {
                    assertSame($message, $error->getMessage(), json_encode($arguments) ?: '');
                }
            }
            // A language settles a name two languages declare.
            assertSame('synth::types::Widget', $tools->call('synthesize_mock', ['project_id' => $projectId, 'symbol' => 'Widget', 'language' => 'rust'])->data['symbol']);
        });
    }

    public function testFrameworkSources(): void
    {
        $this->withScan(function (ToolService $tools, string $projectId): void {
            $root = $this->scanRoot;
            // A manifest dev dependency, when the project has no doubles of the language.
            file_put_contents($root . '/python/pyproject.toml', "[project.optional-dependencies]\ntest = [\"pytest-mock>=3\"]\n");
            $data = $tools->call('synthesize_mock', ['project_id' => $projectId, 'symbol' => 'python.app.repo.Repo'])->data;
            assertSame(['pytest-mock', 'manifest'], [$data['framework'], $data['framework_source']]);
            // The project's own doubles come before the manifest.
            mkdir($root . '/python/tests');
            file_put_contents($root . '/python/tests/test_repo.py', "from unittest.mock import MagicMock\n\nfrom app.repo import Repo\n\n\ndef test_repo():\n    repo = MagicMock(spec=Repo)\n    repo.price.return_value = 1\n    assert repo.price() == 1\n");
            $tools->call('scan_project', ['path' => $root]);
            $data = $tools->call('synthesize_mock', ['project_id' => $projectId, 'symbol' => 'python.app.repo.Repo'])->data;
            assertSame(['unittest.mock', 'existing_doubles'], [$data['framework'], $data['framework_source']]);
            assertSame(['pytest-mock', 'explicit'], array_values(array_intersect_key($tools->call('synthesize_mock', ['project_id' => $projectId, 'symbol' => 'python.app.repo.Repo', 'framework' => 'pytest-mock'])->data, ['framework' => 1, 'framework_source' => 1])));
        });
    }

    private string $scanRoot = '';

    /** Run `$test` against a scanned copy of the four-language synthesis fixture. */
    private function withScan(\Closure $test): void
    {
        [$tools, $projectId, $root] = $this->buildToolServiceWithScan('test-fidelity/synthesis');
        $this->scanRoot = $root;
        try {
            $test($tools, $projectId);
        } finally {
            $this->removeTempTree($root);
        }
    }
}

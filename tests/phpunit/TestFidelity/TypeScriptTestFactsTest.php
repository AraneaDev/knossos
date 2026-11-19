<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use FilesystemIterator;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The test doubles the real TypeScript worker reads out of test files, down to
 * what each pinned value is held to. The worker's own vitest suite pins the
 * same readings in-process; these run the worker as the core does, so the
 * paths a scan takes through it count towards its coverage.
 */
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class TypeScriptTestFactsTest extends KnossosTestCase
{
    use ScansTypeScript;

    private const TSCONFIG = '{"compilerOptions":{"strict":true,"module":"esnext","target":"es2022","moduleResolution":"bundler"}}';

    private const ROW = "export interface Row { id: string; status: 'open' | 'closed' }\n"
        . "export interface Stamp { at: number }\n"
        . "export class Repo {\n"
        . "  load(): Row { throw new Error(); }\n"
        . "  fetch(): Promise<Row> { throw new Error(); }\n"
        . "  both(): Row & Stamp { throw new Error(); }\n"
        . "  list(): Row[] { throw new Error(); }\n"
        . "  count(): number { return 0; }\n"
        . "}\n";

    /**
     * Every file of a fixture project, by its path inside the project.
     *
     * @return array<string, string>
     */
    private static function fixture(string $name): array
    {
        $base = self::repositoryRoot() . '/tests/Fixtures/test-fidelity/typescript/' . $name;
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[substr((string) $file, strlen($base) + 1)] = (string) file_get_contents((string) $file);
        }

        return $files;
    }

    /**
     * @param array<string, string> $files
     * @return list<NodeFact>
     */
    private function nodes(array $files): array
    {
        return array_merge([], ...array_map(static fn(ScanContribution $c): array => $c->nodes, $this->scanTypeScript($files + ['tsconfig.json' => self::TSCONFIG])));
    }

    /**
     * The node of a kind at a line of a file; there is exactly one.
     *
     * @param list<NodeFact> $nodes
     */
    private static function at(array $nodes, string $kind, string $path, int $line, ?string $pattern = null): NodeFact
    {
        $found = array_values(array_filter($nodes, static fn(NodeFact $node): bool => $node->kind === $kind
            && $node->evidence->relativePath === $path
            && $node->evidence->startLine === $line
            && ($pattern === null || ($node->attributes['pattern'] ?? null) === $pattern)));
        assertCount(1, $found, sprintf('%s at %s:%d', $kind, $path, $line));

        return $found[0];
    }

    /**
     * What the first pinned return value of the double on a line was judged
     * to be: true, false, or "unknown".
     *
     * @param list<NodeFact> $nodes
     */
    private static function assignable(array $nodes, string $path, int $line): bool|string
    {
        $members = self::at($nodes, 'test_double', $path, $line)->attributes['members'];
        assert(is_array($members) && is_array($members[0]) && is_array($members[0]['returns']) && is_array($members[0]['returns'][0]));
        $answer = $members[0]['returns'][0]['assignable'];
        assert(is_bool($answer) || is_string($answer));

        return $answer;
    }

    /**
     * The answers for `vi.spyOn(repo, ...)` lines, by line.
     *
     * @param list<string> $lines the spy statements, from line 4 of the file
     * @return array<int, bool|string>
     */
    private function answers(array $lines): array
    {
        $nodes = $this->nodes([
            'tsconfig.json' => self::TSCONFIG,
            'src/row.ts' => self::ROW,
            'tests/spies.test.ts' => "import { vi } from 'vitest';\nimport { Repo, Row } from '../src/row';\nconst repo = new Repo();\nconst loose: any = {};\n"
                . implode("\n", $lines) . "\n",
        ]);
        $answers = [];
        foreach ($lines as $index => $_) {
            $answers[$index + 5] = self::assignable($nodes, 'tests/spies.test.ts', $index + 5);
        }

        return $answers;
    }

    public function testAnInferredImplementationIsHeldToTheDeclaredReturnExpressionByExpression(): void
    {
        $answers = $this->answers([
            "vi.spyOn(repo, 'load').mockImplementation(() => ({ id: 'a', status: 'open' }));",
            "vi.spyOn(repo, 'load').mockImplementation(() => { if (repo) return { id: 'a', status: 'closed' }; return { id: 'b', status: 'open' }; });",
            "vi.spyOn(repo, 'load').mockImplementation(() => ({ id: 'a', status: 'gone' }));",
            "vi.spyOn(repo, 'fetch').mockImplementation(async () => ({ id: 'a', status: 'open' }));",
            "vi.spyOn(repo, 'load').mockImplementation(() => ({ id: 1, status: 'open' }));",
            "vi.spyOn(repo, 'load').mockImplementation(() => ({ status: 'open' }));",
            "vi.spyOn(repo, 'load').mockImplementation(() => (repo ? { id: 'a', status: 'open' } : { id: 'b', status: 'closed' }));",
            "vi.spyOn(repo, 'load').mockImplementation(() => ({ ...loose, status: 'open' }));",
            "vi.spyOn(repo, 'list').mockImplementation(() => [{ id: 'a', status: 'open' }]);",
            "vi.spyOn(repo, 'list').mockImplementation(() => [{ id: 'a', status: 'gone' }]);",
            "vi.spyOn(repo, 'list').mockImplementation(() => [...[]]);",
            "vi.spyOn(repo, 'fetch').mockImplementation(() => Promise.resolve({ id: 'a', status: 'open' }));",
            "vi.spyOn(repo, 'fetch').mockImplementation(async () => await Promise.resolve({ id: 'a', status: 'gone' }));",
            "vi.spyOn(repo, 'count').mockImplementation(() => 'many');",
            "vi.spyOn(repo, 'load').mockImplementation(() => ({ ['id']: 'a', status: 'open' }));",
            "vi.spyOn(repo, 'both').mockImplementation(() => ({ id: 'a', status: 'open', at: 1 }));",
            "vi.spyOn(repo, 'both').mockImplementation(() => ({ id: 'a', status: 'open' }));",
            "vi.spyOn(repo, 'load').mockImplementation(() => { return; });",
        ]);

        assertSame([
            5 => true, 6 => true, 7 => false, 8 => true, 9 => false, 10 => false, 11 => true,
            12 => 'unknown', 13 => true, 14 => false, 15 => 'unknown', 16 => true, 17 => false,
            18 => false, 19 => 'unknown', 20 => true, 21 => false, 22 => 'unknown',
        ], $answers);
    }

    public function testAPinnedValueIsHeldToWhatTheMemberDeclaresWhateverFormPinsIt(): void
    {
        $answers = $this->answers([
            "vi.spyOn(repo, 'load').mockReturnValue({ id: 'a', status: 'open' });",
            "vi.spyOn(repo, 'fetch').mockResolvedValue({ id: 'a', status: 'open' });",
            "vi.spyOn(repo, 'load').mockReturnValue({ id: 'a', status: 'gone' });",
            "vi.spyOn(repo, 'fetch').mockResolvedValue({ id: 'a', status: 'gone' });",
            "vi.spyOn(repo, 'fetch').mockResolvedValue({ id: 'a', status: 'open' } as any);",
            "vi.spyOn(repo, 'load').mockReturnValue(loose as any);",
            "vi.spyOn(repo, 'fetch').mockResolvedValue({ id: 'a' } as any);",
            "vi.spyOn(repo, 'load').mockReturnValue({ id: 'a', status: 'gone' } as unknown as Row);",
        ]);

        // A literal cast to any is held as written, to no declared type; a
        // cast to a named type is that type.
        assertSame([5 => true, 6 => true, 7 => false, 8 => false, 9 => true, 10 => true, 11 => false, 12 => true], $answers);
    }

    public function testTheMomusFixturesGiveEveryDoublePatternItsTarget(): void
    {
        $nodes = $this->nodes(self::fixture('momus'));

        $proxy = self::at($nodes, 'test_double', 'tests/proxy.fixture.ts', 4, 'proxy');
        assertSame(['kind' => 'class', 'export' => 'LedgerService'], $proxy->attributes['target']);
        $mocked = self::at($nodes, 'test_double', 'tests/ledger.test.ts', 22, 'vi.mocked-instance');
        assertSame(['kind' => 'class', 'export' => 'Db'], $mocked->attributes['target']);
        $global = self::at($nodes, 'test_double', 'tests/automock.fixture.ts', 6, 'vi.stubGlobal');
        assertSame(['kind' => 'global', 'export' => 'fetch'], $global->attributes['target']);
        $automocks = [];
        foreach ($nodes as $node) {
            if ($node->kind === 'test_double' && $node->evidence->relativePath === 'tests/automock.fixture.ts' && ($node->attributes['automock'] ?? false) === true) {
                $automocks[] = $node->attributes['pattern'];
            }
        }
        assertSame(['vi.importMock', 'jest.requireMock', 'jest.createMockFromModule'], $automocks);
    }

    public function testTheNemesisFixturesTraceACastInstanceAndAPackagesSpy(): void
    {
        $nodes = $this->nodes(self::fixture('nemesis'));

        $cast = self::at($nodes, 'test_double', 'tests/UserService.spec.ts', 23);
        assertSame(['kind' => 'instance-member', 'export' => 'UserService', 'member' => 'purgeCache'], $cast->attributes['target']);
        assertSame('unknown', self::assignable($nodes, 'tests/packageMock.spec.ts', 11));
    }
}

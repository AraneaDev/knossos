<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * Where each assertion's operands get their values, as the real TypeScript
 * worker reads them out of a test: a stub's pinned value, the code under
 * test, a literal, a row of a parameterised test, or nowhere it can say. The
 * worker's own vitest suite pins the same readings in-process; these run it
 * as the core does, so the paths a scan takes through it count towards its
 * coverage.
 */
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class TypeScriptTestCaseFlowTest extends KnossosTestCase
{
    use ScansTypeScript;

    private const PRODUCT = [
        'tsconfig.json' => '{"compilerOptions":{"strict":true,"module":"esnext","target":"es2022","moduleResolution":"bundler"}}',
        'src/engine.ts' => "export class Engine { run(): number { return 1; } }\nexport function start(): number { return 2; }\n",
        'src/ids.ts' => "export function make() { return { id: Math.random() }; }\nexport function next(): number { return 0; }\nexport function gen(): string { return 'x'; }\n",
    ];

    /**
     * The assertions of a test file by line, each as its operands'
     * `source[:text[=value]]`, plus the tests' production-call counts by line.
     *
     * @param list<string> $lines the test file, line by line
     * @return array{assertions: array<int, list<string>>, calls: array<int, int>, nodes: list<NodeFact>}
     */
    private function read(array $lines): array
    {
        $path = 'tests/subject.test.ts';
        $nodes = array_merge([], ...array_map(
            static fn(ScanContribution $c): array => $c->nodes,
            $this->scanTypeScript(self::PRODUCT + [$path => implode("\n", $lines) . "\n"]),
        ));
        $assertions = [];
        $calls = [];
        foreach ($nodes as $node) {
            if ($node->kind !== 'test_case' || $node->evidence->relativePath !== $path) {
                continue;
            }
            $calls[$node->evidence->startLine] = (int) $node->attributes['production_calls'];
            $found = $node->attributes['assertions'];
            assert(is_array($found));
            foreach ($found as $assertion) {
                assert(is_array($assertion) && is_int($assertion['line']) && is_array($assertion['operands']));
                $assertions[$assertion['line']] = array_map(static function (mixed $operand): string {
                    assert(is_array($operand) && is_string($operand['source']));

                    return $operand['source'] . (isset($operand['text']) ? ':' . $operand['text'] : '') . (isset($operand['value']) ? '=' . $operand['value'] : '');
                }, $assertion['operands']);
            }
        }

        return ['assertions' => $assertions, 'calls' => $calls, 'nodes' => $nodes];
    }

    public function testAHelperThatWrapsTheSubjectHandsBackWhereItsValueComesFrom(): void
    {
        $read = $this->read([
            "import { beforeEach, expect, it } from 'vitest';",
            "import { Engine, start } from '../src/engine';",
            'let engine: Engine;',
            'beforeEach(() => {',
            '  engine = new Engine();',
            '});',
            'const go = () => start();',
            'function wrapped() { return start(); }',
            "function fixed() { return 'x'; }",
            'function branching(flag: boolean) { if (flag) return start(); return 1; }',
            'const loose = function () { return engine; };',
            "it('runs', () => {",
            '  expect(engine.run()).toBe(1);',
            '});',
            "it('starts', () => {",
            '  expect(go()).toBe(2);',
            '  expect(wrapped()).toBe(2);',
            '  expect(fixed()).toBe(2);',
            '  expect(branching(true)).toBe(2);',
            '  expect(loose()).toBe(2);',
            '});',
            "it('loads', async () => {",
            "  const { start: later } = await import('../src/engine');",
            '  expect(later()).toBe(2);',
            '  const dynamic = await import("../src/engine");',
            '  expect(dynamic.start()).toBe(2);',
            '});',
        ]);

        assertSame(['production', 'literal:1'], $read['assertions'][13]);
        // A helper that wraps the subject, however it is written, hands back
        // the subject; one returning a literal, one of several values, or a
        // variable the test reassigns, says nothing.
        assertSame(['production', 'literal:2'], $read['assertions'][16]);
        assertSame(['production', 'literal:2'], $read['assertions'][17]);
        assertSame(['unknown', 'literal:2'], $read['assertions'][18]);
        assertSame(['unknown', 'literal:2'], $read['assertions'][19]);
        assertSame(['unknown', 'literal:2'], $read['assertions'][20]);
        assertSame(['production', 'literal:2'], $read['assertions'][24]);
        assertSame(['production', 'literal:2'], $read['assertions'][26]);
        assertSame([12 => 1, 15 => 3, 22 => 4], $read['calls']);
    }

    public function testACallInsideAnOperandGivesItNoTextAndAReassignedVariableProvesNothing(): void
    {
        $read = $this->read([
            "import { expect, it } from 'vitest';",
            "import { gen, make, next } from '../src/ids';",
            "it('makes unique ids', () => {",
            '  expect(make().id).not.toBe(make().id);',
            '  expect(`${gen()}`).not.toBe(`${gen()}`);',
            '  const arr = [1, 2];',
            '  expect(arr[next()]).toBe(arr[next()]);',
            '  expect(arr[0]).toBe(arr[0]);',
            "  let out = '';",
            '  for (const c of gen()) out += c;',
            '  expect(out.length).toBe(3);',
            "  const fixed = 'abc';",
            '  expect(fixed.length).toBe(3);',
            '});',
            "it('loops', () => {",
            '  const a = b;',
            '  const b = a;',
            '  expect(a).toBe(1);',
            '  expect(b.x).toBe(1);',
            '});',
        ]);

        // A call is evaluated again on each side: no text to compare.
        assertSame(['unknown', 'unknown'], $read['assertions'][4]);
        assertSame(['unknown', 'unknown'], $read['assertions'][5]);
        assertSame(['unknown', 'unknown'], $read['assertions'][7]);
        assertSame(['unknown:arr[0]', 'unknown:arr[0]'], $read['assertions'][8]);
        // A binding that can be reassigned is no constant; a constant's member is.
        assertSame(['unknown:out.length', 'literal:3'], $read['assertions'][11]);
        assertSame(['literal:fixed.length', 'literal:3'], $read['assertions'][13]);
        // Bindings that name each other prove nothing.
        assertSame(['unknown:a', 'literal:1'], $read['assertions'][18]);
        assertSame(['unknown:b.x', 'literal:1'], $read['assertions'][19]);
    }

    public function testAParameterisedTestsRowIsAParameterAndNegationIsRecorded(): void
    {
        $read = $this->read([
            "import { describe, expect, it, test, vi } from 'vitest';",
            "it.each([[1, 1]])('adds %i', (a, b) => {",
            '  expect(a).toBe(b);',
            '});',
            'test.each`a | b`(\'table %s\', ({ a }) => {',
            '  expect(a.x).toBeDefined();',
            '});',
            "it.only('only', () => { expect(1).toBe(1); });",
            "test.skip('skipped', () => { expect(2).toBe(2); });",
            "describe.each([1])('group %i', (n) => {",
            "  it('inside', () => expect(n).toBe(n));",
            '});',
            "it('differs from the stub', async () => {",
            '  const stub = { get: vi.fn() };',
            '  stub.get.mockReturnValue(42);',
            '  expect(stub.get()).not.toBe(42);',
            '  expect(stub.get()).toBe(42);',
            '  await expect(stub.get()).resolves.not.toBe(42);',
            '  expect(stub.get()).not.resolves;',
            '});',
            "it('pins an implementation', () => {",
            '  const a = { one: vi.fn(), two: vi.fn(), three: vi.fn(), four: vi.fn() };',
            '  a.one.mockImplementation(() => 7);',
            '  a.two.mockImplementation(function () { if (a) { return 8; } return 9; });',
            '  a.three.mockImplementation((() => 10));',
            '  a.four.mockImplementation(impl);',
            '  expect(a.one()).toBe(7);',
            '  expect(a.two()).toBe(8);',
            '  expect(a.three()).toBe(10);',
            '  expect(a.four()).toBe(0);',
            '});',
            'function impl() { return 0; }',
            'function helper() { expect(3).toBe(3); }',
        ]);

        assertSame(['parameter:a', 'parameter:b'], $read['assertions'][3]);
        assertSame(['parameter:a.x'], $read['assertions'][6]);
        assertSame(['literal:1', 'literal:1'], $read['assertions'][8]);
        // A describe's own parameter is no row of the test.
        assertSame(['unknown:n', 'unknown:n'], $read['assertions'][11]);
        // A stub's pinned value reaches the assertion, negated or not.
        assertSame(['mock-config=42', 'literal:42'], $read['assertions'][16]);
        assertSame(['mock-config=42', 'literal:42'], $read['assertions'][17]);
        assertSame(['mock-config=42', 'literal:42'], $read['assertions'][18]);
        // An implementation pins what it returns: an expression body, the one
        // literal every return hands back, a parenthesised one; any other,
        // two returns of two values among them (G1-09), is its own text.
        assertSame(['mock-config=7', 'literal:7'], $read['assertions'][27]);
        assertSame(['mock-config=function () { if (a) { return 8; } return 9; }', 'literal:8'], $read['assertions'][28]);
        assertSame(['mock-config=10', 'literal:10'], $read['assertions'][29]);
        assertSame(['mock-config=impl', 'literal:0'], $read['assertions'][30]);
        // An assertion outside every test belongs to no test.
        assertCount(0, array_filter($read['assertions'], static fn(int $line): bool => $line === 32, ARRAY_FILTER_USE_KEY));
        $negated = [];
        foreach ($read['nodes'] as $node) {
            if ($node->kind === 'test_case' && $node->evidence->startLine === 13) {
                $assertions = $node->attributes['assertions'];
                assert(is_array($assertions));
                foreach ($assertions as $assertion) {
                    assert(is_array($assertion) && is_int($assertion['line']));
                    $negated[$assertion['line']] = $assertion['negated'] ?? false;
                }
            }
        }
        // A property read with no matcher called is no assertion.
        assertSame([16 => true, 17 => false, 18 => true], $negated);
    }

    /** The call matchers of each double of a test file, by the line the double is made on, as `api:line:count[:spread]`. */
    public function testACallMatcherIsReadOnTheMemberItIsAboutAndOnNoOther(): void
    {
        $path = 'tests/matchers.test.ts';
        $lines = [
            "import { expect, it, vi } from 'vitest';",
            "import { Engine } from '../src/engine';",
            "it('matchers', async () => {",
            '  const engine = new Engine();',
            "  const spy = vi.spyOn(engine, 'run');",
            '  const port = { send: vi.fn() };',
            '  const mocked = vi.mocked(new Engine());',
            '  mocked.run.mockReturnValue(1);',
            "  expect(spy).toHaveBeenCalledWith('a', 1);",
            "  expect(spy).not.toHaveBeenNthCalledWith(2, 'a');",
            '  expect(spy).lastCalledWith(...[1, 2]);',
            '  expect(engine.run).toBeCalledWith();',
            "  (spy as any).nthCalledWith(1, 'x', 'y');",
            "  expect(port.send).resolves.toHaveBeenCalledWith('a');",
            "  expect(mocked.run).toHaveBeenCalledWith('a', 'b');",
            '  expect(mocked.other).toHaveBeenCalledWith(1);',
            '  expect(engine.start).toHaveBeenCalledWith(1);',
            '  expect(spy.name).toHaveBeenCalledWith(1);',
            '  expect(vi.fn()).toHaveBeenCalledWith(1);',
            '  expect().toHaveBeenCalledWith(1);',
            '  expect(spy).toHaveBeenCalledTimes(1);',
            '});',
        ];
        $nodes = array_merge([], ...array_map(
            static fn(ScanContribution $c): array => $c->nodes,
            $this->scanTypeScript(self::PRODUCT + [$path => implode("\n", $lines) . "\n"]),
        ));
        $matchers = [];
        foreach ($nodes as $node) {
            if ($node->kind !== 'test_double' || $node->evidence->relativePath !== $path) {
                continue;
            }
            $members = $node->attributes['members'];
            assert(is_array($members));
            foreach ($members as $member) {
                assert(is_array($member) && is_string($member['name']));
                $listed = $member['called_with'] ?? [];
                assert(is_array($listed));
                $matchers[$node->evidence->startLine . ':' . $member['name']] = array_map(static function (mixed $matcher): string {
                    assert(is_array($matcher));

                    return sprintf('%s:%s:%s%s', $matcher['api'], $matcher['line'], $matcher['count'], $matcher['spread'] === true ? ':spread' : '');
                }, $listed);
            }
        }

        assertSame([
            '5:run' => ['toHaveBeenCalledWith:9:2', 'toHaveBeenNthCalledWith:10:1', 'lastCalledWith:11:1:spread', 'toBeCalledWith:12:0', 'nthCalledWith:13:2'],
            '6:send' => ['toHaveBeenCalledWith:14:1'],
            '7:run' => ['toHaveBeenCalledWith:15:2'],
        ], $matchers);
    }
}

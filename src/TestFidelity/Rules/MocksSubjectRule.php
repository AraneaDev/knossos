<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Rules;

use Knossos\TestFidelity\Facts\{TestCaseFact, TestDouble};
use Knossos\TestFidelity\{FindingCategory, FindingConfidence, TestFidelityGraph, TestFidelityRule, TestFinding};

/**
 * A test replaces the very module it exercises: Momus's MOCK-002, with the
 * test's subjects (the project modules its production calls land in) standing
 * where Momus has only the test file's name.
 *
 * Only a double of a whole module counts (`vi.mock`, `jest.mock`, an
 * automock), as in Momus for TypeScript: a spy on one member of the subject,
 * or a mocked instance of its class, is a partial mock and a normal one. A
 * double made in a test answers to that test's subjects; one made outside any
 * test, to those of every test in its file, and the first test caught is
 * named.
 *
 * A manual mock under `__mocks__/` is a stand-in, never the subject: neither
 * a double defined in one, nor a double whose target or subject is one, is
 * reported. Always a warning: a test may mean to check its own wiring.
 */
final readonly class MocksSubjectRule implements TestFidelityRule
{
    public const ID = 'test.fidelity.mocks_subject.v1';

    /**
     * One finding at most per double, in the graph's double order.
     *
     * @return iterable<TestFinding>
     */
    public function findings(TestFidelityGraph $graph): iterable
    {
        foreach ($graph->doubles() as $double) {
            $target = $graph->target($double);
            if ($target === null || $double->target['kind'] !== 'module' || isset($double->target['member']) || $graph->isExternal($double)) {
                continue;
            }
            $module = DoubleTests::file($target->canonicalName);
            if (self::isManualMock($module) || self::isManualMock(DoubleTests::file($double->canonicalName))) {
                continue;
            }
            $case = self::caught($double, $module, $graph);
            if ($case === null) {
                continue;
            }
            $node = $graph->nodeOf($double);
            $message = sprintf("%s replaces %s, which the test '%s' exercises", DriftText::clean($node->displayName), DriftText::clean($module), DriftText::clean($case->name));
            yield new TestFinding($node->localId, $target->localId, FindingCategory::MocksSubject, FindingConfidence::Warning, self::ID, 'mock_of_self', DriftText::clean($message), $node->evidence, [
                'module' => DriftText::clean($module),
                'test_case' => DriftText::clean($case->canonicalName),
            ]);
        }
    }

    /**
     * The first test the double answers to whose subject is the module it replaces, or null.
     *
     * The module is that test's subject when the test's production calls land
     * in it and nowhere else in the project, or when, among several, it is the
     * one the test file is named for (`db.test.ts` for `db.ts`), as Momus reads
     * a subject. A test that also calls into a module it mocks wholesale, say
     * to build a dependency with `new Db()`, is testing something else.
     */
    private static function caught(TestDouble $double, string $module, TestFidelityGraph $graph): ?TestCaseFact
    {
        foreach (DoubleTests::of($double, $graph) as $case) {
            $subjects = array_values(array_filter($case->subjects, static fn(string $subject): bool => !self::isManualMock($subject)));
            if (!in_array($module, $subjects, true)) {
                continue;
            }
            if ($subjects === [$module] || self::namedFor(DoubleTests::file($case->canonicalName)) === self::baseName($module)) {
                return $case;
            }
        }

        return null;
    }

    /** The name a test file is named for: `db` for `tests/db.test.ts`, null for a file not named `<name>.test.*` or `<name>.spec.*`. */
    private static function namedFor(string $testFile): ?string
    {
        return preg_match('/^(.+?)\.(?:test|spec)\.[cm]?[jt]sx?$/D', basename($testFile), $match) === 1 ? $match[1] : null;
    }

    /** A module's file name without its extension: `db` for `src/db.ts`. */
    private static function baseName(string $module): string
    {
        return (string) preg_replace('/\.(?:[cm]?[jt]sx?)$/D', '', basename($module));
    }

    /** Whether a project path lies under a `__mocks__` directory. */
    private static function isManualMock(string $path): bool
    {
        return preg_match('#(?:^|/)__mocks__/#', $path) === 1;
    }

    /** The id every finding of this rule carries. */
    public function id(): string
    {
        return self::ID;
    }
}

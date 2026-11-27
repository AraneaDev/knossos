<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\OnRequest;

use Knossos\TestFidelity\Facts\Assertion;
use Knossos\TestFidelity\Facts\Operand;
use Knossos\TestFidelity\OnRequest\OnRequestInput;
use Knossos\TestFidelity\OnRequest\OverMocking;
use Knossos\TestFidelity\TestFindingKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `OVER_MOCKING`, the port of Momus MOCK-001 (`hygiene.ts:56-96`): a test file
 * whose dependencies are mostly doubles, with fewer than two assertions that
 * read production code. Mocked targets and dependencies are both counted per
 * module (preflight F2).
 */
#[Group('test-fidelity')]
final class OverMockingTest extends TestCase
{
    private const FILE = 'tests/order.test.ts';

    public function testFiresAtTheMomusThreshold(): void
    {
        // 7 of 10 modules mocked: 0.7 is the threshold, and reaching it fires.
        $fires = OverMocking::findings(self::input(mocked: 7, imported: 10));
        self::assertCount(1, $fires);
        self::assertSame('7/10 dependencies mocked, 0 production assertions: over-mocked', $fires[0]->message);
        // 6 of 10 is under it.
        self::assertSame([], OverMocking::findings(self::input(mocked: 6, imported: 10)));
    }

    public function testTwoProductionAssertionsSilenceIt(): void
    {
        $one = OverMocking::findings(self::input(mocked: 1, imported: 1, production: 1, other: 3));
        self::assertCount(1, $one);
        self::assertSame('1/1 dependencies mocked, 1 production assertions: over-mocked', $one[0]->message);
        self::assertSame([], OverMocking::findings(self::input(mocked: 1, imported: 1, production: 2)));
    }

    public function testAnAssertionCountsOnceHoweverManyProductionOperandsItHas(): void
    {
        $assertion = new Assertion('toBe', 3, [new Operand('production', 'a.x()', null, false), new Operand('production', 'a.y()', null, false)]);
        $input = new OnRequestInput(
            [self::FILE => 'typescript'],
            [self::FILE => [self::import('src/a.ts')]],
            [self::FILE => [self::double('src/a.ts')]],
            [self::FILE => [$assertion]],
        );

        self::assertSame('1/1 dependencies mocked, 1 production assertions: over-mocked', OverMocking::findings($input)[0]->message);
    }

    public function testZeroDependenciesIsSilent(): void
    {
        $input = new OnRequestInput([self::FILE => 'typescript'], [self::FILE => [self::import('vitest')]], [self::FILE => []], [self::FILE => []]);

        self::assertSame([], OverMocking::findings($input));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function frameworkImports(): iterable
    {
        yield 'typescript' => ['typescript', 'tests/a.test.ts', ['vitest', '@vitest/expect', 'jest', '@jest/globals']];
        yield 'php' => ['php', 'tests/ATest.php', ['PHPUnit\\Framework\\TestCase', '\\PHPUnit\\Framework\\Assert', 'phpunit\\framework\\mockobject', 'Mockery', 'Mockery\\MockInterface', 'Prophecy\\Argument', 'Pest', 'Pest\\Expectation']];
        yield 'python' => ['python', 'tests/test_a.py', ['unittest', 'unittest.mock', 'mock', 'pytest', 'pytest_mock']];
        yield 'rust' => ['rust', 'tests/a.rs', ['mockall', 'mockall::predicate', 'faux', 'mry', 'mockers', 'mockiato', 'mocktopus', 'mock_derive', 'galvanic', 'std', 'std::sync', 'pretty_assertions', 'pretty-assertions', 'mock-derive']];
    }

    /**
     * @param list<string> $frameworks
     */
    #[DataProvider('frameworkImports')]
    public function testFrameworkImportsAreNotDependencies(string $language, string $file, array $frameworks): void
    {
        $imports = [self::import('src/a'), ...array_map(self::import(...), $frameworks)];
        $input = new OnRequestInput([$file => $language], [$file => $imports], [$file => [self::double('src/a')]], [$file => []]);

        $findings = OverMocking::findings($input);

        self::assertCount(1, $findings);
        self::assertSame('1/1 dependencies mocked, 0 production assertions: over-mocked', $findings[0]->message);
    }

    public function testAFrameworkOfAnotherLanguageIsADependency(): void
    {
        // `pytest` names Python's framework; in a TypeScript file it is a module like any other.
        $input = new OnRequestInput(
            [self::FILE => 'typescript'],
            [self::FILE => [self::import('src/a.ts'), self::import('external:pytest'), self::import('external:unittest')]],
            [self::FILE => [self::double('src/a.ts')]],
            [self::FILE => []],
        );

        self::assertSame([], OverMocking::findings($input));
    }

    public function testAFrameworkNameIsMatchedAsAWholeSegment(): void
    {
        // `vitest-extra`, `mockery_helpers` and `stdx` are not the frameworks they start like.
        $cases = [
            ['typescript', 'vitest-extra'], ['typescript', '@vitest'], ['php', 'MockeryHelpers'], ['php', 'PHPUnitish'],
            ['python', 'pytestx'], ['python', 'unittest_extras'], ['rust', 'stdx'], ['rust', 'mockall_double'],
        ];
        foreach ($cases as [$language, $name]) {
            $input = new OnRequestInput(
                [self::FILE => $language],
                [self::FILE => [self::import('src/a'), self::import('external:' . $name)]],
                [self::FILE => [self::double('src/a')]],
                [self::FILE => []],
            );
            self::assertSame([], OverMocking::findings($input), $language . ' ' . $name . ' is a dependency');
        }
    }

    public function testAStringPatchTargetCountsAsADependency(): void
    {
        // `patch("prod_missing.missing")`: the file never imports prod_missing, yet replaces it.
        $file = 'test_patch_missing.py';
        $input = new OnRequestInput([$file => 'python'], [$file => [self::import('external:unittest.mock')]], [$file => [self::double('prod_missing.py')]], [$file => []]);

        $findings = OverMocking::findings($input);

        self::assertCount(1, $findings);
        self::assertSame('1/1 dependencies mocked, 0 production assertions: over-mocked', $findings[0]->message);
    }

    public function testTwoTargetsInOneModuleCountOnce(): void
    {
        // Two classes of one module mocked, two of three imported modules: 1/3 per module, not 2/3 per class.
        $input = new OnRequestInput(
            [self::FILE => 'typescript'],
            [self::FILE => [self::import('src/a.ts'), self::import('src/b.ts'), self::import('src/c.ts')]],
            [self::FILE => [self::double('src/a.ts', 'A'), self::double('src/a.ts', 'B'), self::double('src/a.ts', 'A')]],
            [self::FILE => []],
        );
        self::assertSame([], OverMocking::findings($input));

        // A module imported twice is one dependency.
        $twice = new OnRequestInput(
            [self::FILE => 'typescript'],
            [self::FILE => [self::import('src/a.ts'), self::import('src/a.ts')]],
            [self::FILE => [self::double('src/a.ts', 'A'), self::double('src/a.ts', 'B')]],
            [self::FILE => []],
        );
        self::assertSame('1/1 dependencies mocked, 0 production assertions: over-mocked', OverMocking::findings($twice)[0]->message);
    }

    public function testASameFileTargetIsNotMocked(): void
    {
        // mockall's own tests declare a trait in the test file and mock it: that is no dependency.
        $file = 'tests/automock.rs';
        $input = new OnRequestInput(
            [$file => 'rust'],
            [$file => [self::import('src/lib.rs'), self::import($file)]],
            [$file => [self::double($file, 'Foo')]],
            [$file => []],
        );

        self::assertSame([], OverMocking::findings($input));
    }

    public function testADoubleWithoutATargetIsNotCounted(): void
    {
        $input = new OnRequestInput(
            [self::FILE => 'typescript'],
            [self::FILE => [self::import('src/a.ts')]],
            [self::FILE => [['module' => null, 'name' => null], ['module' => null, 'name' => null]]],
            [self::FILE => []],
        );

        self::assertSame([], OverMocking::findings($input));
    }

    public function testALocalImportIsNotADependency(): void
    {
        // Momus reads module-level imports only (`parser-python/src/index.ts:101-103`).
        $file = 'test_patch_imports.py';
        $input = new OnRequestInput(
            [$file => 'python'],
            [$file => [self::import('external:unittest.mock'), self::import('external:datetime', local: true)]],
            [$file => [self::double('prod_imports.py'), self::double('prod_imports.py')]],
            [$file => []],
        );

        self::assertSame('1/1 dependencies mocked, 0 production assertions: over-mocked', OverMocking::findings($input)[0]->message);
    }

    public function testAPythonPackageOfAnImportedProjectModuleIsNoDependencyOfItsOwn(): void
    {
        // `from postal import mailer`, where `postal/` is a namespace package: the worker sends the outside
        // `postal` beside the project's `postal.mailer`. Momus counts the one import (Nemesis `module-drift`).
        $file = 'tests/test_mailer.py';
        $postal = ['module' => 'external:postal', 'name' => 'postal', 'local' => false];
        $mailer = ['module' => 'postal/mailer.py', 'name' => 'postal.mailer', 'local' => false];
        $doubles = [$file => [self::double('postal/mailer.py'), self::double('postal/sender.py')]];

        $findings = OverMocking::findings(new OnRequestInput([$file => 'python'], [$file => [$postal, $mailer]], $doubles, [$file => []]));
        self::assertSame('2/2 dependencies mocked, 0 production assertions: over-mocked', $findings[0]->message ?? null);

        // A project import inside a function holds no package for the module-level one: 2/3, silent.
        $local = ['module' => 'postal/mailer.py', 'name' => 'postal.mailer', 'local' => true];
        self::assertSame([], OverMocking::findings(new OnRequestInput([$file => 'python'], [$file => [$postal, $local]], $doubles, [$file => []])));

        // An outside package no project import sits under stays a dependency, and so does one in another language.
        $other = ['module' => 'external:postbox', 'name' => 'postbox', 'local' => false];
        self::assertSame([], OverMocking::findings(new OnRequestInput([$file => 'python'], [$file => [$other, $mailer]], $doubles, [$file => []])));
        $ts = 'tests/mailer.test.ts';
        $tsImports = [$ts => [['module' => 'external:postal', 'name' => 'postal', 'local' => false], ['module' => 'src/a.ts', 'name' => 'postal.mailer', 'local' => false]]];
        self::assertSame([], OverMocking::findings(new OnRequestInput([$ts => 'typescript'], $tsImports, [$ts => [self::double('src/a.ts'), self::double('src/b.ts')]], [$ts => []])));
    }

    public function testItIsAlwaysAWarningAtLineOne(): void
    {
        $finding = OverMocking::findings(self::input(mocked: 3, imported: 3))[0];
        $item = $finding->toItem();
        $message = '3/3 dependencies mocked, 0 production assertions: over-mocked';
        // The key is the finding's identity, the rule and the file: not its counts (G6-12).
        $key = TestFindingKey::of('test.fidelity.over_mocking.v1', 'saturation', self::FILE, null, '');

        self::assertSame([
            'id' => 'on_request:' . $key,
            'key' => $key,
            'category' => 'OVER_MOCKING',
            'confidence' => 'warning',
            'rule_id' => 'test.fidelity.over_mocking.v1',
            'sub_rule' => 'saturation',
            'message' => $message,
            'file' => self::FILE,
            'line' => 1,
            'double' => null,
            'target' => null,
            'tier' => 'on_request',
        ], $item);
        self::assertSame('test.fidelity.over_mocking.v1', OverMocking::ID);
    }

    public function testTwoRunsThatDifferOnlyInCountsGiveOneKey(): void
    {
        // G6-12: a caller tracking a finding across runs keeps its key while the counts move.
        $three = OverMocking::findings(self::input(mocked: 3, imported: 3))[0];
        $four = OverMocking::findings(self::input(mocked: 4, imported: 4, production: 1))[0];

        self::assertNotSame($three->message, $four->message);
        self::assertSame($three->key(), $four->key());
        self::assertSame($three->toItem()['id'], $four->toItem()['id']);
    }

    public function testFindingsAreInFileOrder(): void
    {
        $files = ['tests/b.test.ts' => 'typescript', 'tests/a.test.ts' => 'typescript'];
        $imports = array_map(static fn(): array => [self::import('src/a.ts')], $files);
        $doubles = array_map(static fn(): array => [self::double('src/a.ts')], $files);
        $input = new OnRequestInput($files, $imports, $doubles, []);

        self::assertSame(['tests/a.test.ts', 'tests/b.test.ts'], array_map(static fn($finding): string => $finding->file, OverMocking::findings($input)));
    }

    public function testALanguageWithoutTestImportsIsSilentAndSaysSo(): void
    {
        // The PHP worker sends no `imports` edge, so no PHP test file has a known dependency.
        $files = ['tests/ATest.php' => 'php', 'tests/BTest.php' => 'php', self::FILE => 'typescript'];
        $input = new OnRequestInput(
            $files,
            [self::FILE => [self::import('vitest')]],
            ['tests/ATest.php' => [self::double('src/A.php')], 'tests/BTest.php' => [self::double('src/B.php')], self::FILE => []],
            [],
        );

        self::assertSame([], OverMocking::findings($input));
        self::assertSame(['php'], OverMocking::languagesWithoutImports($input));
        // A local import still shows the worker sends imports.
        $local = new OnRequestInput(['tests/ATest.php' => 'php'], ['tests/ATest.php' => [self::import('external:x', local: true)]], [], []);
        self::assertSame([], OverMocking::languagesWithoutImports($local));
    }

    /**
     * One test file importing `$imported` project modules, of which the first
     * `$mocked` are doubled, with `$production` assertions that read
     * production code and `$other` that do not.
     */
    private static function input(int $mocked, int $imported, int $production = 0, int $other = 0): OnRequestInput
    {
        $imports = [self::import('vitest')];
        $doubles = [];
        for ($i = 0; $i < $imported; ++$i) {
            $imports[] = self::import('src/m' . $i . '.ts');
            if ($i < $mocked) {
                $doubles[] = self::double('src/m' . $i . '.ts');
            }
        }
        $assertions = [];
        for ($i = 0; $i < $production; ++$i) {
            $assertions[] = new Assertion('toBe', 10 + $i, [new Operand('production', 'run()', null, false), new Operand('literal', '1', null, false)]);
        }
        for ($i = 0; $i < $other; ++$i) {
            $assertions[] = new Assertion('toBe', 20 + $i, [new Operand('mock-call', 'spy()', null, false), new Operand('literal', '1', null, false)]);
        }

        return new OnRequestInput([self::FILE => 'typescript'], [self::FILE => $imports], [self::FILE => $doubles], [self::FILE => $assertions]);
    }

    /**
     * An import of a module: a project file by its path, or an outside module as `external:` and its name.
     *
     * @return array{module: string, name: string, local: bool}
     */
    private static function import(string $module, bool $local = false): array
    {
        $name = str_starts_with($module, 'external:') ? substr($module, strlen('external:')) : $module;
        $project = str_starts_with($name, 'src/') || str_starts_with($name, 'tests/');

        return ['module' => $project ? $name : 'external:' . $name, 'name' => $name, 'local' => $local];
    }

    /**
     * A double whose target is declared in `$module`.
     *
     * @return array{module: ?string, name: ?string}
     */
    private static function double(string $module, string $name = 'Target'): array
    {
        return ['module' => $module, 'name' => $name];
    }
}

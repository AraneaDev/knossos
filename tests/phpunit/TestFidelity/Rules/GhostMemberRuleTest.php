<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Rules;

use Knossos\Scanner\Protocol\NodeFact;
use Knossos\TestFidelity\FindingCategory;
use Knossos\TestFidelity\FindingConfidence;
use Knossos\TestFidelity\Rules\GhostMemberRule;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

#[Group('test-fidelity')]
final class GhostMemberRuleTest extends KnossosTestCase
{
    /** @return list<\Knossos\TestFidelity\TestFinding> */
    private static function judge(\Knossos\TestFidelity\TestFidelityGraph $graph): array
    {
        return [...(new GhostMemberRule())->findings($graph)];
    }

    public function testMissingMemberIsDefiniteWithSuggestion(): void
    {
        $graph = DriftFixtures::graph([DriftFixtures::method('totalCents')], [DriftFixtures::stub('totalCent')]);

        $findings = self::judge($graph);

        assertCount(1, $findings);
        assertSame(FindingConfidence::Definite, $findings[0]->confidence);
        assertSame(FindingCategory::GhostMember, $findings[0]->category);
        assertSame('missing_member', $findings[0]->subRule);
        assertSame('vi.spyOn stubs totalCent, which UserService no longer has; did you mean totalCents?', $findings[0]->message);
        assertSame(7, $findings[0]->evidence->startLine);
    }

    public function testNoSuggestionBeyondDistanceTwo(): void
    {
        $graph = DriftFixtures::graph([DriftFixtures::method('totalCents')], [DriftFixtures::stub('refund')]);

        assertSame('vi.spyOn stubs refund, which UserService no longer has', self::judge($graph)[0]->message);
    }

    public function testMemberFoundThroughInheritanceIsNotAGhost(): void
    {
        $base = DriftFixtures::node('class', 'src/Base.ts#Base', 'Base');
        $inherited = DriftFixtures::node('method', 'src/Base.ts#Base::save', 'save');
        $graph = DriftFixtures::graph(
            [],
            [DriftFixtures::stub('save')],
            extra: [$base, $inherited],
            extraEdges: [
                DriftFixtures::edge('contains', $base, $inherited->localId),
                DriftFixtures::edge('extends', DriftFixtures::node('class', 'src/UserService.ts#UserService', 'UserService'), $base->localId),
            ],
        );

        assertSame([], self::judge($graph));
    }

    public function testAnAncestorTheScanDoesNotHoldMakesAbsenceProveNothing(): void
    {
        $graph = DriftFixtures::graph(
            [],
            [DriftFixtures::stub('on')],
            extraEdges: [DriftFixtures::edge('extends', DriftFixtures::node('class', 'src/UserService.ts#UserService', 'UserService'), 'ts:class:node_modules/events#EventEmitter')],
        );

        assertSame([], self::judge($graph));
    }

    public function testAnExternalAncestorMakesAbsenceProveNothing(): void
    {
        // The ancestor is in the scan, as the worker's stand-in for a class outside the project.
        $emitter = DriftFixtures::node('external_class', 'events#EventEmitter', 'EventEmitter');
        $graph = DriftFixtures::graph(
            [DriftFixtures::method('save')],
            [DriftFixtures::stub('on')],
            extra: [$emitter],
            extraEdges: [DriftFixtures::edge('extends', DriftFixtures::node('class', 'src/UserService.ts#UserService', 'UserService'), $emitter->localId)],
        );

        assertSame([], self::judge($graph));
    }

    public function testANameReExportedThroughAChainIsNotAGhost(): void
    {
        assertSame([], self::judge(self::reExportChain('helper', [])));
    }

    public function testANameAbsentFromAReExportChainWithACompleteListIsAGhost(): void
    {
        $findings = self::judge(self::reExportChain('gone', ['helper']));

        assertCount(1, $findings);
        assertSame('missing_export', $findings[0]->subRule);
        assertSame('vi.spyOn stubs gone, which UserService no longer has', $findings[0]->message);
    }

    /**
     * A barrel module, the double's target, re-exporting a module that
     * re-exports another, which declares `helper`.
     *
     * @param list<string> $exports the barrel's complete export list
     */
    private static function reExportChain(string $stubbed, array $exports): \Knossos\TestFidelity\TestFidelityGraph
    {
        $barrel = DriftFixtures::node('module', 'src/UserService.ts#UserService', 'UserService');
        $middle = DriftFixtures::node('module', 'src/middle.ts', 'middle.ts', ['exports' => ['helper']]);
        $helpers = DriftFixtures::node('module', 'src/helpers.ts', 'helpers.ts', ['exports' => ['helper']]);
        $helper = DriftFixtures::node('function', 'src/helpers.ts#helper', 'helper');

        return DriftFixtures::graph(
            [],
            [DriftFixtures::stub($stubbed)],
            ['kind' => 'module', 'specifier' => './UserService'],
            extra: [$middle, $helpers, $helper],
            extraEdges: [
                DriftFixtures::edge('re_exports', $barrel, $middle->localId),
                DriftFixtures::edge('re_exports', $middle, $helpers->localId),
                DriftFixtures::edge('contains', $helpers, $helper->localId),
            ],
            targetKind: 'module',
            targetAttributes: ['exports' => $exports],
        );
    }

    public function testACompileCheckedDoubleIsNeverAGhost(): void
    {
        $methods = [DriftFixtures::method('totalCents')];
        $members = [DriftFixtures::stub('refund')];

        assertCount(1, self::judge(DriftFixtures::graph($methods, $members)), 'the control: without the flag the stub is a ghost');
        assertSame([], self::judge(DriftFixtures::graph($methods, $members, doubleAttributes: ['compile_checked' => true])));
    }

    public function testACompileCheckedModuleDoubleIsNeverAMissingModule(): void
    {
        assertCount(1, self::judge(DriftFixtures::moduleGraph('./gone')), 'the control');
        assertSame([], self::judge(DriftFixtures::moduleGraph('./gone', ['compile_checked' => true])));
    }

    public function testModuleTargetIsAMissingExport(): void
    {
        $graph = DriftFixtures::graph([], [DriftFixtures::stub('gone')], ['kind' => 'module', 'specifier' => './UserService'], targetKind: 'module');

        assertSame('missing_export', self::judge($graph)[0]->subRule);
    }

    public function testDefaultAndEsModuleKeysAreNotExports(): void
    {
        $graph = DriftFixtures::graph([], [DriftFixtures::stub('default'), DriftFixtures::stub('__esModule')], ['kind' => 'module', 'specifier' => './UserService'], targetKind: 'module');

        assertSame([], self::judge($graph));
    }

    public function testExternalPackageTargetIsSilent(): void
    {
        assertSame([], self::judge(DriftFixtures::moduleGraph('axios')));
        assertSame([], self::judge(DriftFixtures::moduleGraph('@scope/pkg')));
        // A package whose name starts with `~` is a package; only `~/` is the project's alias.
        assertSame([], self::judge(DriftFixtures::moduleGraph('~pkg')));
        assertCount(1, self::judge(DriftFixtures::moduleGraph('~/gone')));
    }

    public function testUnresolvedRelativeSpecifierIsAGhost(): void
    {
        $findings = self::judge(DriftFixtures::moduleGraph('./gone'));

        assertCount(1, $findings);
        assertSame('missing_module', $findings[0]->subRule);
        assertSame(FindingConfidence::Definite, $findings[0]->confidence);
        assertNull($findings[0]->targetReference);
        assertSame('vi.mock replaces ./gone, which resolves to nothing in the project', $findings[0]->message);
    }

    public function testUnresolvedAliasIsAGhostButAnAssetIsNot(): void
    {
        assertCount(1, self::judge(DriftFixtures::moduleGraph('@/gone')));
        assertSame([], self::judge(DriftFixtures::moduleGraph('./styles.css')));
    }

    public function testANameTheListHoldsWithoutANodeIsNotAGhost(): void
    {
        // `export const API_URL`, a getter or a parameter property have no node, but the worker lists them.
        $graph = DriftFixtures::graph([], [DriftFixtures::stub('API_URL'), DriftFixtures::stub('total')], ['kind' => 'module', 'specifier' => './config'], targetKind: 'module', targetAttributes: ['exports' => ['API_URL', 'total']]);

        assertSame([], self::judge($graph));
    }

    public function testATargetWithoutACompleteListIsSilent(): void
    {
        foreach ([[], ['member_names' => ['a'], 'member_names_open' => true], ['member_names' => 'a']] as $attributes) {
            assertSame([], self::judge(DriftFixtures::graph([], [DriftFixtures::stub('gone')], targetAttributes: $attributes)));
        }
        assertSame([], self::judge(DriftFixtures::graph([], [DriftFixtures::stub('gone')], ['kind' => 'module', 'specifier' => './c'], targetKind: 'module', targetAttributes: ['exports' => ['a'], 'exports_open' => true])));
    }

    public function testASpecifierTheWorkerResolvedToANodeOutsideTheScanIsNotAGhost(): void
    {
        $double = DriftFixtures::node('test_double', 'tests/a.test.ts#double:2:1', 'vi.mock', [
            'framework' => 'vitest', 'pattern' => 'vi.mock', 'target' => ['kind' => 'module', 'specifier' => './generated/client'], 'automock' => true, 'invocations' => 0, 'members' => [],
        ], 2);
        $graph = \Knossos\TestFidelity\TestFidelityGraph::fromContributions([new \Knossos\Scanner\Protocol\ScanContribution('knossos.typescript:file:tests/a.test.ts', [$double], [DriftFixtures::edge('doubles', $double, 'ts:module:src/generated/client.ts')])]);

        assertSame([], self::judge($graph));
    }

    public function testAssetSpecifiersWithQueriesAreNotGhosts(): void
    {
        foreach (['./icon.svg?react', './x.css?inline', './q.graphql', './m.wasm', './f.woff2', './a.mp4'] as $specifier) {
            assertSame([], self::judge(DriftFixtures::moduleGraph($specifier)), $specifier);
        }
    }

    public function testASpecifierWithALoaderQueryIsSilent(): void
    {
        foreach (['./worker.ts?worker', './x.js?raw'] as $specifier) {
            assertSame([], self::judge(DriftFixtures::moduleGraph($specifier)), $specifier);
        }
    }

    public function testControlCharactersInANameDoNotReachTheMessage(): void
    {
        $graph = DriftFixtures::graph([], [DriftFixtures::stub("bad\n\x1bname")]);

        assertSame('vi.spyOn stubs bad name, which UserService no longer has', self::judge($graph)[0]->message);
    }

    public function testVendorTargetIsNotAGhost(): void
    {
        // `createMock(LoggerInterface::class)`, `Psr\Log` only in vendor/: the scan holds no node to judge against.
        $graph = DriftFixtures::phpGraph([['find', []]], [DriftFixtures::stub('debugg')], doubled: 'Psr\\Log\\LoggerInterface');

        assertSame([], self::judge($graph));
    }

    public function testAPhpMagicTargetIsNotAGhost(): void
    {
        // The class declares __call(): any name may answer (Nemesis `magic-php`).
        $graph = DriftFixtures::phpGraph([['__call', []]], [DriftFixtures::stub('anything')], targetAttributes: ['member_names' => ['__call'], 'member_names_open' => true]);

        assertSame([], self::judge($graph));
    }

    public function testAPhpMemberMatchesInAnyCase(): void
    {
        // PHP method names are case-insensitive; an interface target is reached through the worker's `php:class:` reference.
        assertSame([], self::judge(DriftFixtures::phpGraph([['findById', []]], [DriftFixtures::stub('FINDBYID')])));
        assertSame([], self::judge(DriftFixtures::phpGraph([['findById', []]], [DriftFixtures::stub('findbyid')], targetKind: 'interface', targetAttributes: ['member_names' => ['findById']])));
        // A `@method` docblock name is listed with no node of its own.
        assertSame([], self::judge(DriftFixtures::phpGraph([], [DriftFixtures::stub('FINDBYID')], targetAttributes: ['member_names' => ['findById']])));
        assertSame(['createMock stubs findByld, which UserService no longer has; did you mean findById?'], array_map(static fn($finding): string => $finding->message, self::judge(DriftFixtures::phpGraph([['findById', []]], [DriftFixtures::stub('findByld')], targetKind: 'interface'))));
    }

    public function testAPhpAncestorsNamesAndMagicCount(): void
    {
        // `class UserService extends Base`, `use Magic;`: a parent's `@method` names and a parent's or trait's `__call()` answer too.
        $judge = static function (string $edge, string $kind, array $attributes, string $stub = 'magicCount'): array {
            $ancestor = DriftFixtures::phpType($kind, 'App\Base', $attributes, 'src/Base.php');

            return self::judge(DriftFixtures::phpGraph([['find', []]], [DriftFixtures::stub($stub)], extra: [$ancestor], extraEdges: [
                DriftFixtures::edge($edge, DriftFixtures::phpType('class', 'App\UserService'), $ancestor->localId),
            ]));
        };

        assertSame([], $judge('extends', 'class', ['member_names' => ['magicCount']]), "A parent's @method name.");
        assertSame([], $judge('extends', 'class', ['member_names' => ['__call'], 'member_names_open' => true]), 'A parent that declares __call().');
        assertSame([], $judge('uses_trait', 'trait', ['member_names' => ['__call'], 'member_names_open' => true]), 'A trait that declares __call().');
        assertSame([], $judge('implements', 'interface', ['member_names' => ['magicCount']]), "An interface's @method name.");
        assertSame([], $judge('extends', 'class', []), 'A parent that sends no list leaves the whole incomplete.');
        assertSame(['createMock stubs gone, which UserService no longer has'], array_map(static fn($finding): string => $finding->message, $judge('extends', 'class', ['member_names' => ['magicCount']], 'gone')), 'A name no complete list in the lineage holds is still a ghost.');
    }

    public function testANameAFurtherTypeOfTheDoubleMayOfferIsNotAGhost(): void
    {
        // `Mockery::mock(UserService::class, Second::class)`: the double is both.
        $second = DriftFixtures::phpType('interface', 'App\Second', ['member_names' => ['second']], 'src/Second.php');
        $judge = static fn(string $stub, array $double): array => self::judge(DriftFixtures::phpGraph([['find', []]], [DriftFixtures::stub($stub)], ['framework' => 'mockery', ...$double], extra: [$second]));

        assertSame([], $judge('second', ['also_implements' => ['App\Second']]));
        assertSame([], $judge('SECOND', ['also_implements' => ['app\second']]), 'In any case.');
        assertSame([], $judge('third', ['also_implements' => ['Psr\Log\LoggerInterface']]), 'A further type the scan does not hold may have it.');
        assertSame([], $judge('third', ['also_implements_open' => true]), 'A further type the worker could not name may have it.');
        assertSame([], $judge('third', ['also_implements' => 'App\Second']), 'A malformed list leaves it unknown.');
        assertCount(1, $judge('third', ['also_implements' => ['App\Second']]), 'A name no type of the double has is a ghost.');
        assertCount(1, $judge('third', []));
    }

    public function testANamePhpunitsAddMethodsAddsIsNotAGhost(): void
    {
        $added = ['name' => 'extra', 'line' => 7, 'api' => 'addMethods', 'returns' => []];

        assertSame([], self::judge(DriftFixtures::phpGraph([['find', []]], [$added], ['pattern' => 'getMockBuilder'])));
        assertCount(1, self::judge(DriftFixtures::phpGraph([['find', []]], [['api' => 'method'] + $added], ['pattern' => 'getMockBuilder'])));
    }

    public function testATypeScriptMemberMatchesOnlyInItsOwnCase(): void
    {
        assertCount(1, self::judge(DriftFixtures::graph([DriftFixtures::method('total')], [DriftFixtures::stub('Total')])));
    }

    /**
     * The ghosts on a Python double of `app.repo.Repo` (methods `run`), stubbing `$stubbed`, the class extending `$bases`.
     *
     * @param list<string> $stubbed
     * @param list<string> $bases references the class's `extends` edges name
     * @param list<NodeFact> $extra
     * @param array<string, mixed>|null $classAttributes
     * @return list<string> each ghost's sub-rule, confidence and stubbed name
     */
    private static function python(array $stubbed, array $bases = [], array $extra = [], ?array $classAttributes = null, string $kind = 'class'): array
    {
        $owner = PythonFixtures::node($kind, $kind === 'module' ? 'app.repo' : 'app.repo.Repo');
        $graph = PythonFixtures::graph(
            [['run', []]],
            array_map(static fn(string $name): array => PythonFixtures::stub($name), $stubbed),
            ownerAttributes: $classAttributes,
            extra: $extra,
            extraEdges: array_map(static fn(string $base): \Knossos\Scanner\Protocol\EdgeFact => PythonFixtures::edge('extends', $owner, $base), $bases),
            kind: $kind,
        );

        return array_map(static fn($finding): string => $finding->subRule . ' ' . $finding->confidence->value . ' ' . $finding->attributes['member'], self::judge($graph));
    }

    public function testPythonDunderIsNeverAGhost(): void
    {
        // Ruling P3: no worker lists a dunder, and the runtime or `MagicMock` answers one; a class and a module alike.
        assertSame(['missing_member definite rnu'], self::python(['__enter__', '__call__', 'rnu']));
        assertSame(['missing_export definite rnu'], self::python(['__version__', '__getattr__', 'rnu'], kind: 'module'));
        // A name that only starts with two underscores is no dunder.
        assertSame(['missing_member definite __rnu'], self::python(['__rnu']));
    }

    public function testADunderIsJudgedOutsidePython(): void
    {
        // The skip is Python's alone: a TypeScript or PHP name spelled `__x__` keeps its ghost.
        $typescript = DriftFixtures::graph([DriftFixtures::method('save')], [DriftFixtures::stub('__init__')]);
        $php = DriftFixtures::phpGraph([['save', []]], [DriftFixtures::stub('__init__')]);

        assertSame(['missing_member'], array_map(static fn($finding): string => $finding->subRule, self::judge($typescript)));
        assertSame(['missing_member'], array_map(static fn($finding): string => $finding->subRule, self::judge($php)));
    }

    public function testPythonAbcBaseKeepsTheListComplete(): void
    {
        // Ruling P8: `from abc import ABC` and `abc.ABC` name the same base, and so do typing's two spellings.
        foreach (['py:external_symbol:abc.ABC', 'py:class:abc.ABC', 'py:external_symbol:typing.Protocol', 'py:class:typing.Protocol', 'py:external_symbol:typing.Generic'] as $base) {
            assertSame([$base, ['missing_member definite rnu']], [$base, self::python(['rnu'], [$base])]);
        }
        // What `ABCMeta` gives the class is no ghost; the protocol flags only a Protocol has.
        assertSame([], self::python(['register', '_abc_impl'], ['py:class:abc.ABC']));
        assertSame([], self::python(['_is_protocol'], ['py:external_symbol:typing.Protocol']));
        assertSame(['missing_member definite _is_runtime_protocol'], self::python(['_is_runtime_protocol'], ['py:external_symbol:abc.ABC']));
    }

    public function testPythonClassesAnswerTypesMro(): void
    {
        // Review M1: `type.mro` is the one name every class answers that its body never binds; a module has none.
        assertSame([], self::python(['mro']));
        assertSame([], self::python(['mro'], ['py:class:abc.ABC']));
        assertSame(['missing_export definite mro'], self::python(['mro'], kind: 'module'));
    }

    public function testPythonExternalAncestorsAreUnseen(): void
    {
        // Carried item (a): every other base outside the project may give names the scan cannot see.
        $cases = [
            'py:external_symbol:enum.Enum' => ['name', 'value', '_value_'],
            'py:class:enum.Enum' => ['name'],
            'py:external_symbol:typing.NamedTuple' => ['_replace', '_asdict'],
            'py:external_symbol:typing.TypedDict' => ['keys'],
            'py:external_symbol:typing_extensions.Protocol' => ['rnu'],
            'py:class:app.repo.Missing' => ['rnu'],
        ];
        foreach ($cases as $base => $stubbed) {
            assertSame([$base, []], [$base, self::python($stubbed, [$base])]);
        }
        // Behind a known base, an unseen one still opens the list.
        assertSame([], self::python(['name'], ['py:class:abc.ABC', 'py:external_symbol:enum.Enum']));
    }

    public function testPythonOpenParentIsSilent(): void
    {
        // Carried item (b): an ancestor open by a metaclass, `__init_subclass__` or a dynamic base opens its subclasses, however closed.
        $base = PythonFixtures::node('class', 'app.repo.Base', ['member_names' => ['helper'], 'member_names_open' => true]);
        assertSame([], self::python(['rnu'], ['py:class:app.repo.Base'], [$base]));

        $grand = PythonFixtures::node('class', 'app.repo.Grand', ['member_names' => [], 'member_names_open' => true]);
        $middle = PythonFixtures::node('class', 'app.repo.Middle', ['member_names' => ['helper']]);
        $edges = [PythonFixtures::edge('extends', $middle, 'py:class:app.repo.Grand')];
        $graph = PythonFixtures::graph([['run', []]], [PythonFixtures::stub('rnu')], extra: [$grand, $middle], extraEdges: [PythonFixtures::edge('extends', PythonFixtures::node('class', 'app.repo.Repo'), 'py:class:app.repo.Middle'), ...$edges]);
        assertSame([], self::judge($graph));

        // A closed parent keeps the list complete, and its names count as the child's.
        $closed = PythonFixtures::node('class', 'app.repo.Base', ['member_names' => ['helper']]);
        assertSame(['missing_member definite rnu'], self::python(['helper', 'rnu'], ['py:class:app.repo.Base'], [$closed]));
    }
}

<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\TestFidelity\Rules\ArityMismatchRule;
use Knossos\TestFidelity\Rules\GhostMemberRule;
use Knossos\TestFidelity\Rules\TautologyRule;
use Knossos\TestFidelity\TestFinding;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\TestFidelity\Rules\CaseFixtures;
use Knossos\Tests\Phpunit\TestFidelity\Rules\PythonFixtures;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * The user's decision U1 (2026-11-27): a Python ghost, arity or constant that
 * rests on a write the worker sees but does not follow (G4-02, G4-05, G4-08)
 * or on a base outside the project that may rewrite signatures (G4-12) is a
 * warning, naming the pattern as its `residual`. The same finding on a target
 * with none of them stays definite.
 */
#[Group('test-fidelity')]
final class PythonUnfollowedWritesTest extends KnossosTestCase
{
    public function testAGhostOnAClassWrittenInAWayNotFollowedIsAWarning(): void
    {
        assertSame(['missing_member definite'], self::ghosts(['member_names' => ['run']]));
        assertSame(['missing_member warning self_alias'], self::ghosts(['member_names' => ['run'], 'unfollowed_writes' => ['self_alias']]));
        // A malformed list names no pattern.
        assertSame(['missing_member definite'], self::ghosts(['member_names' => ['run'], 'unfollowed_writes' => 'self_alias']));
        assertSame(['missing_member definite'], self::ghosts(['member_names' => ['run'], 'unfollowed_writes' => [7, '']]));
    }

    public function testAGhostOnASubclassOfAClassWrittenInAWayNotFollowedIsAWarning(): void
    {
        $owner = PythonFixtures::node('class', 'app.repo.Repo');
        $base = PythonFixtures::node('class', 'app.repo.Base', ['member_names' => [], 'unfollowed_writes' => ['default_holder', 'instance_write']]);
        $graph = PythonFixtures::graph([['run', []]], [PythonFixtures::stub('missing')], extra: [$base], extraEdges: [PythonFixtures::edge('extends', $owner, $base->localId)]);

        assertSame(['missing_member warning default_holder'], self::described((new GhostMemberRule())->findings($graph)));
    }

    public function testAGhostOnAModuleThatWritesItselfIsAWarning(): void
    {
        assertSame(['missing_export definite'], self::ghosts(['exports' => ['run']], 'module'));
        assertSame(['missing_export warning module_self_write'], self::ghosts(['exports' => ['run'], 'unfollowed_writes' => ['module_self_write']], 'module'));
    }

    public function testAnInstanceWrittenFromAnotherFileKeepsTheListAndWarns(): void
    {
        $writer = PythonFixtures::node('module', 'app.boot', ['foreign_writes' => [['target' => 'py:class:app.repo.Repo', 'name' => 'missing', 'instance' => true]]], 'src/app/boot.py');
        $graph = PythonFixtures::graph([['run', []]], [PythonFixtures::stub('missing')], extra: [$writer]);

        assertSame(['missing_member warning instance_write'], self::described((new GhostMemberRule())->findings($graph)));
    }

    public function testAnArityMismatchOnAClassWrittenInAWayNotFollowedIsAWarning(): void
    {
        assertSame(['call_arity definite'], self::arity([]));
        assertSame(['call_arity warning self_alias'], self::arity(['unfollowed_writes' => ['self_alias']]));
    }

    public function testAnArityMismatchUnderABaseThatMayRewriteSignaturesIsAWarning(): void
    {
        assertSame(['call_arity warning external_base'], self::arity([], 'py:external_symbol:framework.Model'));
        assertSame(['call_arity warning external_base'], self::arity([], 'py:class:framework.Model'));
        // A base of the standard library keeps the signatures its subclasses' defs write (Fix round 1).
        foreach (['py:external_symbol:logging.Handler', 'py:class:threading.Thread', 'py:external_symbol:json.JSONEncoder', 'py:external_symbol:collections.abc.Mapping'] as $base) {
            assertSame([$base, ['call_arity definite']], [$base, self::arity([], $base)]);
        }
        // A builtin, a known base, `enum`'s and Django's keep the signature a def writes.
        foreach (['py:class:Exception', 'py:external_symbol:builtins.dict', 'py:external_symbol:abc.ABC', 'py:class:enum.Enum', 'py:external_symbol:django.db.models.Model'] as $base) {
            assertSame([$base, ['call_arity definite']], [$base, self::arity([], $base)]);
        }
    }

    public function testAModuleReExportingOneOutsideTheProjectKeepsItsArity(): void
    {
        $owner = PythonFixtures::node('module', 'app.repo');
        $graph = PythonFixtures::graph(
            [['save', PythonFixtures::signature([['name' => 'item', 'type' => 'int']])]],
            [PythonFixtures::stub('save', calledWith: [PythonFixtures::matcher(2)])],
            ownerAttributes: ['exports' => ['save']],
            extraEdges: [PythonFixtures::edge('re_exports', $owner, 'py:module:vendored.lib')],
            kind: 'module',
        );

        assertSame(['call_arity definite'], self::described((new ArityMismatchRule())->findings($graph)));
    }

    public function testAConstantALocalHoldsIsAWarningWhereProductionWritesFrames(): void
    {
        $local = CaseFixtures::assertion('assert ==', [CaseFixtures::operand('literal', ['text' => 'x', 'value' => '1']), CaseFixtures::operand('literal', ['text' => '1'])]);
        $written = CaseFixtures::assertion('assert ==', [CaseFixtures::operand('literal', ['text' => '2']), CaseFixtures::operand('literal', ['text' => '3'])]);

        assertSame(['constant definite'], self::constants([$local], null));
        // The test calls into the module that writes frames, or its file imports it.
        assertSame(['constant warning frame_write'], self::constants([$local], 'src/app/frames.py', subjects: ['src/app/frames.py']));
        assertSame(['constant warning frame_write'], self::constants([$local], 'src/app/frames.py', imported: true));
        // A test that neither calls into nor imports it keeps its constant (Fix round 1).
        assertSame(['constant definite'], self::constants([$local], 'src/app/frames.py'));
        // A constant written out is no local a frame write can change; the test's own frame access is the worker's to see.
        assertSame(['constant definite'], self::constants([$written], 'src/app/frames.py', subjects: ['src/app/frames.py']));
        assertSame(['constant definite'], self::constants([$local], 'tests/test_frames.py', subjects: ['tests/test_frames.py']));
    }

    /**
     * The ghosts a double stubbing `missing` gives on a class (or module) with these attributes.
     *
     * @param array<string, mixed> $attributes
     * @return list<string>
     */
    private static function ghosts(array $attributes, string $kind = 'class'): array
    {
        return self::described((new GhostMemberRule())->findings(PythonFixtures::graph([['run', []]], [PythonFixtures::stub('missing')], ownerAttributes: $attributes, kind: $kind)));
    }

    /**
     * The arity findings of a matcher of two arguments on `save(item)`, the class carrying these attributes and, when given, a base.
     *
     * @param array<string, mixed> $attributes
     * @return list<string>
     */
    private static function arity(array $attributes, ?string $base = null): array
    {
        $owner = PythonFixtures::node('class', 'app.repo.Repo');
        $graph = PythonFixtures::graph(
            [['save', PythonFixtures::signature([['name' => 'item', 'type' => 'int']])]],
            [PythonFixtures::stub('save', calledWith: [PythonFixtures::matcher(2)])],
            ownerAttributes: ['member_names' => ['save'], ...$attributes],
            extraEdges: $base === null ? [] : [PythonFixtures::edge('extends', $owner, $base)],
        );

        return self::described((new ArityMismatchRule())->findings($graph));
    }

    /**
     * The tautology findings of a Python test case, with a module writing frames at `$frames` when given,
     * which the case calls into when `$subjects` names it, or its file imports.
     *
     * @param list<array<string, mixed>> $assertions
     * @param list<string> $subjects
     * @return list<string>
     */
    private static function constants(array $assertions, ?string $frames, array $subjects = [], bool $imported = false): array
    {
        $case = PythonFixtures::node('test_case', 'tests/test_repo.py#test:10', ['name' => 'test_it', 'production_calls' => 1, 'subjects' => $subjects, 'assertions' => $assertions], 'tests/test_repo.py');
        $production = $frames === null ? [] : [PythonFixtures::node('module', 'app.frames', ['exports' => [], 'unfollowed_writes' => ['frame_write']], $frames)];
        $module = PythonFixtures::node('module', 'tests.test_repo', [], 'tests/test_repo.py');
        $imports = $imported ? [PythonFixtures::edge('imports', $module, 'py:module:app.frames')] : [];

        return self::described((new TautologyRule())->findings(CaseFixtures::graph([$case, $module], $production, $imports)));
    }

    /**
     * Each finding's sub-rule, confidence and residual.
     *
     * @param iterable<TestFinding> $findings
     * @return list<string>
     */
    private static function described(iterable $findings): array
    {
        $described = [];
        foreach ($findings as $finding) {
            $residual = $finding->attributes['residual'] ?? null;
            $described[] = trim(sprintf('%s %s %s', $finding->subRule, $finding->confidence->value, is_string($residual) ? $residual : ''));
        }

        return $described;
    }

}

<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Rules;

use Knossos\TestFidelity\FindingConfidence;
use Knossos\TestFidelity\Rules\ReturnDriftRule;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

#[Group('test-fidelity')]
final class ReturnDriftRuleTest extends KnossosTestCase
{
    /** @return list<\Knossos\TestFidelity\TestFinding> */
    private static function judge(bool|string $assignable, bool $typed, ?string $returns = 'number'): array
    {
        $signature = ['params' => []] + ($returns === null ? [] : ['returns' => $returns]);
        $graph = DriftFixtures::graph(
            [DriftFixtures::method('total', ['signature' => $signature])],
            [DriftFixtures::stub('total', null, [['api' => 'mockReturnValue', 'line' => 9, 'once' => false, 'assignable' => $assignable, 'typed' => $typed]])],
        );

        return [...(new ReturnDriftRule())->findings($graph)];
    }

    public function testAssignableFalseAndTypedIsDefinite(): void
    {
        $findings = self::judge(false, true);

        assertCount(1, $findings);
        assertSame(FindingConfidence::Definite, $findings[0]->confidence);
        assertSame('total returns number; the double pins a value it does not admit (mockReturnValue)', $findings[0]->message);
        assertSame(9, $findings[0]->evidence->startLine);
    }

    public function testACompileCheckedDoubleIsNeverJudged(): void
    {
        $methods = [DriftFixtures::method('total', ['signature' => ['params' => [], 'returns' => 'number']])];
        $members = [DriftFixtures::stub('total', null, [['api' => 'mockReturnValue', 'line' => 9, 'once' => false, 'assignable' => false, 'typed' => true]])];

        assertCount(1, [...(new ReturnDriftRule())->findings(DriftFixtures::graph($methods, $members))], 'the control');
        assertSame([], [...(new ReturnDriftRule())->findings(DriftFixtures::graph($methods, $members, doubleAttributes: ['compile_checked' => true]))]);
    }

    public function testAssignableUnknownOrTrueIsSilent(): void
    {
        assertSame([], self::judge('unknown', true));
        assertSame([], self::judge(true, true));
    }

    public function testAnOverloadedMemberIsSilent(): void
    {
        $graph = DriftFixtures::graph(
            [DriftFixtures::method('total', ['signature' => ['params' => [], 'returns' => 'number'], 'overloaded' => true])],
            [DriftFixtures::stub('total', null, [['api' => 'mockReturnValue', 'line' => 9, 'once' => false, 'assignable' => false, 'typed' => true]])],
        );

        assertSame([], [...(new ReturnDriftRule())->findings($graph)]);
    }

    public function testATruncatedOrMissingSignatureIsSilent(): void
    {
        $pinned = [['api' => 'mockReturnValue', 'line' => 9, 'once' => false, 'assignable' => false, 'typed' => false]];
        foreach ([['signature' => ['params' => [], 'truncated' => true]], []] as $attributes) {
            $graph = DriftFixtures::graph([DriftFixtures::method('total', $attributes)], [DriftFixtures::stub('total', null, $pinned)]);

            assertSame([], [...(new ReturnDriftRule())->findings($graph)]);
        }
    }

    public function testUntypedIsAWarning(): void
    {
        assertSame(FindingConfidence::Warning, self::judge(false, false, null)[0]->confidence);
        assertSame('total returns an inferred type; the double pins a value it does not admit (mockReturnValue)', self::judge(false, false, null)[0]->message);
    }

    /**
     * A PHP stub of `App\UserService::total()` pinned to one value of `$type`, as the PHP worker emits it.
     *
     * @return list<\Knossos\TestFidelity\TestFinding>
     */
    private static function judgePhp(string $type, ?string $returns, bool $typed = true, string $framework = 'phpunit'): array
    {
        $signature = ['params' => []] + ($returns === null ? [] : ['returns' => $returns]);
        $graph = DriftFixtures::phpGraph(
            [['total', ['signature' => $signature, 'visibility' => 'public']], ['make', ['signature' => ['params' => [], 'returns' => 'self']]]],
            [DriftFixtures::stub('total', null, [['api' => 'willReturn', 'line' => 9, 'once' => false, 'assignable' => 'unknown', 'typed' => $typed, 'type' => $type]])],
            ['framework' => $framework],
        );

        return [...(new ReturnDriftRule())->findings($graph)];
    }

    public function testPhpStringPinOnIntReturnIsDefinite(): void
    {
        $findings = self::judgePhp('string', 'int');

        assertCount(1, $findings);
        assertSame(FindingConfidence::Definite, $findings[0]->confidence);
        assertSame('total returns int; the double pins a value it does not admit (willReturn)', $findings[0]->message);
        assertSame(9, $findings[0]->evidence->startLine);
    }

    public function testAPhpPinThatFitsOrCannotBeJudgedIsSilent(): void
    {
        assertSame([], self::judgePhp('int', 'float'));
        assertSame([], self::judgePhp('null', '?int'));
        assertSame([], self::judgePhp('string', 'mixed'));
        assertSame([], self::judgePhp('string', null), 'No declared return type: nothing to judge against.');
        assertSame([], self::judgePhp('unknown', 'int', false));
        assertSame([], self::judgePhp('resource', 'int'), 'A type outside the list is unknown.');
    }

    public function testAPhpPinOnAVendorTypeIsAWarning(): void
    {
        $findings = self::judgePhp('class:App\\UserService', 'Psr\\Log\\LoggerInterface');

        assertCount(1, $findings);
        assertSame(FindingConfidence::Warning, $findings[0]->confidence);
    }

    public function testSelfInAPhpReturnTypeIsTheDeclaringClass(): void
    {
        $pin = static fn(string $type): array => [DriftFixtures::stub('make', null, [['api' => 'willReturn', 'line' => 9, 'once' => false, 'assignable' => 'unknown', 'typed' => true, 'type' => $type]])];
        $methods = [['make', ['signature' => ['params' => [], 'returns' => 'self']]]];

        assertSame([], [...(new ReturnDriftRule())->findings(DriftFixtures::phpGraph($methods, $pin('class:App\\UserService')))]);
        assertCount(1, [...(new ReturnDriftRule())->findings(DriftFixtures::phpGraph($methods, $pin('int')))]);
    }

    public function testATypeScriptPinKeepsTheWorkersVerdict(): void
    {
        $graph = DriftFixtures::graph(
            [DriftFixtures::method('total', ['signature' => ['params' => [], 'returns' => 'number']])],
            [DriftFixtures::stub('total', null, [['api' => 'mockReturnValue', 'line' => 9, 'once' => false, 'assignable' => 'unknown', 'typed' => true, 'type' => 'string']])],
        );

        assertSame([], [...(new ReturnDriftRule())->findings($graph)]);
    }

    /**
     * A double of `App\C`, which uses trait `App\T`, stubbing `T::make(): $returns`, pinned to one value of `$type`.
     *
     * @return list<\Knossos\TestFidelity\TestFinding>
     */
    private static function judgeTraitMethod(string $returns, string $type, string $doubled = 'App\\C'): array
    {
        $class = DriftFixtures::phpType('class', 'App\\C', ['member_names' => []], 'src/C.php');
        $trait = DriftFixtures::phpType('trait', 'App\\T', ['member_names' => ['make']], 'src/T.php');
        $make = DriftFixtures::phpMethod($trait, 'make', ['signature' => ['params' => [], 'returns' => $returns], 'visibility' => 'public']);
        $double = DriftFixtures::phpType('test_double', 'tests/CTest.php#double:6:3', [
            'framework' => 'phpunit', 'pattern' => 'createMock', 'target' => ['kind' => 'class', 'export' => $doubled], 'automock' => false, 'invocations' => 0,
            'members' => [DriftFixtures::stub('make', null, [['api' => 'willReturn', 'line' => 9, 'once' => false, 'assignable' => 'unknown', 'typed' => true, 'type' => $type]])],
        ], 'tests/CTest.php');
        $graph = \Knossos\TestFidelity\TestFidelityGraph::fromContributions([
            new \Knossos\Scanner\Protocol\ScanContribution('knossos.php:file:src', [$class, $trait, $make], [DriftFixtures::edge('uses_trait', $class, 'php:trait:App\\T'), DriftFixtures::edge('contains', $trait, $make->localId)]),
            new \Knossos\Scanner\Protocol\ScanContribution('knossos.php:file:tests/CTest.php', [$double], [DriftFixtures::edge('doubles', $double, 'php:class:' . $doubled)]),
        ]);

        return [...(new ReturnDriftRule())->findings($graph)];
    }

    public function testSelfOrStaticInATraitIsTheDoubledClass(): void
    {
        // `trait T { function make(): self }`, `class C { use T; }`: `createMock(C::class)->method('make')->willReturn(new C())` is healthy.
        assertSame([], self::judgeTraitMethod('self', 'class:App\\C'));
        assertSame([], self::judgeTraitMethod('static', 'class:App\\C'));
        assertSame([], self::judgeTraitMethod('?static', 'null'));
        assertCount(1, self::judgeTraitMethod('self', 'int'), 'Resolved to the doubled class, a wrong pin is still refused.');
        assertSame([], self::judgeTraitMethod('self', 'class:App\\C', 'App\\T'), 'A double of the trait itself: what self is, nothing says.');
    }

    public function testAMockeryPinThePhpCoercionMayAdmitIsSilent(): void
    {
        assertSame([], self::judgePhp('null', 'void', framework: 'mockery'), 'Mockery drops what a void method is given.');
        assertSame([], self::judgePhp('string', 'int', framework: 'mockery'), "Mockery's code is not strict: '5' is coerced.");
        assertSame([], self::judgePhp('string', 'int', framework: 'prophecy'));
        assertCount(1, self::judgePhp('array', 'int', framework: 'mockery'));
        assertSame(FindingConfidence::Definite, self::judgePhp('null', 'void')[0]->confidence, 'PHPUnit refuses null on void.');
    }

    public function testTheDoubleItselfPinnedOnVoidIsAWarning(): void
    {
        // Ruling R18: the void template returns nothing, so neither framework fails on `willReturnSelf()`.
        $self = static fn(string $api, string $returns, string $framework = 'phpunit'): array => [...(new ReturnDriftRule())->findings(DriftFixtures::phpGraph(
            [['total', ['signature' => ['params' => [], 'returns' => $returns]]]],
            [DriftFixtures::stub('total', null, [['api' => $api, 'line' => 9, 'once' => false, 'assignable' => 'unknown', 'typed' => true, 'type' => 'class:App\\UserService']])],
            ['framework' => $framework],
        ))];

        assertSame([FindingConfidence::Warning], array_map(static fn($finding) => $finding->confidence, $self('willReturnSelf', 'void')));
        assertSame([FindingConfidence::Warning], array_map(static fn($finding) => $finding->confidence, $self('andReturnSelf', 'void', 'mockery')));
        assertSame([FindingConfidence::Definite], array_map(static fn($finding) => $finding->confidence, $self('willReturn', 'void')), 'A stated value on void stays definite.');
        assertSame([FindingConfidence::Definite], array_map(static fn($finding) => $finding->confidence, $self('willReturnSelf', 'int')), 'The double itself is no int.');
    }

    /**
     * Each finding of one Python double pinning `$pins` on `Repo.total`, its confidence and message.
     *
     * @param list<array<string, mixed>> $pins
     * @param array<string, mixed> $attributes further attributes of the member node
     * @param array<string, mixed>|null $classAttributes the class's attributes; by default its complete name list
     * @return list<string>
     */
    private static function python(array $pins, ?string $returns = 'int', array $attributes = [], ?array $classAttributes = null): array
    {
        $graph = PythonFixtures::graph([['total', PythonFixtures::signature([], $returns) + $attributes]], [PythonFixtures::stub('total', $pins)], ownerAttributes: $classAttributes);

        return array_map(static fn($finding): string => $finding->confidence->value . ' ' . $finding->message, [...(new ReturnDriftRule())->findings($graph)]);
    }

    public function testPythonRefusalIsAWarning(): void
    {
        // Python never enforces an annotation, and a mock returns what it is given (the user's decision).
        assertSame(['warning total returns int; the double pins a value it does not admit (return_value)'], self::python([PythonFixtures::pin('str')]));
        assertSame(['warning total returns int; the double pins a value it does not admit (return_value)'], self::python([['api' => 'return_value', 'line' => 8, 'once' => false, 'assignable' => false, 'typed' => true]]));
        assertSame([], self::python([PythonFixtures::pin('int'), PythonFixtures::pin('bool', 'side_effect')]));
        assertSame([], self::python([PythonFixtures::pin('unknown')]));
        assertSame([], self::python([PythonFixtures::pin('str')], 'Iterator[int]'));
        assertSame([], self::python([PythonFixtures::pin('str')], null));
    }

    public function testPythonMemberOfARewritableClassIsSilent(): void
    {
        // Ruling P21: the method may not return what its annotation says once something has rewritten it.
        assertSame([], self::python([PythonFixtures::pin('str')], 'int', [], ['member_names' => ['total'], 'methods_rewritable' => true]));
        // The flag is Python's alone.
        $typescript = DriftFixtures::graph(
            [DriftFixtures::method('total', ['signature' => ['params' => [], 'returns' => 'number']])],
            [DriftFixtures::stub('total', null, [['api' => 'mockReturnValue', 'line' => 9, 'once' => false, 'assignable' => false, 'typed' => true]])],
            targetAttributes: ['member_names' => ['total'], 'methods_rewritable' => true],
        );
        assertCount(1, [...(new ReturnDriftRule())->findings($typescript)]);
    }

    public function testAPythonPinIsJudgedOnlyAgainstTheDefThatCertainlyAnswers(): void
    {
        // Ruling P24: `class Repo(Base): total = _other` shadows Base's `total() -> int`; a subclass that does not keeps it judged.
        $judge = static function (array $ownNames): array {
            $owner = PythonFixtures::node('class', 'app.repo.Repo');
            $base = PythonFixtures::node('class', 'app.repo.Base', ['member_names' => ['total']]);
            $total = PythonFixtures::method($base, 'total', PythonFixtures::signature([], 'int'));
            $graph = PythonFixtures::graph([], [PythonFixtures::stub('total', [PythonFixtures::pin('str')])], ownerAttributes: ['member_names' => $ownNames], extra: [$base, $total], extraEdges: [
                PythonFixtures::edge('extends', $owner, $base->localId), PythonFixtures::edge('contains', $base, $total->localId),
            ]);

            return array_map(static fn(\Knossos\TestFidelity\TestFinding $finding): string => $finding->message, [...(new ReturnDriftRule())->findings($graph)]);
        };

        assertSame([], $judge(['total']));
        assertSame(['total returns int; the double pins a value it does not admit (return_value)'], $judge([]));
    }

    public function testPythonDecoratedMemberIsSilent(): void
    {
        assertSame([], self::python([PythonFixtures::pin('str')], 'int', ['decorated' => true]));
        assertSame([], self::python([PythonFixtures::pin('str')], 'int', ['overloaded' => true]));
    }

    public function testPythonReplacementIsNoReturnPin(): void
    {
        // `new=` replaces the member itself: a callable stands in for it, and nothing it returns is pinned.
        assertSame([], self::python([PythonFixtures::pin('str', 'new')]));
        assertSame(['warning total returns int; the double pins a value it does not admit (return_value)'], self::python([PythonFixtures::pin('str', 'new'), PythonFixtures::pin('str')]));
    }

    public function testAPythonMemberAModuleOnlyImportsIsNotJudged(): void
    {
        // `patch.multiple(m, f=…)` keeps `m` as the owner; `f` is m's import, no node of m's, so drift and arity are silent (carried item e).
        $graph = PythonFixtures::graph([['own', PythonFixtures::signature([], 'int')]], [PythonFixtures::stub('imported', [PythonFixtures::pin('str')], [PythonFixtures::matcher(9)])], kind: 'module', ownerAttributes: ['exports' => ['imported', 'own']]);

        assertSame([], [...(new ReturnDriftRule())->findings($graph)]);
        assertSame([], [...(new \Knossos\TestFidelity\Rules\ArityMismatchRule())->findings($graph)]);
    }
}

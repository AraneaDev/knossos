<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Rules;

use Knossos\TestFidelity\FindingConfidence;
use Knossos\TestFidelity\Rules\VisibilityBreachRule;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

#[Group('test-fidelity')]
final class VisibilityBreachRuleTest extends KnossosTestCase
{
    public function testATypeScriptPrivateMemberIsAWarning(): void
    {
        // `private` holds at compile time only: `vi.spyOn(s as any, 'hash')` replaces it at run time.
        $graph = DriftFixtures::graph([DriftFixtures::method('hash', ['visibility' => 'private'])], [DriftFixtures::stub('hash')]);

        $findings = [...(new VisibilityBreachRule())->findings($graph)];

        assertCount(1, $findings);
        assertSame(FindingConfidence::Warning, $findings[0]->confidence);
        assertSame('hash is private; the double bypasses the public interface', $findings[0]->message);
        assertSame(['member' => 'hash', 'visibility' => 'private'], $findings[0]->attributes);
    }

    public function testACompileCheckedDoubleIsNeverJudged(): void
    {
        $methods = [DriftFixtures::method('hash', ['visibility' => 'private'])];

        assertCount(1, [...(new VisibilityBreachRule())->findings(DriftFixtures::graph($methods, [DriftFixtures::stub('hash')]))], 'the control');
        assertSame([], [...(new VisibilityBreachRule())->findings(DriftFixtures::graph($methods, [DriftFixtures::stub('hash')], doubleAttributes: ['compile_checked' => true]))]);
    }

    public function testProtectedIsAWarningAndPublicIsNotReported(): void
    {
        $graph = DriftFixtures::graph(
            [DriftFixtures::method('a', ['visibility' => 'protected']), DriftFixtures::method('b')],
            [DriftFixtures::stub('a'), DriftFixtures::stub('b')],
        );

        $findings = [...(new VisibilityBreachRule())->findings($graph)];

        assertCount(1, $findings);
        assertSame(FindingConfidence::Warning, $findings[0]->confidence);
        assertSame('a is protected; the double bypasses the public interface', $findings[0]->message);
    }

    public function testAnEcmaScriptPrivateMemberIsDefinite(): void
    {
        // A `#name` member is no property: no double can reach it. The worker
        // lists it in `private_names`, without a node of its own.
        $graph = DriftFixtures::graph([], [DriftFixtures::stub('#hash')], targetAttributes: ['member_names' => ['#hash', 'open'], 'private_names' => ['#hash']]);

        $findings = [...(new VisibilityBreachRule())->findings($graph)];

        assertCount(1, $findings);
        assertSame(FindingConfidence::Definite, $findings[0]->confidence);
        assertSame('#hash is private; a double cannot replace it', $findings[0]->message);
        assertSame(['member' => '#hash', 'visibility' => 'private'], $findings[0]->attributes);
    }

    public function testAMemberNamedByTheStringHashNameIsPublic(): void
    {
        // G1-27: `'#x'(): number` is a public method spelled like a private one,
        // with a node of its own and in the member list, but not in `private_names`.
        $quoted = DriftFixtures::graph([DriftFixtures::method('#x')], [DriftFixtures::stub('#x')], targetAttributes: ['member_names' => ['#hidden', '#x'], 'private_names' => ['#hidden']]);
        $unlisted = DriftFixtures::graph([], [DriftFixtures::stub('#c')], targetAttributes: ['member_names' => ['#c']]);
        $listedElsewhere = DriftFixtures::graph([], [DriftFixtures::stub('#c')], targetAttributes: ['member_names' => ['#c'], 'private_names' => 'not a list']);

        assertSame([], [...(new VisibilityBreachRule())->findings($quoted)]);
        assertSame([], [...(new VisibilityBreachRule())->findings($unlisted)]);
        assertSame([], [...(new VisibilityBreachRule())->findings($listedElsewhere)]);
    }

    public function testOnlyAHashNameIsEcmaScriptPrivate(): void
    {
        // A list naming a member without `#` makes no breach of it.
        $graph = DriftFixtures::graph([DriftFixtures::method('open')], [DriftFixtures::stub('open')], targetAttributes: ['member_names' => ['open'], 'private_names' => ['open']]);

        assertSame([], [...(new VisibilityBreachRule())->findings($graph)]);
    }

    public function testAStubbedNameOfNoMemberIsNoBreach(): void
    {
        assertSame([], [...(new VisibilityBreachRule())->findings(DriftFixtures::graph([], [DriftFixtures::stub('#gone')]))]);
    }

    /**
     * The rule's verdicts on a PHP double stubbing a method of each visibility.
     *
     * @param array<string, mixed> $double overrides of the double's attributes
     * @return list<array{string, FindingConfidence}>
     */
    private static function judgePhp(array $double = []): array
    {
        $graph = DriftFixtures::phpGraph(
            [['hash', ['visibility' => 'private']], ['load', ['visibility' => 'protected']], ['find', ['visibility' => 'public']]],
            [DriftFixtures::stub('hash'), DriftFixtures::stub('load'), DriftFixtures::stub('find')],
            $double,
        );

        return array_map(static fn($finding): array => [$finding->message, $finding->confidence], [...(new VisibilityBreachRule())->findings($graph)]);
    }

    public function testPhpPrivateAndProtectedAreDefinite(): void
    {
        // PHP visibility holds at run time: a double cannot replace a private method, nor a protected one unless it opted in.
        assertSame([
            ['hash is private; the double bypasses the public interface', FindingConfidence::Definite],
            ['load is protected; the double bypasses the public interface', FindingConfidence::Definite],
        ], self::judgePhp());
    }

    public function testPhpProtectedWithAllowsProtectedIsSilent(): void
    {
        $private = [['hash is private; the double bypasses the public interface', FindingConfidence::Definite]];

        assertSame($private, self::judgePhp(['allows_protected' => true]), 'shouldAllowMockingProtectedMethods() reaches protected methods, never private ones.');
        assertSame($private, self::judgePhp(['pattern' => 'getMockBuilder']), 'A getMockBuilder() double reaches protected methods.');
    }

    public function testAPhpunitDoubleOtherThanCreateMockReachesProtectedMethods(): void
    {
        // createPartialMock() is onlyMethods() underneath, which takes protected methods; so do the abstract and trait doubles.
        $private = [['hash is private; the double bypasses the public interface', FindingConfidence::Definite]];

        foreach (['createPartialMock', 'getMockForAbstractClass', 'getMockForTrait', 'createConfiguredMock'] as $pattern) {
            assertSame($private, self::judgePhp(['pattern' => $pattern]), $pattern);
        }
        assertCount(2, self::judgePhp(['pattern' => 'createStub']));
        assertCount(2, self::judgePhp(['framework' => 'mockery', 'pattern' => 'Mockery::mock']), 'Mockery reaches a protected method only when allowed.');
    }

    public function testCreateMockOfAnAbstractProtectedMethodIsSilent(): void
    {
        $graph = DriftFixtures::phpGraph([['load', ['visibility' => 'protected', 'abstract' => true]], ['save', ['visibility' => 'protected', 'abstract' => false]]], [DriftFixtures::stub('load'), DriftFixtures::stub('save')]);

        assertSame(['save is protected; the double bypasses the public interface'], array_map(static fn($finding): string => $finding->message, [...(new VisibilityBreachRule())->findings($graph)]));
    }

    public function testAVisibilityATraitUseChangesIsUnknown(): void
    {
        // `class UserService { use Loads { load as public; } }`: the trait's protected load is public here, under any case of its name.
        $trait = DriftFixtures::phpType('trait', 'App\\Loads', ['member_names' => ['load', 'keep']], 'src/Loads.php');
        $load = DriftFixtures::phpMethod($trait, 'load', ['visibility' => 'protected']);
        $keep = DriftFixtures::phpMethod($trait, 'keep', ['visibility' => 'protected']);
        $use = new \Knossos\Scanner\Protocol\EdgeFact('uses_trait', 'php:class:App\\UserService', 'php:trait:App\\Loads', \Knossos\Scanner\Protocol\Origin::Ast, \Knossos\Scanner\Protocol\Confidence::Certain, $trait->evidence, ['visibility_changed' => ['LOAD']]);
        $graph = DriftFixtures::phpGraph([], [DriftFixtures::stub('Load'), DriftFixtures::stub('keep')], targetAttributes: ['member_names' => []], extra: [$trait, $load, $keep], extraEdges: [$use, DriftFixtures::edge('contains', $trait, $load->localId), DriftFixtures::edge('contains', $trait, $keep->localId)]);

        assertSame(['keep is protected; the double bypasses the public interface'], array_map(static fn($finding): string => $finding->message, [...(new VisibilityBreachRule())->findings($graph)]));
    }

    /**
     * Each finding on a Python double of `Repo._guard` (protected) and `Repo.__secret` (private), confidence and message.
     *
     * @param list<string> $stubbed
     * @param array<string, mixed>|null $classAttributes
     * @param list<\Knossos\Scanner\Protocol\EdgeFact> $edges
     * @return list<string>
     */
    private static function python(array $stubbed, ?array $classAttributes = null, array $edges = []): array
    {
        $graph = PythonFixtures::graph(
            [['_guard', ['visibility' => 'protected']], ['__secret', ['visibility' => 'private']], ['run', []]],
            array_map(static fn(string $name): array => PythonFixtures::stub($name), $stubbed),
            ownerAttributes: $classAttributes,
            extraEdges: $edges,
        );

        return array_map(static fn($finding): string => $finding->confidence->value . ' ' . $finding->message, [...(new VisibilityBreachRule())->findings($graph)]);
    }

    public function testPythonSingleUnderscoreIsAWarning(): void
    {
        assertSame(['warning _guard is protected; the double bypasses the public interface'], self::python(['_guard']));
        assertSame([], self::python(['run']));
    }

    public function testPythonMangledIsDefinite(): void
    {
        assertSame(['definite __secret is name-mangled; a double cannot replace it under that name'], self::python(['__secret']));
        // The mangled spelling names the member as Python stores it: no node, never reported.
        assertSame([], self::python(['_Repo__secret']));
    }

    public function testPythonMangledWithAnOpenOrUnseenLineageIsAWarning(): void
    {
        // A metaclass `__getattr__` (an open list) or a base outside the project may answer the name.
        $open = ['member_names' => ['_Repo__secret', '__secret', '_guard', 'run'], 'member_names_open' => true];
        assertSame(['warning __secret is name-mangled; a double cannot replace it under that name'], self::python(['__secret'], $open));
        $owner = PythonFixtures::node('class', 'app.repo.Repo');
        assertSame(['warning __secret is name-mangled; a double cannot replace it under that name'], self::python(['__secret'], edges: [PythonFixtures::edge('extends', $owner, 'py:external_symbol:enum.Enum')]));
        // `abc.ABC` is seen, and adds no name that could answer it.
        assertSame(['definite __secret is name-mangled; a double cannot replace it under that name'], self::python(['__secret'], edges: [PythonFixtures::edge('extends', $owner, 'py:class:abc.ABC')]));
    }
}

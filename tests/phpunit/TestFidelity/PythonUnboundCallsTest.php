<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\TestFidelity\Rules\ArityMismatchRule;
use Knossos\TestFidelity\TestFinding;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\TestFidelity\Rules\PythonFixtures;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * A Python method production calls through its class (`Repo.save(repo, x)`)
 * takes its receiver as an argument there, so a matcher or a `side_effect`
 * that lists it is no arity mismatch (G4-10). One argument more than that
 * still reports, and so does the receiver where production only calls the
 * method bound.
 */
#[Group('test-fidelity')]
final class PythonUnboundCallsTest extends KnossosTestCase
{
    public function testAMatcherListingTheReceiverOfAnUnboundCallIsNoMismatch(): void
    {
        assertSame([], self::matched(2, ['unbound' => true]));
        // One more than the receiver and the parameter is a mismatch however the method is called.
        assertSame(['call_arity'], self::matched(3, ['unbound' => true]));
        // Called only bound, the receiver is one argument too many.
        assertSame(['call_arity'], self::matched(2, []));
        assertSame(['call_arity'], self::matched(2, ['unbound' => 'yes']));
    }

    public function testATestsOwnUnboundCallMakesNoReceiverAnArgument(): void
    {
        assertSame(['call_arity'], self::matched(2, ['unbound' => true], 'tests/test_service.py'));
    }

    public function testASideEffectTakingTheReceiverOfAnUnboundCallIsNoMismatch(): void
    {
        $params = [['name' => 'repo', 'type' => 'Repo'], ['name' => 'item', 'type' => 'int']];

        assertSame([], self::replaced($params, ['unbound' => true]));
        assertSame(['signature'], self::replaced($params, []));
        assertSame(['signature'], self::replaced([...$params, ['name' => 'extra', 'type' => 'int']], ['unbound' => true]));
    }

    /**
     * The arity findings of a matcher of `$count` arguments on `save(item)`, with production calling it under `$attributes`.
     *
     * @param array<string, mixed> $attributes
     * @return list<string>
     */
    private static function matched(int $count, array $attributes, string $caller = 'src/app/service.py'): array
    {
        return self::findings([PythonFixtures::stub('save', calledWith: [PythonFixtures::matcher($count)])], $attributes, $caller);
    }

    /**
     * The arity findings of a `side_effect` taking `$params` on `save(item)`, with production calling it under `$attributes`.
     *
     * @param list<array<string, mixed>> $params
     * @param array<string, mixed> $attributes
     * @return list<string>
     */
    private static function replaced(array $params, array $attributes): array
    {
        return self::findings([PythonFixtures::stub('save', params: $params)], $attributes, 'src/app/service.py');
    }

    /**
     * @param list<array<string, mixed>> $members
     * @param array<string, mixed> $attributes
     * @return list<string>
     */
    private static function findings(array $members, array $attributes, string $caller): array
    {
        $function = new NodeFact('py:function:app.service.store', 'function', 'app.service.store', 'store', Origin::Ast, Confidence::Certain, new Evidence($caller, 1, 1));
        $call = new EdgeFact('calls', $function->localId, 'py:method:app.repo.Repo::save', Origin::Ast, Confidence::Certain, $function->evidence, $attributes);
        $graph = PythonFixtures::graph([['save', PythonFixtures::signature([['name' => 'item', 'type' => 'int']])]], $members, extra: [$function], extraEdges: [$call]);

        return array_map(static fn(TestFinding $finding): string => $finding->subRule, [...(new ArityMismatchRule())->findings($graph)]);
    }
}

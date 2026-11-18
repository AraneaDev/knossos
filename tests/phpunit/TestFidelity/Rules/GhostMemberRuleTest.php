<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Rules;

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

    public function testControlCharactersInANameDoNotReachTheMessage(): void
    {
        $graph = DriftFixtures::graph([], [DriftFixtures::stub("bad\n\x1bname")]);

        assertSame('vi.spyOn stubs bad name, which UserService no longer has', self::judge($graph)[0]->message);
    }
}

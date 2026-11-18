<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Rules;

use Knossos\Scanner\Protocol\NodeFact;
use Knossos\TestFidelity\FindingCategory;
use Knossos\TestFidelity\FindingConfidence;
use Knossos\TestFidelity\TestFidelityGraph;
use Knossos\TestFidelity\TestFinding;

/**
 * A double stubs a member, export or module that its target no longer has.
 *
 * Silent where the evidence runs out: an external target, a type with an
 * ancestor the scan cannot see, a member found through inheritance.
 */
final readonly class GhostMemberRule extends DriftRule
{
    public const ID = 'test.fidelity.ghost_member.v1';

    /** Edit distance at most which a present name is offered as the one meant. */
    private const SUGGESTION_DISTANCE = 2;

    /** Longest name considered for a suggestion. */
    private const SUGGESTION_LENGTH = 64;

    /** Files a project imports that are not code, so no node stands for them. */
    private const ASSET_EXTENSIONS = '/\.(?:css|scss|sass|less|json|svg|png|jpe?g|gif|webp|ico|md|html|txt|ya?ml|vue|svelte|astro)$/Di';

    /**
     * The stubbed members the target lacks, then the modules that resolve to nothing.
     *
     * @return iterable<TestFinding>
     */
    public function findings(TestFidelityGraph $graph): iterable
    {
        yield from parent::findings($graph);
        foreach ($graph->doubles() as $double) {
            $specifier = $double->target['specifier'] ?? '';
            if ($double->target['kind'] !== 'module' || $specifier === '' || $graph->target($double) !== null || $graph->isExternal($double) || preg_match(self::ASSET_EXTENSIONS, $specifier) === 1) {
                continue;
            }
            $finding = self::moduleFinding($graph->nodeOf($double), $specifier);
            if ($finding !== null) {
                yield $finding;
            }
        }
    }

    /** The finding for a double whose module specifier resolves to nothing. */
    private static function moduleFinding(NodeFact $node, string $specifier): ?TestFinding
    {
        $message = DriftText::clean(sprintf('%s replaces %s, which resolves to nothing in the project', $node->displayName, $specifier));

        return $message === '' ? null : new TestFinding($node->localId, null, FindingCategory::GhostMember, FindingConfidence::Definite, self::ID, 'missing_module', $message, $node->evidence, ['specifier' => DriftText::clean($specifier)]);
    }

    /** Reports a stub the target neither declares nor could inherit. */
    protected function judge(DriftPair $pair, TestFidelityGraph $graph): ?TestFinding
    {
        if ($pair->member !== null || $graph->hasUnseenAncestor($pair->target)) {
            return null;
        }
        $suggestion = self::suggestion($pair->stub->name, $graph->memberNames($pair->target));

        return $pair->finding(
            FindingCategory::GhostMember,
            FindingConfidence::Definite,
            self::ID,
            $pair->target->kind === 'module' ? 'missing_export' : 'missing_member',
            sprintf(
                '%s stubs %s, which %s no longer has%s',
                DriftText::clean($pair->doubleNode->displayName),
                DriftText::clean($pair->stub->name),
                DriftText::clean($pair->target->displayName),
                $suggestion === null ? '' : '; did you mean ' . DriftText::clean($suggestion) . '?',
            ),
            $suggestion === null ? [] : ['suggestion' => DriftText::clean($suggestion)],
        );
    }

    /**
     * The nearest present name within edit distance two, the alphabetically first on a tie.
     *
     * @param list<string> $names
     */
    private static function suggestion(string $missing, array $names): ?string
    {
        if (strlen($missing) > self::SUGGESTION_LENGTH) {
            return null;
        }
        $best = null;
        $bestDistance = self::SUGGESTION_DISTANCE + 1;
        foreach ($names as $name) {
            if (strlen($name) > self::SUGGESTION_LENGTH) {
                continue;
            }
            $distance = levenshtein($missing, $name);
            if ($distance > 0 && $distance < $bestDistance) {
                $best = $name;
                $bestDistance = $distance;
            }
        }

        return $best;
    }
}

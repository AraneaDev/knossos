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
 * Silent where the evidence runs out: an external target, a target whose worker
 * sent no complete name list, a type with an ancestor the scan cannot see or
 * whose ancestor's list is open or missing, a member found through
 * inheritance, a name a further type of the double may offer (Mockery's
 * `mock(A::class, B::class)`). A name PHPUnit's `addMethods()` adds is the
 * double's own and never a ghost. A PHP name is matched in any case, as PHP
 * matches method names.
 *
 * A Python dunder (`__x__`) is never judged, on a class or a module: it is a
 * protocol hook the runtime or `MagicMock` answers, and no worker lists one
 * (Ruling P3). A Python base outside the project is an unseen ancestor
 * (`enum.Enum`'s `name` and `value`, `NamedTuple`'s `_replace`, a
 * `TypedDict`), except `abc.ABC`, `typing.Protocol` and `typing.Generic`,
 * which add only their metaclass's names. Openness flows down: an ancestor
 * whose list is open (a metaclass, `__init_subclass__`, a dynamic base) leaves
 * every subclass open, however closed its own list.
 *
 * Accepted residual: a write from another file (`C.x = 1` in another module,
 * `m.FLAG = 1` from outside `m`) adds a name no worker sees, so a double of
 * that name reads as a ghost. The worker sees only writes in the class's or
 * the module's own file.
 */
final readonly class GhostMemberRule extends DriftRule
{
    public const ID = 'test.fidelity.ghost_member.v1';

    /** Edit distance at most which a present name is offered as the one meant. */
    private const SUGGESTION_DISTANCE = 2;

    /** Longest name considered for a suggestion. */
    private const SUGGESTION_LENGTH = 64;

    /** Files a project imports that are not code, so no node stands for them. */
    private const ASSET_EXTENSIONS = '/\.(?:css|scss|sass|less|json|svg|png|jpe?g|gif|webp|ico|md|mdx|html|txt|csv|ya?ml|vue|svelte|astro|graphql|gql|wasm|woff2?|ttf|otf|eot|mp3|mp4|webm|wav|ogg)$/Di';

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
            // A specifier with a loader query (`./w.ts?worker`, `./x.js?raw`) names no node; a double with an edge was resolved, to a node the scan may merely not hold (an excluded path).
            $path = explode('#', $specifier)[0];
            if ($double->target['kind'] !== 'module' || $specifier === '' || str_contains($specifier, '?') || $graph->hasTarget($double) || $graph->isExternal($double) || preg_match(self::ASSET_EXTENSIONS, $path) === 1) {
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
        // A name PHPUnit's `addMethods()` gives the double is the test's own, never the target's.
        if ($pair->stub->api === 'addMethods') {
            return null;
        }
        $python = TestFidelityGraph::isPython($pair->target);
        if ($python && preg_match('/^__.+__$/sD', $pair->stub->name) === 1) {
            return null;
        }
        $names = self::completeNames($pair->target, $graph);
        if ($names !== null && $python) {
            $names = [...$names, ...$graph->knownBaseNames($pair->target)];
        }
        if ($names === null || $pair->member !== null || self::listed($pair, $names) || $graph->hasUnseenAncestor($pair->target) || !self::absentFromAlso($pair, $graph)) {
            return null;
        }
        $suggestion = self::suggestion($pair->stub->name, $names);

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
     * Whether every further type the double implements (Mockery's
     * `mock(A::class, B::class)`) surely lacks the stubbed name too: each is
     * held by the scan inside the project, declares and inherits no member of
     * that name, and its complete name list leaves it out. A type the double
     * names but the reader could not read makes the absence unknown.
     */
    private static function absentFromAlso(DriftPair $pair, TestFidelityGraph $graph): bool
    {
        if ($pair->double->alsoImplements === null) {
            return false;
        }
        foreach ($pair->double->alsoImplements as $class) {
            $type = $graph->phpType($class);
            $names = $type === null ? null : self::completeNames($type, $graph);
            if ($type === null || $names === null || $graph->hasUnseenAncestor($type) || $graph->member($type, $pair->stub->name) !== null || in_array(strtolower($pair->stub->name), array_map(strtolower(...), $names), true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every name a target offers, when the workers said it is the complete list; null otherwise.
     *
     * A module lists its `exports`. A class, interface or trait lists its
     * `member_names` and offers every name of the types it inherits from as
     * well (a parent's or a trait's `@method` names); one of them that sends
     * no list, or marks it open (`member_names_open`, as a `__call()` does),
     * leaves the whole incomplete. A module's list marked `exports_open`
     * could not be completed either, and a node with no list says nothing.
     *
     * @return list<string>|null
     */
    private static function completeNames(NodeFact $target, TestFidelityGraph $graph): ?array
    {
        $module = $target->kind === 'module';
        $names = [];
        foreach ($module ? [$target] : $graph->lineage($target) as $type) {
            $listed = self::ownNames($type->attributes, $module);
            if ($listed === null) {
                return null;
            }
            $names = [...$names, ...$listed];
        }

        return array_values(array_unique($names));
    }

    /**
     * One node's own complete name list; null when it sends none or marks it open.
     *
     * @param array<string, mixed> $attributes
     * @return list<string>|null
     */
    private static function ownNames(array $attributes, bool $module): ?array
    {
        $names = $attributes[$module ? 'exports' : 'member_names'] ?? null;
        if (!is_array($names) || !array_is_list($names) || ($attributes[$module ? 'exports_open' : 'member_names_open'] ?? false) !== false) {
            return null;
        }

        return array_values(array_filter($names, is_string(...)));
    }

    /**
     * Whether the target's name list holds the stubbed name; in any case for a PHP target.
     *
     * @param list<string> $names
     */
    private static function listed(DriftPair $pair, array $names): bool
    {
        if (!TestFidelityGraph::isPhp($pair->target)) {
            return in_array($pair->stub->name, $names, true);
        }

        return in_array(strtolower($pair->stub->name), array_map(strtolower(...), $names), true);
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

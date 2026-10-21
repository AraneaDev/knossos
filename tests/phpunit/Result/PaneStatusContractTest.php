<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Result;

use Knossos\Result\PaneStatus;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;

/**
 * The pane drops an envelope whose status its set does not hold, without a
 * word. The sets were hand-kept strings in `hooks/lib/envelopes.ts`; these
 * tests hold them to {@see PaneStatus}, set by set, and the policy statuses
 * to the `PolicyStatus` type the pane reads them as.
 */
final class PaneStatusContractTest extends KnossosTestCase
{
    /** Every status set the pane parses with is one the core lists, with the same members. */
    #[Group('quality')]
    public function testThePanesStatusSetsAreTheCoresSets(): void
    {
        // The sets only: the single policy statuses are named apart for their producers.
        $declared = array_filter((new ReflectionClass(PaneStatus::class))->getConstants(), 'is_array');
        unset($declared['POLICY']);
        $pane = self::paneSets((string) file_get_contents(self::repositoryRoot() . '/hooks/lib/envelopes.ts'));

        self::assertSame(self::sorted($declared), self::sorted($pane));
    }

    /** The policy verdict's statuses are the union the pane's types allow. */
    #[Group('quality')]
    public function testThePolicyStatusesAreThePanesPolicyStatusType(): void
    {
        $types = (string) file_get_contents(self::repositoryRoot() . '/types/index.d.ts');
        self::assertSame(1, preg_match('/export type PolicyStatus = ([^\n]+)/', $types, $match));
        preg_match_all("/'([^']+)'/", $match[1], $members);
        $policy = $members[1];
        $expected = PaneStatus::POLICY;
        sort($policy);
        sort($expected);

        self::assertSame($expected, $policy);
    }

    /**
     * Each `const NAME = new Set([...])` in the source, a `...OTHER` spread
     * resolved against the sets before it.
     *
     * @return array<string, list<string>>
     */
    private static function paneSets(string $source): array
    {
        preg_match_all('/^const ([A-Z_]+) = new Set\(\[([^\]]*)\]\)/m', $source, $matches, PREG_SET_ORDER);
        self::assertNotSame([], $matches, 'envelopes.ts declares no status sets.');
        $sets = [];
        foreach ($matches as [, $name, $body]) {
            $members = [];
            foreach (array_map('trim', explode(',', $body)) as $member) {
                if (str_starts_with($member, '...')) {
                    $members = [...$members, ...$sets[substr($member, 3)]];
                } elseif (preg_match("/^'([^']+)'$/", $member, $quoted) === 1) {
                    $members[] = $quoted[1];
                } else {
                    self::fail(sprintf('Unreadable member %s of %s in envelopes.ts.', $member, $name));
                }
            }
            $sets[$name] = $members;
        }

        return $sets;
    }

    /**
     * Each set's members sorted, and the sets by name, so only membership is compared.
     *
     * @param array<array-key, mixed> $sets
     * @return array<array-key, mixed>
     */
    private static function sorted(array $sets): array
    {
        foreach ($sets as &$members) {
            self::assertIsArray($members);
            sort($members);
        }
        unset($members);
        ksort($sets);

        return $sets;
    }
}

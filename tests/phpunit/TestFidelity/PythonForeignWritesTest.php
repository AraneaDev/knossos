<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Scanner\Protocol\NodeFact;
use Knossos\TestFidelity\Rules\ArityMismatchRule;
use Knossos\TestFidelity\Rules\GhostMemberRule;
use Knossos\TestFidelity\TestFinding;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\TestFidelity\Rules\PythonFixtures;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * A Python list another file writes to is open in the graph's view (G4-03):
 * no ghost on it, and the def a written name holds has no signature a
 * matcher must meet. A target nothing writes, or another name, still reports.
 */
#[Group('test-fidelity')]
final class PythonForeignWritesTest extends KnossosTestCase
{
    public function testAWrittenClassHasNoGhostAndAnUnwrittenOneKeepsIt(): void
    {
        assertSame(['missing_member'], self::ghosts([]));
        assertSame([], self::ghosts([['target' => 'py:class:app.repo.Repo', 'name' => 'debug']]));
        // A write to another target, or one in no module node, opens nothing.
        assertSame(['missing_member'], self::ghosts([['target' => 'py:class:app.repo.Other', 'name' => 'debug']]));
        assertSame(['missing_member'], self::ghosts([['target' => 'py:class:app.repo.Repo', 'name' => 'debug']], 'class'));
        // A target the scan does not hold, written first, leaves the next target opened.
        assertSame([], self::ghosts([['target' => 'py:class:app.repo.Gone', 'name' => 'debug'], ['target' => 'py:class:app.repo.Repo', 'name' => 'debug']]));
    }

    public function testAWrittenModuleHasNoGhost(): void
    {
        assertSame(['missing_export'], self::ghosts([], kind: 'module'));
        assertSame([], self::ghosts([['target' => 'py:module:app.repo']], kind: 'module'));
    }

    public function testMalformedEntriesOpenNothing(): void
    {
        foreach ([['py:class:app.repo.Repo'], [['target' => 7]], [['target' => 'py:class:app.repo.Repo', 'name' => 3]], [['target' => 'ts:class:app.repo.Repo']], 'py:class:app.repo.Repo'] as $writes) {
            assertSame(['missing_member'], self::ghosts($writes));
        }
    }

    public function testAWrittenDefHasNoSignatureAndAnotherKeepsIt(): void
    {
        assertSame(['call_arity'], self::arity([]));
        assertSame([], self::arity([['target' => 'py:class:app.repo.Repo', 'name' => 'save']]));
        // A name no literal spells may replace any def of the class, or of the module.
        assertSame([], self::arity([['target' => 'py:class:app.repo.Repo']]));
        assertSame([], self::arity([['target' => 'py:module:app.repo']], 'module'));
        // Another name written leaves the def's signature standing.
        assertSame(['call_arity'], self::arity([['target' => 'py:class:app.repo.Repo', 'name' => 'load']]));
        assertSame(['call_arity'], self::arity([['target' => 'py:module:app.repo', 'name' => 'load']], 'module'));
    }

    /**
     * The ghosts a double stubbing `missing` on a class (or module) listing only `run` gives, with a writer module carrying `$writes`.
     *
     * @param mixed $writes
     * @return list<string>
     */
    private static function ghosts(mixed $writes, string $writerKind = 'module', string $kind = 'class'): array
    {
        $graph = PythonFixtures::graph([['run', []]], [PythonFixtures::stub('missing')], extra: [self::writer($writes, $writerKind)], kind: $kind);

        return self::subRules((new GhostMemberRule())->findings($graph));
    }

    /**
     * The arity findings a matcher of two arguments on `save(item)` gives, with a writer module carrying `$writes`.
     *
     * @param list<array<string, string>> $writes
     * @return list<string>
     */
    private static function arity(array $writes, string $kind = 'class'): array
    {
        $graph = PythonFixtures::graph(
            [['save', PythonFixtures::signature([['name' => 'item', 'type' => 'int']])]],
            [PythonFixtures::stub('save', calledWith: [PythonFixtures::matcher(2)])],
            extra: [self::writer($writes)],
            kind: $kind,
        );

        return self::subRules((new ArityMismatchRule())->findings($graph));
    }

    private static function writer(mixed $writes, string $kind = 'module'): NodeFact
    {
        return PythonFixtures::node($kind, 'app.boot', $writes === [] ? [] : ['foreign_writes' => $writes], 'src/app/boot.py');
    }

    /**
     * @param iterable<TestFinding> $findings
     * @return list<string>
     */
    private static function subRules(iterable $findings): array
    {
        $found = [];
        foreach ($findings as $finding) {
            $found[] = $finding->subRule;
        }

        return $found;
    }

}

<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\BoundaryLabels;
use Knossos\Query\DashboardService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * The inferred boundaries the dashboard names beside the declared ones: those
 * that label a component, so the two counts add up to what the heat map lists.
 */
final class BoundaryLabelsInferredTest extends KnossosTestCase
{
    /** Only the two tables the labels read, without the rest of the graph they refer to. */
    private static function store(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE boundaries (id TEXT PRIMARY KEY, project_id TEXT NOT NULL, name TEXT NOT NULL, matcher_json TEXT NOT NULL, source TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE boundary_memberships (boundary_id TEXT NOT NULL, project_id TEXT NOT NULL, node_id TEXT NOT NULL, PRIMARY KEY (boundary_id, node_id))');
        // The member count leaves out derived test facts, so it looks each member's kind up; none here is one.
        $pdo->exec('CREATE TABLE nodes (id TEXT PRIMARY KEY, kind TEXT NOT NULL)');

        return $pdo;
    }

    /**
     * A boundary of project `p` and its members.
     *
     * @param list<string> $nodes
     */
    private static function boundary(PDO $pdo, string $id, string $name, string $source, array $nodes, string $prefix = 'src/'): void
    {
        $pdo->prepare('INSERT INTO boundaries VALUES (?, ?, ?, ?, ?)')->execute([$id, 'p', $name, json_encode(['type' => 'path_prefix', 'value' => $prefix]), $source]);
        foreach ($nodes as $node) {
            $pdo->prepare('INSERT INTO boundary_memberships VALUES (?, ?, ?)')->execute([$id, 'p', $node]);
        }
    }

    /**
     * What the dashboard says beside the declared names, read as it reads it.
     *
     * @return array{inferred: list<string>, inferred_truncated: bool}
     */
    private static function inferredOf(PDO $pdo, int $limit = BoundaryLabels::DECLARED): array
    {
        $labels = BoundaryLabels::load($pdo, 'p');

        return $labels->inferred($labels->forProject('p'), $limit);
    }

    /** With every component in a declared boundary, no inferred one is named. */
    #[Group('query')]
    public function testDeclaredBoundariesAloneNameNoInferredOne(): void
    {
        $pdo = self::store();
        self::boundary($pdo, 'b1', 'core', 'explicit', ['n1', 'n2']);
        self::boundary($pdo, 'b2', 'tests', 'explicit', ['n3']);
        // An inferred boundary over the same components labels none of them: the declared ones come first.
        self::boundary($pdo, 'b3', 'module:src', 'inferred', ['n1', 'n2', 'n3']);
        assertSame(['inferred' => [], 'inferred_truncated' => false], self::inferredOf($pdo));
    }

    /** Without a declared boundary, every inferred one that labels a component is named, in name order. */
    #[Group('query')]
    public function testInferredBoundariesAloneAreNamedInNameOrder(): void
    {
        $pdo = self::store();
        self::boundary($pdo, 'b1', 'ns:Store', 'inferred', ['n1']);
        self::boundary($pdo, 'b2', 'module:hooks', 'inferred', ['n2', 'n3']);
        assertSame(['inferred' => ['module:hooks', 'ns:Store'], 'inferred_truncated' => false], self::inferredOf($pdo));
    }

    /** Beside declared ones, only the inferred boundaries that label what no declared one holds. */
    #[Group('query')]
    public function testBesideDeclaredOnesOnlyTheInferredOnesLabellingTheRestAreNamed(): void
    {
        $pdo = self::store();
        self::boundary($pdo, 'b1', 'core', 'explicit', ['n1', 'n2']);
        self::boundary($pdo, 'b2', 'module:hooks', 'inferred', ['n3']);
        self::boundary($pdo, 'b3', 'module:src', 'inferred', ['n1', 'n2']);
        // A name a declared boundary also has is listed once, as declared.
        self::boundary($pdo, 'b4', 'core', 'inferred', ['n4']);
        assertSame(['inferred' => ['module:hooks'], 'inferred_truncated' => false], self::inferredOf($pdo));
    }

    /** An inferred boundary with no members, or whose members all carry a closer label, is not named. */
    #[Group('query')]
    public function testAnInferredBoundaryLabellingNothingIsNotNamed(): void
    {
        $pdo = self::store();
        self::boundary($pdo, 'b1', 'empty', 'inferred', []);
        self::boundary($pdo, 'b2', 'ns:Store', 'inferred', ['n1']);
        // Spanning the repository, it labels only what no other boundary holds: here nothing.
        self::boundary($pdo, 'b3', 'package:root', 'inferred', ['n1'], '');
        assertSame(['inferred' => ['ns:Store'], 'inferred_truncated' => false], self::inferredOf($pdo));
    }

    /** Past the limit the first names are kept and the cut is said, as for the declared names. */
    #[Group('query')]
    public function testInferredNamesPastTheLimitAreReportedTruncated(): void
    {
        $pdo = self::store();
        foreach (['c', 'a', 'b'] as $i => $name) {
            self::boundary($pdo, "b{$i}", $name, 'inferred', ["n{$i}"]);
        }
        assertSame(['inferred' => ['a', 'b'], 'inferred_truncated' => true], self::inferredOf($pdo, 2));
        assertSame(['inferred' => ['a', 'b', 'c'], 'inferred_truncated' => false], self::inferredOf($pdo, 3));
        assertSame(200, BoundaryLabels::DECLARED);
    }

    /** The dashboard's declared and inferred names together are the matrix's axes when every declared one labels a component. */
    #[Group('query')]
    public function testTheDashboardsTwoCountsAddUpToTheMatrixAxes(): void
    {
        [$pdo, , $root] = $this->scanTempFixture('turn-brief');
        try {
            $d = (new DashboardService($pdo))->dashboard($root, boundaryAxes: 100);
            $axes = $d['boundary_matrix']['boundaries'];
            $named = [...$d['boundaries']['declared'], ...$d['boundaries']['inferred']];
            sort($axes);
            sort($named);
            assertSame($axes, $named);
            // The namespace labels what the two declared boundaries leave; the package spanning the repository labels nothing.
            assertSame(['Core', 'Edge'], $d['boundaries']['declared']);
            assertSame(['inferred' => ['namespace:App'], 'inferred_truncated' => false], array_intersect_key($d['boundaries'], ['inferred' => 0, 'inferred_truncated' => 0]));
            assertCount(count($axes) - count($d['boundaries']['declared']), $d['boundaries']['inferred']);
        } finally {
            $this->removeTempTree($root);
        }
    }
}

<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\AbstractArchitectureQueryService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\Direction;
use Knossos\Query\EdgePage;
use Knossos\Query\FileFanInQuery;
use Knossos\Query\Truncation;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PDOStatement;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * changed_files_impact and a file's top dependents read the edges of the
 * components they were given, seeking each, never every edge of the project.
 *
 * A component's members were read in target order, which SQLite took from the
 * project's whole target index to spare sorting a handful of children: 60 ms
 * a component on a scan of this repository, 8.8 s for twelve changed files,
 * and 58 s on a project of 25,000 classes. A file's top dependents read every
 * edge of the project through the kind index and then kept the file's: 280 ms
 * a file.
 */
final class ChangedFilesPlanTest extends KnossosTestCase
{
    #[Group('query')]
    public function testChangedFilesImpactSeeksItsComponentsMembers(): void
    {
        [$pdo, $project] = $this->classWithMembers();

        $scans = $this->projectScans($pdo, static function () use ($pdo, $project): void {
            ArchitectureQueryService::forDatabase($pdo)->services()->changeQueries->changedFilesImpact($project, ['src/Checkout.php'], timeoutMs: 5000);
        });

        assertSame([], $scans);
    }

    /**
     * The edges into a class and into what it contains, read for a framework
     * hook: asked for as the class or a member, SQLite read every edge of the
     * project and looked each one's target up among the members, 4 s for a
     * class of this repository.
     */
    #[Group('query')]
    public function testTheEdgesIntoAClassAndItsMembersAreSoughtByTarget(): void
    {
        [$pdo, $project] = $this->classWithMembers();
        $checkout = StableId::symbol($project, 'php', 'class', 'App\\Checkout');

        $scans = $this->projectScans($pdo, static function () use ($pdo, $project, $checkout): void {
            EdgePage::fetch($pdo, $project, Direction::Incoming, $checkout, AbstractArchitectureQueryService::IMPACT_EDGE_KINDS, 1, new Truncation(), withMembers: true);
        });

        assertSame([], $scans);
    }

    #[Group('query')]
    public function testAFilesTopDependentsAreReadFromTheFileOut(): void
    {
        [$pdo, $project] = $this->classWithMembers();

        $scans = $this->projectScans($pdo, static function () use ($pdo, $project): void {
            (new FileFanInQuery($pdo))->forPaths($project, ['src/Checkout.php']);
        });

        assertSame([], $scans);
    }

    /**
     * The fixture's Checkout class with two methods it contains.
     *
     * @return array{0: PDO, 1: string}
     */
    private function classWithMembers(): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        foreach (['pay', 'refund'] as $line => $method) {
            $id = StableId::symbol($project, 'php', 'method', 'App\\Checkout::' . $method);
            $repository->saveNode($id, $project, 'php', 'method', 'App\\Checkout::' . $method, $method, null, $ids['file'], 4 + $line, 5 + $line, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
            $repository->saveEdge(StableId::edge($project, 'contains', $ids['checkout'], $id, $method), $project, 'contains', $ids['checkout'], $id, $ids['file'], 4 + $line, 4 + $line, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        }
        $repository->completeScan($project, $ids['scan']);

        return [$pdo, $project];
    }

    /**
     * Each edge or node read `$run` makes that seeks the project alone, with the statement it came from.
     *
     * @return list<string>
     */
    private function projectScans(PDO $pdo, callable $run): array
    {
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RecordingStatement::class]);
        RecordingStatement::drain();
        try {
            $run();
        } finally {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
        }

        $reads = 0;
        $scans = [];
        foreach (RecordingStatement::drain() as [$sql, $params]) {
            foreach (RecordingStatement::plan($pdo, $sql, $params) as $detail) {
                $detail = (string) $detail;
                if (preg_match('/^SEARCH \w+ USING (COVERING )?INDEX (edges|nodes)_project_/', $detail) !== 1) {
                    continue;
                }
                ++$reads;
                // The project alone, or the project and a kind: every edge or node of the project.
                if (preg_match('/\(project_id=\?( AND kind=\?)?\)$/', $detail) === 1) {
                    $scans[] = $detail . '  <=  ' . substr((string) preg_replace('/\s+/', ' ', $sql), 0, 120);
                }
            }
        }
        assertNotSame(0, $reads, 'Nothing was read, so this test checked nothing.');

        return $scans;
    }
}

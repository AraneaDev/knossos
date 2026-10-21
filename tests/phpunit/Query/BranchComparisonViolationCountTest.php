<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\GraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The branch comparison counted new policy violations by filtering the first
 * 100 violations the check collected. On a project with more than 100
 * violations already, a new one past that page counted as none, and the
 * Branch tab called the branch clean. The base's edges are now left out
 * while the check streams, so the count is exact and only the listing is
 * capped.
 */
final class BranchComparisonViolationCountTest extends KnossosTestCase
{
    private const POLICIES = [['id' => 'web-not-core', 'from_boundary' => 'Web', 'deny_targets' => ['Core']]];

    /** One new violation behind 150 the base already had is counted and listed. */
    #[Group('query')]
    public function testANewViolationPastTheBasesFirstHundredIsCounted(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $target = $this->node($repository, $ids, 'App\\Core\\Service');
        $callers = [];
        foreach (range(1, 150) as $index) {
            $callers[] = $this->node($repository, $ids, sprintf('App\\Web\\Old%03d', $index));
        }
        // The check walks edges by source id: the new caller is placed after
        // every old one, where a page of the first 100 never reaches.
        $last = max($callers);
        $index = 0;
        while (StableId::symbol($project, 'php', 'class', 'App\\Web\\New' . $index) <= $last) {
            ++$index;
        }
        $new = $this->node($repository, $ids, 'App\\Web\\New' . $index);
        foreach ($callers as $caller) {
            $this->imports($repository, $ids, $ids['scan'], $caller, $target);
        }
        $this->boundary($repository, $ids, 'Web', [...$callers, $new]);
        $this->boundary($repository, $ids, 'Core', [$target]);
        $next = $this->nextScan($repository, $ids);
        $this->imports($repository, $ids, $next, $new, $target);
        $repository->completeScan($project, $next);

        $violations = ArchitectureQueryService::forDatabase($pdo)->branchComparison($project, $ids['scan'], self::POLICIES)['violations'];

        self::assertSame(1, $violations['count']);
        self::assertSame(['App\\Web\\New' . $index], array_column($violations['items'], 'source'));
        self::assertFalse($violations['truncated']);
    }

    /** More new violations than the check lists: the count is exact, and a full listing is no early stop. */
    #[Group('query')]
    public function testMoreNewViolationsThanTheListingHoldsAreCountedExactly(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $target = $this->node($repository, $ids, 'App\\Core\\Service');
        $callers = [];
        foreach (range(1, 120) as $index) {
            $callers[] = $this->node($repository, $ids, sprintf('App\\Web\\New%03d', $index));
        }
        $this->boundary($repository, $ids, 'Web', $callers);
        $this->boundary($repository, $ids, 'Core', [$target]);
        $next = $this->nextScan($repository, $ids);
        foreach ($callers as $caller) {
            $this->imports($repository, $ids, $next, $caller, $target);
        }
        $repository->completeScan($project, $next);

        $violations = ArchitectureQueryService::forDatabase($pdo)->branchComparison($project, $ids['scan'], self::POLICIES, 8)['violations'];

        self::assertSame(120, $violations['count']);
        self::assertCount(8, $violations['items']);
        self::assertFalse($violations['truncated']);
    }

    /** @param array<string, string> $ids */
    private function node(GraphRepository $repository, array $ids, string $name): string
    {
        $id = StableId::symbol($ids['project'], 'php', 'class', $name);
        $repository->saveNode($id, $ids['project'], 'php', 'class', $name, $name, null, $ids['file'], 1, 1, 'ast', 'certain', [], 'php:file:' . $name, $ids['scan']);
        return $id;
    }

    /** @param array<string, string> $ids */
    private function imports(GraphRepository $repository, array $ids, string $scan, string $source, string $target): void
    {
        $repository->saveEdge(StableId::edge($ids['project'], 'imports', $source, $target, 'identity'), $ids['project'], 'imports', $source, $target, $ids['file'], 1, 1, 'ast', 'certain', [], 'php:file:src/A.php', $scan);
    }

    /**
     * @param array<string, string> $ids
     * @param list<string> $members
     */
    private function boundary(GraphRepository $repository, array $ids, string $name, array $members): void
    {
        $id = StableId::boundary($ids['project'], $name, 'explicit');
        $repository->saveBoundary($id, $ids['project'], $name, ['path_prefix' => strtolower($name)], 'explicit', $ids['scan']);
        foreach ($members as $member) {
            $repository->saveBoundaryMembership($id, $ids['project'], $member, $ids['scan']);
        }
    }

    /**
     * Complete and archive the fixture's scan as the baseline, and open the next one.
     *
     * @param array<string, string> $ids
     */
    private function nextScan(GraphRepository $repository, array $ids): string
    {
        $repository->completeScan($ids['project'], $ids['scan']);
        $repository->archiveActiveSnapshot($ids['project'], hash('sha256', '{}'), 5);
        $next = StableId::scan($ids['project'], 'scan-2');
        $repository->createScan($next, $ids['project'], 'incremental', hash('sha256', 'scanner-set'));
        return $next;
    }
}

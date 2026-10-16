<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class ReviewDiffTest extends KnossosTestCase
{
    #[Group('query')]
    public function testComposesChangePolicyAndCycleSections(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $backend = StableId::boundary($ids['project'], 'Backend', 'explicit');
        $billing = StableId::boundary($ids['project'], 'Billing', 'explicit');
        $repository->saveBoundary($backend, $ids['project'], 'Backend', ['path_prefix' => 'src'], 'explicit', $ids['scan']);
        $repository->saveBoundary($billing, $ids['project'], 'Billing', ['path_prefix' => 'src/Invoice'], 'explicit', $ids['scan']);
        $repository->saveBoundaryMembership($backend, $ids['project'], $ids['checkout'], $ids['scan']);
        $repository->saveBoundaryMembership($billing, $ids['project'], $ids['invoice'], $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = ArchitectureQueryService::forDatabase($pdo);

        $policies = [['id' => 'no-billing', 'from_boundary' => $backend, 'deny_targets' => [$billing]]];
        $result = $queries->reviewDiff($ids['project'], files: ['src/Checkout.php'], policies: $policies);

        assertSame('evaluated', $result->data['change']['status']);
        assertSame(['src/Checkout.php'], $result->data['change']['changed_files']);
        assertSame('evaluated', $result->data['policy_check']['status']);
        assertSame(1, $result->data['policy_check']['total_violations']);
        // The Checkout→Invoice call violates the policy AND touches the change.
        assertSame(1, count($result->data['policy_check']['violations_touching_change']));
        assertSame('evaluated', $result->data['cycles_touching_change']['status']);
        assertSame([], $result->data['cycles_touching_change']['cycles']);
        // No retained non-active snapshot and no budgets in this fixture:
        assertSame('not_evaluated', $result->data['quality_gate']['status']);
        // dependencyCycles' caveat warning propagates into the envelope:
        assertContains('Cycles are derived', implode(' ', $result->warnings));
        // Policy-check evidence (from the touching violation) is unioned into the envelope evidence:
        $hasPolicyEvidence = false;
        foreach ($result->evidence as $row) {
            if (array_key_exists('policy_id', $row)) {
                $hasPolicyEvidence = true;
                break;
            }
        }
        assertSame(true, $hasPolicyEvidence);
    }

    /** The cycle a change touches is reported however many larger cycles elsewhere sort ahead of it. */
    #[Group('query')]
    public function testATouchingCycleBehindAHundredOthersIsReported(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        // Checkout -> Invoice exists; the back edge closes a two-member cycle in the changed file.
        self::copyRow($pdo, 'edges', $ids['edge'], ['id' => 'edge:back', 'source_id' => $ids['invoice'], 'target_id' => $ids['checkout']]);
        // 101 three-member cycles in another file sort ahead of it by size.
        self::copyRow($pdo, 'files', $ids['file'], ['id' => 'file:other', 'relative_path' => 'src/Other.php']);
        for ($cycle = 0; $cycle < 101; ++$cycle) {
            for ($member = 0; $member < 3; ++$member) {
                self::copyRow($pdo, 'nodes', $ids['invoice'], ['id' => "node:o:{$cycle}:{$member}", 'canonical_name' => "App\\Other{$cycle}x{$member}", 'display_name' => "Other{$cycle}x{$member}", 'file_id' => 'file:other', 'parent_id' => null]);
            }
            for ($member = 0; $member < 3; ++$member) {
                self::copyRow($pdo, 'edges', $ids['edge'], ['id' => "edge:o:{$cycle}:{$member}", 'source_id' => "node:o:{$cycle}:{$member}", 'target_id' => 'node:o:' . $cycle . ':' . (($member + 1) % 3), 'file_id' => 'file:other']);
            }
        }
        $repository->completeScan($ids['project'], $ids['scan']);

        $result = ArchitectureQueryService::forDatabase($pdo)->reviewDiff($ids['project'], files: ['src/Checkout.php']);

        $cycles = $result->data['cycles_touching_change']['cycles'];
        assertSame(1, count($cycles));
        $members = $cycles[0]['member_ids'];
        sort($members);
        $expected = [$ids['checkout'], $ids['invoice']];
        sort($expected);
        assertSame($expected, $members);
    }

    /** A change that touches no component touches no cycle, whatever cycles the project has. */
    #[Group('query')]
    public function testAChangeTouchingNoComponentReportsNoCycles(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        self::copyRow($pdo, 'edges', $ids['edge'], ['id' => 'edge:back', 'source_id' => $ids['invoice'], 'target_id' => $ids['checkout']]);
        $repository->completeScan($ids['project'], $ids['scan']);

        $result = ArchitectureQueryService::forDatabase($pdo)->reviewDiff($ids['project'], files: ['docs/notes.md']);

        assertSame([], $result->data['change']['direct_components'] ?? []);
        assertSame('evaluated', $result->data['cycles_touching_change']['status']);
        assertSame([], $result->data['cycles_touching_change']['cycles']);
        // Skipped, not searched and found empty: the cycle search's caveat is not there.
        assertSame(false, str_contains(implode(' ', $result->warnings), 'Cycles are derived'));
    }

    /**
     * Inserts a copy of one row with some columns replaced.
     *
     * @param array<string, string|null> $replace
     */
    private static function copyRow(\PDO $pdo, string $table, string $id, array $replace): void
    {
        $statement = $pdo->prepare(sprintf('SELECT * FROM %s WHERE id = ?', $table));
        $statement->execute([$id]);
        $row = array_replace((array) $statement->fetch(\PDO::FETCH_ASSOC), $replace);
        $pdo->prepare(sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', array_keys($row)), implode(', ', array_fill(0, count($row), '?'))))->execute(array_values($row));
    }

    #[Group('query')]
    public function testMissingConfigYieldsNotEvaluatedSectionsNotErrors(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        // root_realpath (/workspace/fixture-shop) does not exist on disk:
        // config loading fails soft, sections degrade, envelope still returns.
        $result = ArchitectureQueryService::forDatabase($pdo)->reviewDiff($ids['project'], files: ['src/Checkout.php']);
        assertSame('not_evaluated', $result->data['policy_check']['status']);
        assertSame('not_evaluated', $result->data['quality_gate']['status']);
        assertSame('evaluated', $result->data['change']['status']);
    }

    #[Group('query')]
    public function testFilesWithBaseRefIsRejected(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        assertThrows(
            fn() => ArchitectureQueryService::forDatabase($pdo)->reviewDiff($ids['project'], baseRef: 'main', files: ['src/Checkout.php']),
            InvalidArgumentException::class,
        );
    }

    #[Group('query')]
    public function testQualityGateIsEvaluatedAgainstARetainedSnapshot(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        // Archive scan-1's snapshot before it's superseded, so it becomes the
        // latest non-active snapshot once scan-2 takes over as active.
        $repository->archiveActiveSnapshot($ids['project'], hash('sha256', '{}'), 5);

        $scan2 = StableId::scan($ids['project'], 'scan-2');
        $repository->createScan($scan2, $ids['project'], 'full', hash('sha256', 'scanner-set'));
        $repository->completeScan($ids['project'], $scan2);

        $result = ArchitectureQueryService::forDatabase($pdo)->reviewDiff(
            $ids['project'],
            files: ['src/Checkout.php'],
            budgets: ['new_cycles' => 0],
        );
        assertSame('evaluated', $result->data['quality_gate']['status']);
        assertSame(true, is_bool($result->data['quality_gate']['passed']));
    }

    /**
     * The gate compares against the scan before the active one unless told
     * otherwise, so its verdict means "no regression since that scan". The
     * result names that baseline, and says when base_ref was asked for but
     * is not what the gate compared against.
     */
    #[Group('query')]
    public function testTheGateNamesItsBaselineAndWhenItIsNotTheBaseRef(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $repository->archiveActiveSnapshot($ids['project'], hash('sha256', '{}'), 5);
        $scan2 = StableId::scan($ids['project'], 'scan-2');
        $repository->createScan($scan2, $ids['project'], 'full', hash('sha256', 'scanner-set'));
        $repository->completeScan($ids['project'], $scan2);
        $queries = ArchitectureQueryService::forDatabase($pdo);

        $previous = $queries->reviewDiff($ids['project'], files: ['src/Checkout.php'], budgets: ['new_cycles' => 0])->data['quality_gate']['baseline'];
        assertSame($ids['scan'], $previous['snapshot']);
        assertSame('previous_scan', $previous['source']);
        assertSame(true, is_int($previous['age_seconds']) && $previous['age_seconds'] >= 0);

        $given = $queries->reviewDiff($ids['project'], files: ['src/Checkout.php'], budgets: ['new_cycles' => 0], baselineSnapshot: $ids['scan'])->data['quality_gate']['baseline'];
        assertSame('argument', $given['source']);

        $tree = new class implements \Knossos\Git\GitWorkingTreeProvider {
            public function changes(string $projectRoot, ?string $baseRef, int $maxFiles, int $timeoutMs): array
            {
                return ['paths' => ['src/Checkout.php'], 'renames' => [], 'truncated' => false];
            }
        };
        $fromGit = ArchitectureQueryService::forDatabase($pdo, gitWorkingTree: $tree);
        $warnings = $fromGit->reviewDiff($ids['project'], baseRef: 'main', budgets: ['new_cycles' => 0])->warnings;
        assertSame(1, count(array_filter($warnings, static fn(string $w): bool => str_contains($w, 'not the graph of base_ref main'))));
        $named = $fromGit->reviewDiff($ids['project'], baseRef: 'main', budgets: ['new_cycles' => 0], baselineSnapshot: $ids['scan'])->warnings;
        assertSame(0, count(array_filter($named, static fn(string $w): bool => str_contains($w, 'not the graph of base_ref'))));
    }

    #[Group('query')]
    public function testDispatchThroughToolService(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $tools = new \Knossos\Mcp\ToolService(
            new \Knossos\Scan\ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            ArchitectureQueryService::forDatabase($pdo),
            new \Knossos\Maintenance\DatabaseMaintenanceService($pdo, ':memory:'),
            new \Knossos\Mcp\ResultEnricher(new \Knossos\Query\StalenessProbe($pdo), new \Knossos\Mcp\NextStepPlanner()),
        );
        $result = $tools->call('review_diff', ['project_id' => $ids['project'], 'files' => ['src/Checkout.php']]);
        assertSame(true, array_key_exists('change', $result->data));
        assertSame(true, array_key_exists('policy_check', $result->data));
        assertSame(true, array_key_exists('quality_gate', $result->data));
        assertSame(true, array_key_exists('cycles_touching_change', $result->data));
    }
}

<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Closure;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Result\ResultEnvelope;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Each query turns `timeout_ms` into a deadline `timeout_ms * 1_000_000`
 * nanoseconds after its first clock read, and stops only once the clock is
 * past it. A clock that reads exactly the deadline is still in time; one
 * nanosecond later is out. The two together pin the conversion exactly: a
 * deadline a few nanoseconds early or late lands on the wrong side of one of
 * them.
 */
#[Group('query')]
final class DeadlineBoundaryTest extends KnossosTestCase
{
    private const TIMEOUT_MS = 50;

    /** @return iterable<string, array{Closure(ArchitectureQueryService, array<string, string>): ResultEnvelope}> */
    public static function queries(): iterable
    {
        $timeout = self::TIMEOUT_MS;
        yield 'architecture_health' => [static fn(ArchitectureQueryService $q, array $ids): ResultEnvelope => $q->architectureHealth($ids['project'], timeoutMs: $timeout)];
        yield 'check_architecture' => [static fn(ArchitectureQueryService $q, array $ids): ResultEnvelope => $q->checkArchitecture($ids['project'], [
            ['id' => 'backend-deny-billing', 'from_boundary' => $ids['backend'], 'deny_targets' => [$ids['billing']], 'edge_kinds' => ['calls']],
        ], timeoutMs: $timeout)];
        yield 'changed_files_impact' => [static fn(ArchitectureQueryService $q, array $ids): ResultEnvelope => $q->changedFilesImpact($ids['project'], ['src/Checkout.php'], timeoutMs: $timeout)];
        yield 'test_impact' => [static fn(ArchitectureQueryService $q, array $ids): ResultEnvelope => $q->testImpact($ids['project'], ['src/Checkout.php'], timeoutMs: $timeout)];
        yield 'dependency_cycles' => [static fn(ArchitectureQueryService $q, array $ids): ResultEnvelope => $q->dependencyCycles($ids['project'], timeoutMs: $timeout)];
        yield 'explain_flow' => [static fn(ArchitectureQueryService $q, array $ids): ResultEnvelope => $q->explainFlow($ids['project'], 'App\\Checkout', 'App\\InvoiceService', timeoutMs: $timeout)];
        yield 'impact_analysis' => [static fn(ArchitectureQueryService $q, array $ids): ResultEnvelope => $q->impactAnalysis($ids['project'], 'App\\InvoiceService', timeoutMs: $timeout)];
        yield 'suggest_location' => [static fn(ArchitectureQueryService $q, array $ids): ResultEnvelope => $q->suggestLocation($ids['project'], 'checkout invoice service', timeoutMs: $timeout)];
    }

    /** @param Closure(ArchitectureQueryService, array<string, string>): ResultEnvelope $query */
    #[DataProvider('queries')]
    public function testAClockAtTheDeadlineIsStillInTime(Closure $query): void
    {
        self::assertSame(false, self::timedOut($this->runAgainstFixture($query, 0)));
    }

    /** @param Closure(ArchitectureQueryService, array<string, string>): ResultEnvelope $query */
    #[DataProvider('queries')]
    public function testAClockOneNanosecondPastTheDeadlineIsOutOfTime(Closure $query): void
    {
        self::assertSame(true, self::timedOut($this->runAgainstFixture($query, 1)));
    }

    /**
     * Run the query against the fixture graph with a clock that reads T0 once,
     * where the query sets its deadline, and the deadline plus $past after that.
     *
     * @param Closure(ArchitectureQueryService, array<string, string>): ResultEnvelope $query
     */
    private function runAgainstFixture(Closure $query, int $past): ResultEnvelope
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $ids['backend'] = StableId::boundary($ids['project'], 'Backend', 'explicit');
        $ids['billing'] = StableId::boundary($ids['project'], 'Billing', 'explicit');
        $repository->saveBoundary($ids['backend'], $ids['project'], 'Backend', ['path_prefix' => 'src/Checkout'], 'explicit', $ids['scan']);
        $repository->saveBoundary($ids['billing'], $ids['project'], 'Billing', ['path_prefix' => 'src/Invoice'], 'explicit', $ids['scan']);
        $repository->saveBoundaryMembership($ids['backend'], $ids['project'], $ids['checkout'], $ids['scan']);
        $repository->saveBoundaryMembership($ids['billing'], $ids['project'], $ids['invoice'], $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $start = 1_000_000_000_000;
        $later = $start + self::TIMEOUT_MS * 1_000_000 + $past;
        $reads = 0;
        $clock = static function () use (&$reads, $start, $later): int {
            return $reads++ === 0 ? $start : $later;
        };

        return $query(ArchitectureQueryService::forDatabase($pdo, $clock), $ids);
    }

    /**
     * Whether the query stopped early. Most name `time_limit` among their
     * truncation reasons; changed_files_impact folds its per-component
     * analyses into one result and reports only that it was truncated.
     */
    private static function timedOut(ResultEnvelope $result): bool
    {
        return $result->truncated || str_contains((string) json_encode([$result->data, $result->warnings]), 'time_limit');
    }
}

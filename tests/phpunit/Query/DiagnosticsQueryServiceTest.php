<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** A project's scan diagnostics, whole, filtered by severity and path. */
#[Group('query')]
final class DiagnosticsQueryServiceTest extends KnossosTestCase
{
    public function testDiagnosticsAreListedByFileAndLineAndFiltered(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $insert = $pdo->prepare("INSERT INTO diagnostics (id, project_id, scan_id, file_id, severity, code, message, start_line, end_line, owner_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'test')");
        $insert->execute(['d1', $ids['project'], $ids['scan'], $ids['file'], 'warning', 'TS6133', 'unused', 9, 9]);
        $insert->execute(['d2', $ids['project'], $ids['scan'], $ids['file'], 'error', 'TS2322', 'wrong type', 3, 3]);
        $insert->execute(['d3', $ids['project'], $ids['scan'], null, 'info', 'K1', 'note', null, null]);
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = ArchitectureQueryService::forDatabase($pdo);

        $all = $queries->services()->diagnosticsQueries->listDiagnostics($ids['project']);
        self::assertSame(3, $all->data['total']);
        self::assertSame(['TS2322', 'TS6133', 'K1'], array_column($all->data['diagnostics'], 'code'));
        self::assertSame(['src/Checkout.php', 3], [$all->data['diagnostics'][0]['path'], $all->data['diagnostics'][0]['line']]);
        self::assertSame(['TS2322'], array_column($queries->services()->diagnosticsQueries->listDiagnostics($ids['project'], severity: 'error')->data['diagnostics'], 'code'));
        self::assertSame(['TS2322', 'TS6133'], array_column($queries->services()->diagnosticsQueries->listDiagnostics($ids['project'], pathPrefix: 'src/')->data['diagnostics'], 'code'));
        self::assertSame([], $queries->services()->diagnosticsQueries->listDiagnostics($ids['project'], pathPrefix: 'nowhere/')->data['diagnostics']);
        // A prefix is a path, and paths are case-sensitive.
        self::assertSame([], $queries->services()->diagnosticsQueries->listDiagnostics($ids['project'], pathPrefix: 'SRC/')->data['diagnostics']);
        self::assertSame([], $queries->services()->diagnosticsQueries->listDiagnostics($ids['project'], pathPrefix: 's_c/')->data['diagnostics']);
        $page = $queries->services()->diagnosticsQueries->listDiagnostics($ids['project'], limit: 2);
        self::assertSame([2, true], [$page->data['pagination']['next_offset'], $page->truncated]);
        $this->expectException(InvalidArgumentException::class);
        $queries->services()->diagnosticsQueries->listDiagnostics($ids['project'], severity: 'fatal');
    }

    /**
     * A diagnostic the project's `diagnostic_suppressions` mark as not worth
     * reporting (by code, by code and file, or by code under a path) is left
     * out of the list and counted, so a false positive can be put aside.
     */
    public function testSuppressedDiagnosticsAreLeftOutAndCounted(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $insert = $pdo->prepare("INSERT INTO diagnostics (id, project_id, scan_id, file_id, severity, code, message, start_line, end_line, owner_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'test')");
        $insert->execute(['d1', $ids['project'], $ids['scan'], $ids['file'], 'error', 'TS5101', 'deprecated option', 1, 1]);
        $insert->execute(['d2', $ids['project'], $ids['scan'], $ids['file'], 'error', 'TS2322', 'wrong type', 3, 3]);
        $insert->execute(['d3', $ids['project'], $ids['scan'], $ids['file'], 'warning', 'TS6133', 'unused', 9, 9]);
        $repository->completeScan($ids['project'], $ids['scan']);
        $diagnostics = ArchitectureQueryService::forDatabase($pdo)->services()->diagnosticsQueries;
        $suppress = static function (array $suppressions) use ($pdo, $ids): void {
            $pdo->prepare('UPDATE projects SET config_json = ? WHERE id = ?')->execute([json_encode(['diagnostic_suppressions' => $suppressions], JSON_THROW_ON_ERROR), $ids['project']]);
        };

        $suppress([['code' => 'TS5101'], ['code' => 'TS6133', 'path' => 'src/*'], ['code' => 'TS2322', 'path' => 'src/Other.php']]);
        $listed = $diagnostics->listDiagnostics($ids['project']);
        self::assertSame(['TS2322'], array_column($listed->data['diagnostics'], 'code'));
        self::assertSame([1, 2], [$listed->data['total'], $listed->data['suppressed']]);
        self::assertStringContainsString('2 more the project suppresses', $listed->summary);

        $suppress([]);
        self::assertSame(0, $diagnostics->listDiagnostics($ids['project'])->data['suppressed']);
    }
}

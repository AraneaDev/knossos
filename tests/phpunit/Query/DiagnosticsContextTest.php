<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\ProjectFindings;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * A diagnostic says where it came from: the scanner or enricher that
 * recorded it, or that this is unknown, and whether its message was cut.
 */
final class DiagnosticsContextTest extends KnossosTestCase
{
    /** @return array{0: \PDO, 1: string} */
    private function withDiagnostics(): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $save = static function (string $code, string $message, string $owner) use ($repository, $ids): void {
            $repository->saveDiagnostic('diag_' . $code, $ids['project'], $ids['scan'], $ids['file'], 'error', $code, $message, 3, 3, $owner);
        };
        $save('E1', str_repeat('a', 200), 'knossos.typescript:file:src/Checkout.php');
        $save('E2', 'short', '');
        $repository->completeScan($ids['project'], $ids['scan']);

        return [$pdo, $ids['project']];
    }

    #[Group('query')]
    public function testTheDashboardNamesEachDiagnosticsSourceAndMarksACutMessage(): void
    {
        [$pdo, $project] = $this->withDiagnostics();

        [$long, $short] = (new ProjectFindings($pdo))->diagnostics($project)['items'];

        assertSame(['E1', 'knossos.typescript', true], [$long['code'], $long['source'], $long['cut']]);
        assertSame(160, mb_strlen($long['message']));
        assertSame('…', mb_substr($long['message'], -1));
        // Recorded with no owner: the source is unknown, not guessed.
        assertSame(['E2', null, false, 'short'], [$short['code'], $short['source'], $short['cut'], $short['message']]);
    }

    #[Group('query')]
    public function testListDiagnosticsNamesEachSourceToo(): void
    {
        [$pdo, $project] = $this->withDiagnostics();

        $items = ArchitectureQueryService::forDatabase($pdo)->listDiagnostics($project)->data['diagnostics'];

        assertSame(['knossos.typescript', null], array_column($items, 'source'));
        assertSame(200, mb_strlen($items[0]['message']));
    }
}

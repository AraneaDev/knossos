<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\AnnotationNotes;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ScanOptions;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * A model note on a component goes stale when the component's own lines
 * change, not when anything else in its file does: an edit to a neighbour, or
 * a line inserted above it, leaves the note standing.
 */
#[Group('query')]
final class AnnotationSpanStalenessTest extends KnossosTestCase
{
    private const SOURCE = "<?php\nnamespace App;\nfinal class A\n{\n    public function m(): int\n    {\n        return 1;\n    }\n\n    public function other(): int\n    {\n        return 2;\n    }\n}\n";

    private string $root;

    private PDO $pdo;

    private string $projectId;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/knossos-stale-span-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o777, true);
        file_put_contents($this->root . '/composer.json', '{"name":"app/app","autoload":{"psr-4":{"App\\\\":"src/"}}}');
        file_put_contents($this->root . '/src/A.php', self::SOURCE);
        $this->pdo = $this->freshTestDatabase();
        $this->projectId = $this->rescan();
        ArchitectureQueryService::forDatabase($this->pdo)->services()->annotationQueries
            ->upsertAnnotation($this->projectId, 'App\\A::m', 'note', 'returns the one', execute: true);
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
    }

    public function testTheBasisRecordsTheComponentsSpan(): void
    {
        $basis = json_decode((string) $this->pdo->query("SELECT basis_json FROM annotations WHERE canonical_name = 'App\\A::m'")->fetchColumn(), true);

        self::assertSame('src/A.php', $basis[0]['path']);
        self::assertSame(['start' => 5, 'end' => 8, 'hash' => hash('sha256', "    public function m(): int\n    {\n        return 1;\n    }")], $basis[0]['span']);
    }

    public function testAnEditToAnotherMethodLeavesTheNoteFresh(): void
    {
        $this->edit(str_replace('return 2;', 'return 3;', self::SOURCE));

        self::assertSame([false, null], $this->staleness());
    }

    public function testALineInsertedAboveTheComponentLeavesTheNoteFresh(): void
    {
        $this->edit(str_replace("{\n    public function m", "{\n\n    public function m", self::SOURCE));

        self::assertSame([false, null], $this->staleness());
    }

    public function testAChangeInsideTheComponentMakesTheNoteStale(): void
    {
        $this->edit(str_replace('return 1;', 'return 7;', self::SOURCE));

        self::assertSame([true, 'written before src/A.php changed'], $this->staleness());
    }

    public function testABasisWithoutASpanGoesStaleOnAnyChangeToTheFile(): void
    {
        $basis = json_decode((string) $this->pdo->query("SELECT basis_json FROM annotations WHERE canonical_name = 'App\\A::m'")->fetchColumn(), true);
        $old = array_map(static fn(array $entry): array => ['path' => $entry['path'], 'hash' => $entry['hash']], $basis);
        $this->pdo->prepare("UPDATE annotations SET basis_json = ? WHERE canonical_name = 'App\\A::m'")->execute([json_encode($old)]);

        $this->edit(str_replace('return 2;', 'return 3;', self::SOURCE));

        self::assertSame([true, 'written before src/A.php changed'], $this->staleness());
    }

    public function testAFileEditedOnDiskSinceTheScanFallsBackToTheWholeFile(): void
    {
        $this->edit(str_replace('return 2;', 'return 3;', self::SOURCE));
        // The disk no longer holds what the scan read, so its lines say nothing of the scanned span.
        file_put_contents($this->root . '/src/A.php', str_replace('return 2;', 'return 4;', self::SOURCE));

        self::assertSame([true, 'written before src/A.php changed'], $this->staleness());
    }

    public function testListingAndConfirmingReadTheSpanToo(): void
    {
        $this->edit(str_replace('return 1;', 'return 7;', self::SOURCE));
        $annotations = ArchitectureQueryService::forDatabase($this->pdo)->services()->annotationQueries;
        self::assertSame([true], array_column($annotations->listAnnotations($this->projectId)->data['annotations'], 'stale'));

        $annotations->confirmAnnotation($this->projectId, 'App\\A::m', execute: true);
        $this->edit(str_replace(['return 1;', 'return 2;'], ['return 7;', 'return 3;'], self::SOURCE));

        self::assertSame([false], array_column($annotations->listAnnotations($this->projectId)->data['annotations'], 'stale'));
    }

    /** The source written to disk and scanned again. */
    private function edit(string $source): void
    {
        file_put_contents($this->root . '/src/A.php', $source);
        $this->rescan();
    }

    private function rescan(): string
    {
        return (new ProjectScanService($this->pdo, self::repositoryRoot(), [$this->root]))->scan($this->root, new ScanOptions(mode: 'full'))->projectId;
    }

    /** @return array{0: bool, 1: ?string} */
    private function staleness(): array
    {
        $note = (new AnnotationNotes($this->pdo))->forComponents($this->projectId, ['App\\A::m'])[0];

        return [$note['stale'], $note['stale_reason']];
    }
}

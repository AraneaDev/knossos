<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\NextStepPlanner;
use Knossos\Mcp\ResultEnricher;
use Knossos\Mcp\ToolInputException;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\StalenessProbe;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ScanOptions;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * A note keyed by a name the graph lost: the listing suggests where its
 * component went when one component added since matches it by kind and short
 * name, and an agent moves its own note there with `move_to`.
 */
#[Group('query')]
final class AnnotationMoveTest extends KnossosTestCase
{
    public function testAMovedComponentsNoteSuggestsItsNewName(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-note-move-' . bin2hex(random_bytes(6));
        mkdir($root . '/src/Old', 0o777, true);
        mkdir($root . '/src/New', 0o777, true);
        file_put_contents($root . '/composer.json', '{"name":"app/app","autoload":{"psr-4":{"App\\\\":"src/"}}}');
        file_put_contents($root . '/src/Old/Svc.php', "<?php\nnamespace App\\Old;\nfinal class Svc {}\n");
        file_put_contents($root . '/src/Keep.php', "<?php\nnamespace App;\nfinal class Keep {}\n");
        try {
            $pdo = $this->freshTestDatabase();
            $scan = static fn(): string => (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, new ScanOptions(mode: 'full'))->projectId;
            $projectId = $scan();
            $annotations = ArchitectureQueryService::forDatabase($pdo)->services()->annotationQueries;
            $annotations->upsertAnnotation($projectId, 'App\\Old\\Svc', 'note', 'the service', execute: true);
            $annotations->upsertAnnotation($projectId, 'App\\Gone', 'note', 'never there', execute: true);

            unlink($root . '/src/Old/Svc.php');
            file_put_contents($root . '/src/New/Svc.php', "<?php\nnamespace App\\New;\nfinal class Svc {}\n");
            $scan();
            // The graph that still held the old name is a retained snapshot now.
            self::assertGreaterThan(0, (int) $pdo->query('SELECT COUNT(*) FROM scan_snapshots WHERE complete = 1')->fetchColumn());
            $rows = array_column($annotations->listAnnotations($projectId)->data['annotations'], null, 'canonical_name');

            self::assertSame(['missing', 'App\\New\\Svc'], [$rows['App\\Old\\Svc']['component_status'], $rows['App\\Old\\Svc']['suggested_component']]);
            // Nothing in the graph matches a name no scan ever held.
            self::assertSame(['missing', null], [$rows['App\\Gone']['component_status'], $rows['App\\Gone']['suggested_component']]);

            // Two components that could be it: no guess.
            file_put_contents($root . '/src/Svc.php', "<?php\nnamespace App;\nfinal class Svc {}\n");
            $scan();
            $rows = array_column($annotations->listAnnotations($projectId)->data['annotations'], null, 'canonical_name');
            self::assertSame(['missing', null], [$rows['App\\Old\\Svc']['component_status'], $rows['App\\Old\\Svc']['suggested_component']]);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testANameNoRetainedSnapshotHoldsIsSuggestedNothing(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $annotations = ArchitectureQueryService::forDatabase($pdo)->services()->annotationQueries;
        $annotations->upsertAnnotation($ids['project'], 'App\\Moved\\Checkout', 'note', 'moved away', execute: true);
        $annotations->upsertAnnotation($ids['project'], 'App\\Checkout', 'note', 'here', execute: true);

        $rows = array_column($annotations->listAnnotations($ids['project'])->data['annotations'], null, 'canonical_name');

        // An `App\Checkout` is in the graph, but nothing says it was added since the lost name was last seen.
        self::assertSame(null, $rows['App\\Moved\\Checkout']['suggested_component']);
        // A present note is suggested nothing.
        self::assertArrayNotHasKey('suggested_component', $rows['App\\Checkout']);
    }

    public function testOnlyAPageWithAMissingNoteReadsTheSnapshots(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $annotations = ArchitectureQueryService::forDatabase($pdo)->services()->annotationQueries;
        $annotations->upsertAnnotation($ids['project'], 'App\\Checkout', 'note', 'here', execute: true);
        $annotations->upsertAnnotation($ids['project'], 'App\\Moved\\Checkout', 'note', 'moved away', execute: true);
        // Any read of the snapshots now fails loudly.
        $pdo->exec('ALTER TABLE scan_snapshots RENAME TO scan_snapshots_away');

        $present = $annotations->listAnnotations($ids['project'], limit: 1);

        self::assertSame(['present'], array_column($present->data['annotations'], 'component_status'));
        self::assertInstanceOf(\PDOException::class, self::captureThrown(fn() => $annotations->listAnnotations($ids['project'], limit: 1, offset: 1), \PDOException::class));
    }

    public function testMoveToCarriesAModelNote(): void
    {
        [$pdo, $tools, $projectId] = $this->tools();
        $basis = '[{"path":"src/Old.php","hash":"old"}]';
        $pdo->prepare("INSERT INTO annotations(project_id, canonical_name, kind, value, author, basis_json, created_at, updated_at) VALUES (?, 'App\\Old\\Checkout', 'note', 'the checkout flow', 'model', ?, '2026-01-02T03:04:05Z', '2026-01-02T03:04:05Z')")
            ->execute([$projectId, $basis]);
        $move = ['project_id' => $projectId, 'component' => 'App\\Old\\Checkout', 'kind' => 'note', 'move_to' => 'App\\Checkout'];

        $preview = $tools->call('annotate_component', $move);
        self::assertSame([false, 'move', 'App\\Old\\Checkout', 'App\\Checkout'], [$preview->data['executed'], $preview->data['action'], $preview->data['component'], $preview->data['to']]);
        self::assertSame(['App\\Old\\Checkout'], $this->names($pdo));

        $moved = $tools->call('annotate_component', [...$move, 'execute' => true]);

        self::assertSame(true, $moved->data['executed']);
        self::assertSame(['App\\Checkout'], $this->names($pdo));
        $row = $pdo->query('SELECT value, author, basis_json, created_at FROM annotations')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['value' => 'the checkout flow', 'author' => 'model', 'basis_json' => $basis, 'created_at' => '2026-01-02T03:04:05Z'], $row);
        // It reads stale where it lands, until the model looks.
        self::assertSame(true, $tools->call('list_annotations', ['project_id' => $projectId])->data['annotations'][0]['stale']);
    }

    public function testAMovedModelNoteTakesThePersonsCheckWithIt(): void
    {
        [$pdo, $tools, $projectId] = $this->tools();
        $annotations = ArchitectureQueryService::forDatabase($pdo)->services()->annotationQueries;
        $annotations->upsertAnnotation($projectId, 'App\\Old\\Checkout', 'note', 'the model saw this', execute: true);
        $annotations->upsertAnnotation($projectId, 'App\\Old\\Checkout', 'check', 'still true?', execute: true, author: 'user');
        $annotations->upsertAnnotation($projectId, 'App\\Old\\Invoice', 'note', 'the model saw that', execute: true);
        $annotations->upsertAnnotation($projectId, 'App\\Old\\Invoice', 'check', 'old question', execute: true, author: 'user');
        $annotations->upsertAnnotation($projectId, 'App\\InvoiceService', 'check', 'the question here', execute: true, author: 'user');

        $tools->call('annotate_component', ['project_id' => $projectId, 'component' => 'App\\Old\\Checkout', 'kind' => 'note', 'move_to' => 'App\\Checkout', 'execute' => true]);
        $tools->call('annotate_component', ['project_id' => $projectId, 'component' => 'App\\Old\\Invoice', 'kind' => 'note', 'move_to' => 'App\\InvoiceService', 'execute' => true]);

        // The check went with its note; where one was already, that one stays and the moved one is gone.
        self::assertSame(
            [['App\\Checkout', 'check', 'still true?'], ['App\\Checkout', 'note', 'the model saw this'], ['App\\InvoiceService', 'check', 'the question here'], ['App\\InvoiceService', 'note', 'the model saw that']],
            $pdo->query('SELECT canonical_name, kind, value FROM annotations ORDER BY canonical_name, kind')->fetchAll(PDO::FETCH_NUM),
        );
    }

    public function testMoveToRefusesWhatItCannotMove(): void
    {
        [$pdo, $tools, $projectId] = $this->tools();
        $annotations = ArchitectureQueryService::forDatabase($pdo)->services()->annotationQueries;
        $annotations->upsertAnnotation($projectId, 'App\\Old\\Checkout', 'note', 'the model saw this', execute: true);
        $move = ['project_id' => $projectId, 'component' => 'App\\Old\\Checkout', 'kind' => 'note', 'move_to' => 'App\\Checkout', 'execute' => true];

        foreach ([['value' => 'new text'], ['confirm' => true], ['remove' => true], ['replace' => true], ['evidence_paths' => ['src/Checkout.php']], ['move_to' => 'App\\Old\\Checkout']] as $conflict) {
            self::assertStringContainsString('move_to', self::captureThrown(fn() => $tools->call('annotate_component', [...$move, ...$conflict]), ToolInputException::class)->getMessage());
        }
        self::assertStringContainsString('App\\Nowhere is not in the graph either', self::captureThrown(fn() => $tools->call('annotate_component', [...$move, 'move_to' => 'App\\Nowhere']), InvalidArgumentException::class)->getMessage());
        self::assertSame(['App\\Old\\Checkout'], $this->names($pdo));

        // A person's note is never moved by the model, nor written over where it would land.
        $annotations->upsertAnnotation($projectId, 'App\\Old\\Invoice', 'note', 'the person wrote this', execute: true, author: 'user');
        self::assertStringContainsString('has no note by the model to move', self::captureThrown(fn() => $tools->call('annotate_component', [...$move, 'component' => 'App\\Old\\Invoice']), InvalidArgumentException::class)->getMessage());
        $annotations->upsertAnnotation($projectId, 'App\\Checkout', 'note', 'the person is here', execute: true, author: 'user');
        $tools->call('annotate_component', $move);
        self::assertSame(
            [['App\\Checkout', 'model', 'the model saw this'], ['App\\Checkout', 'user', 'the person is here'], ['App\\Old\\Invoice', 'user', 'the person wrote this']],
            $pdo->query('SELECT canonical_name, author, value FROM annotations ORDER BY canonical_name, author')->fetchAll(PDO::FETCH_NUM),
        );
    }

    /** @return array{0: PDO, 1: ToolService, 2: string} */
    private function tools(): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $tools = new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            ArchitectureQueryService::forDatabase($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner()),
        );

        return [$pdo, $tools, $ids['project']];
    }

    /** @return list<string> */
    private function names(PDO $pdo): array
    {
        return $pdo->query('SELECT canonical_name FROM annotations ORDER BY canonical_name')->fetchAll(PDO::FETCH_COLUMN);
    }
}

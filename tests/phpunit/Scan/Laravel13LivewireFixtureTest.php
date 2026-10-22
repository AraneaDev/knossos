<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\NextStepPlanner;
use Knossos\Mcp\ResultEnricher;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\StalenessProbe;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A Laravel 13 and Livewire 4 project, scanned the way a user would scan it.
 *
 * The fixture holds only code the framework calls on its own (Livewire hooks,
 * attributed methods, view actions, observers, accessors, scopes, Route::livewire
 * components) plus three declarations nothing reaches. If one of the framework
 * conventions stops being recognised, a method that is alive shows up here as a
 * candidate; if the rules over-reach, one of the three controls disappears.
 */
#[Group('scan')]
final class Laravel13LivewireFixtureTest extends KnossosTestCase
{
    public function testOnlyTheControlsAreDeadCodeCandidates(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/laravel13-livewire';
        $pdo = $this->freshTestDatabase();
        $scan = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);
        $result = $scan->scan($root);
        $tools = new ToolService(
            $scan,
            ArchitectureQueryService::forDatabase($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner()),
        );

        $health = $tools->call('architecture_health', ['project_id' => $result->projectId])->jsonSerialize()['data'];
        $candidates = array_column($health['dead_code_candidates'], 'component');
        sort($candidates);
        $routes = $pdo->query("SELECT canonical_name FROM nodes WHERE kind = 'route' ORDER BY canonical_name")->fetchAll(\PDO::FETCH_COLUMN);

        self::assertSame(
            ['App\\Livewire\\PostTable::unusedAction', 'App\\Support\\LegacyFormatter', 'App\\Support\\LegacyFormatter::format'],
            $candidates,
        );
        self::assertContains('GET|HEAD /dashboard => App\\Livewire\\Dashboard', $routes);
        self::assertContains('GET|HEAD /posts/create => pages::post.create', $routes);
    }
}

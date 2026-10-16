<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\NextStepPlanner;
use Knossos\Mcp\ResultEnricher;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\PaneQueries;
use Knossos\Query\StalenessProbe;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\PaneAgreementGraph;
use PDO;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertSame;

/**
 * A person flags a model note for the model to look at again: a `check`
 * written from the pane, which the model's next note on that component
 * clears. Only the pane writes one; the model cannot.
 */
#[Group('query')]
final class AnnotationCheckTest extends KnossosTestCase
{
    use PaneAgreementGraph;

    public function testThePaneWritesTheCheckAsThePersons(): void
    {
        [$pdo, $root, $projectId] = $this->paneAgreementGraph();

        $answer = $this->pane($pdo, $root, ['component' => 'App\\Target', 'kind' => 'check', 'value' => 'still true after the cache moved?'], execute: true);

        assertSame(['ok', true], [$answer['status'], $answer['executed']]);
        assertSame([['user', 'still true after the cache moved?']], self::checks($pdo, $projectId, 'App\\Target'));
    }

    public function testTheModelCannotWriteACheck(): void
    {
        [$pdo, , $projectId] = $this->paneAgreementGraph();
        $queries = ArchitectureQueryService::forDatabase($pdo);

        try {
            $queries->upsertAnnotation($projectId, 'App\\Target', 'check', 'look', execute: true);
            self::fail('a model check was written');
        } catch (InvalidArgumentException $refused) {
            assertSame('check is written by the person, from the pane.', $refused->getMessage());
        }
        assertSame([], self::checks($pdo, $projectId, 'App\\Target'));
    }

    public function testTheMcpToolRefusesTheCheckKind(): void
    {
        [$pdo, , $projectId] = $this->paneAgreementGraph();
        $tools = new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), []),
            ArchitectureQueryService::forDatabase($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner()),
        );

        // Refused before the service is reached, with the kinds the agent may write: check is not one.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('kind must be one of: intended_boundary, confirmed_dead, false_positive, intentional, note.');
        $tools->call('annotate_component', ['project_id' => $projectId, 'component' => 'App\\Target', 'kind' => 'check', 'execute' => true]);
    }

    public function testAModelNoteOnTheComponentClearsItsCheckAndOneElsewhereDoesNot(): void
    {
        [$pdo, $root, $projectId] = $this->paneAgreementGraph();
        $this->pane($pdo, $root, ['component' => 'App\\Target', 'kind' => 'check'], execute: true);
        $this->pane($pdo, $root, ['component' => 'App\\User', 'kind' => 'check'], execute: true);
        $queries = ArchitectureQueryService::forDatabase($pdo);

        $queries->upsertAnnotation($projectId, 'App\\User', 'note', 'Calls Target once per request.', execute: true);
        $queries->upsertAnnotation($projectId, 'App\\Target', 'note', 'Built once per request; checked again.', execute: true, replace: true);

        assertSame([], self::checks($pdo, $projectId, 'App\\Target'));
        assertSame([], self::checks($pdo, $projectId, 'App\\User'));
        $this->pane($pdo, $root, ['component' => 'App\\User', 'kind' => 'check'], execute: true);
        $queries->upsertAnnotation($projectId, 'App\\Target', 'note', 'Built once per request; checked again.', execute: true);
        assertSame([['user', '']], self::checks($pdo, $projectId, 'App\\User'));
    }

    public function testAPreviewOrARefusedModelNoteLeavesTheCheck(): void
    {
        [$pdo, $root, $projectId] = $this->paneAgreementGraph();
        $this->pane($pdo, $root, ['component' => 'App\\Target', 'kind' => 'check'], execute: true);
        $queries = ArchitectureQueryService::forDatabase($pdo);

        $queries->upsertAnnotation($projectId, 'App\\Target', 'note', 'A preview.');
        $refused = $queries->upsertAnnotation($projectId, 'App\\Target', 'note', 'Different text, no replace.', execute: true);

        assertSame('refused', $refused->data['action']);
        assertSame([['user', '']], self::checks($pdo, $projectId, 'App\\Target'));
    }

    /**
     * A result about the component carries the request in data.notes, a
     * check without a reason too, so the agent sees it where it looks.
     */
    public function testAResultAboutTheComponentCarriesTheCheck(): void
    {
        [$pdo, $root, $projectId] = $this->paneAgreementGraph();
        $this->pane($pdo, $root, ['component' => 'App\\Target', 'kind' => 'check'], execute: true);

        $notes = ArchitectureQueryService::forDatabase($pdo)->findComponent($projectId, 'App\\Target')->data['notes'];

        assertSame([['App\\Target', 'check', 'user', '']], array_map(static fn(array $note): array => [$note['component'], $note['kind'], $note['author'], $note['text']], array_values(array_filter($notes, static fn(array $note): bool => $note['kind'] === 'check'))));
    }

    /**
     * The pane's `annotate` command, as the wrapper passes it.
     *
     * @param array<string, string> $options
     * @return array<string, mixed>
     */
    private function pane(PDO $pdo, string $root, array $options, bool $execute = false): array
    {
        return (new PaneQueries($pdo))->answer('annotate', $root, static fn(string $name): ?string => $options[$name] ?? null, static fn(string $name): bool => $name === 'execute' && $execute);
    }

    /**
     * The `check` rows on a component, as [author, value].
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function checks(PDO $pdo, string $projectId, string $component): array
    {
        $statement = $pdo->prepare("SELECT author, value FROM annotations WHERE project_id = ? AND canonical_name = ? AND kind = 'check'");
        $statement->execute([$projectId, $component]);

        return array_map(static fn(array $row): array => [(string) $row[0], (string) $row[1]], $statement->fetchAll(PDO::FETCH_NUM));
    }
}

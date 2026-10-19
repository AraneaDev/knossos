<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * What a search term matches in a component's attributes, and how a name
 * that starts with the query ranks: the words an attribute holds, never the
 * attribute's name or the scanner's own bookkeeping, and a name starting
 * with the query ahead of one holding it further in.
 */
#[Group('query')]
final class SearchMatchingTest extends KnossosTestCase
{
    public function testATermMatchesAttributeValuesNotTheirNames(): void
    {
        [$pdo, $projectId] = $this->graph([
            'App\\Route' => ['uri' => '/orders/static', 'scanner' => 'knossos.php', 'scanner_local_id' => 'php:class:App\\Route'],
            'App\\Plain' => ['static' => false, 'php_attributes' => [], 'scanner' => 'knossos.php', 'scanner_local_id' => 'php:class:App\\Plain'],
        ]);
        $search = ArchitectureQueryService::forDatabase($pdo);

        assertSame(['App\\Route'], array_column($search->searchArchitecture($projectId, 'static')->data['results'], 'canonical_name'));
        assertSame([], $search->searchArchitecture($projectId, 'knossos')->data['results']);
        assertSame([], $search->searchArchitecture($projectId, '_')->data['results']);
    }

    public function testANumberAnAttributeHoldsMatchesButATrueOrFalseDoesNot(): void
    {
        [$pdo, $projectId] = $this->graph(['App\\Server' => ['port' => 8080, 'ratio' => 0.75], 'App\\Flagged' => ['final' => true]]);
        $search = ArchitectureQueryService::forDatabase($pdo);

        assertSame(['App\\Server'], array_column($search->searchArchitecture($projectId, '8080')->data['results'], 'canonical_name'));
        assertSame(['App\\Server'], array_column($search->searchArchitecture($projectId, '0.75')->data['results'], 'canonical_name'));
        assertSame([], $search->searchArchitecture($projectId, 'true')->data['results']);
    }

    public function testANameStartingWithTheQueryRanksAheadOfOneHoldingItFurtherIn(): void
    {
        [$pdo, $projectId] = $this->graph(['App\\Ggree' => [], 'App\\Greeter' => []]);

        $names = array_column(ArchitectureQueryService::forDatabase($pdo)->searchArchitecture($projectId, 'gre')->data['results'], 'canonical_name');

        assertSame(['App\\Greeter', 'App\\Ggree'], $names);
    }

    /**
     * With files asked for, the files whose path holds every term are listed
     * apart, and a query naming a file no component matches finds what that
     * file declares; without, neither.
     */
    public function testFilesAreListedAndAFileNamesWhatItDeclaresWhenAskedFor(): void
    {
        [$pdo, $projectId] = $this->graph(['App\\Route' => [], 'App\\Plain' => []]);
        $search = ArchitectureQueryService::forDatabase($pdo);

        $files = $search->searchArchitecture($projectId, 'src route', includeFiles: true)->data;
        $declared = $search->searchArchitecture($projectId, 'src/Plain.php', includeFiles: true)->data;
        $plain = $search->searchArchitecture($projectId, 'src/Plain.php')->data;

        assertSame([['src/Route.php'], false], [$files['files'], $files['files_truncated']]);
        assertSame(['declared_in', 'src/Plain.php', ['App\\Plain']], [$declared['match'], $declared['declared_in'], array_column($declared['results'], 'canonical_name')]);
        assertSame([[], false, false], [$plain['results'], isset($plain['files']), isset($plain['declared_in'])]);
    }

    /**
     * A project of classes, each with the attributes given.
     *
     * @param array<string, array<string, mixed>> $classes
     * @return array{0: \PDO, 1: string}
     */
    private function graph(array $classes): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $pdo->exec('DELETE FROM edges');
        $pdo->exec('DELETE FROM nodes');
        foreach ($classes as $name => $attributes) {
            $path = 'src/' . substr($name, 4) . '.php';
            $file = StableId::file($ids['project'], $path);
            $repository->saveFile($file, $ids['project'], $path, hash('sha256', $path), 20, 1, 'php', '0.1.0', $ids['scan']);
            $repository->saveNode(StableId::symbol($ids['project'], 'php', 'class', $name), $ids['project'], 'php', 'class', $name, substr($name, 4), null, $file, 1, 10, 'ast', 'certain', $attributes, 'php:file:' . $path, $ids['scan']);
        }
        $repository->completeScan($ids['project'], $ids['scan']);

        return [$pdo, $ids['project']];
    }
}

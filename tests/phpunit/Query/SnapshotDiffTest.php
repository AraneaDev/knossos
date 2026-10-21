<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A component that moved to another file and changed along the way (its
 * lines, at least) was listed under `changed` and again under `moved`, and
 * counted twice in `total_changes`. A move is now one change, and its record
 * still shows every field that changed.
 */
final class SnapshotDiffTest extends KnossosTestCase
{
    #[Group('query')]
    public function testAComponentMovedAndEditedIsCountedOnce(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $repository->completeScan($project, $ids['scan']);
        $repository->archiveActiveSnapshot($project, hash('sha256', '{}'), 5);
        $next = StableId::scan($project, 'scan-2');
        $repository->createScan($next, $project, 'incremental', hash('sha256', 'scanner-set'));
        $file = StableId::file($project, 'src/InvoiceService.php');
        $repository->saveFile($file, $project, 'src/InvoiceService.php', hash('sha256', 'invoice'), 40, 1, 'php', '0.1.0', $next);
        $before = $pdo->query("SELECT start_line FROM nodes WHERE id = '" . $ids['invoice'] . "'")->fetchColumn();
        $repository->saveNode($ids['invoice'], $project, 'php', 'class', 'App\\InvoiceService', 'InvoiceService', null, $file, 3, 17, 'ast', 'certain', [], 'php:file:src/InvoiceService.php', $next);
        $repository->completeScan($project, $next);

        $diff = ArchitectureQueryService::forDatabase($pdo)->services()->diffQueries->snapshotDiff($project, $ids['scan']);

        $counts = $diff->data['changes']['components']['counts'];
        self::assertSame(0, $counts['changed']);
        self::assertSame(1, $counts['moved']);
        $other = 0;
        foreach ($diff->data['changes'] as $name => $section) {
            $other += $name === 'components' ? 0 : $section['counts']['added'] + $section['counts']['removed'] + $section['counts']['changed'];
        }
        $components = $counts['added'] + $counts['removed'] + $counts['changed'] + $counts['moved'];
        self::assertSame($other + $components, $diff->data['bounds']['total_changes'], 'The moved component adds 1 to the total, not 2.');
        $moved = $diff->data['changes']['components']['moved'][0];
        self::assertSame((int) $before, (int) $moved['before']['start_line']);
        self::assertSame(3, $moved['after']['start_line'], 'The move record still shows the edit.');
        self::assertSame('src/InvoiceService.php', $moved['after']['path']);
        self::assertContains('start_line', $moved['changed_fields']);
    }

    /**
     * An edge is identified by where it is written, so inserting a line above a
     * call used to report the call removed and added again. A relationship that
     * only moved is listed under `moved`, with its old and new line.
     */
    #[Group('query')]
    public function testACallThatOnlyMovedIsMovedNotRemovedAndAdded(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-diff-moved-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o700, true);
        $php = static fn(string $body): string => "<?php\n\nnamespace App;\n\n" . $body;
        file_put_contents($root . '/composer.json', "{\"name\": \"demo/diff\", \"autoload\": {\"psr-4\": {\"App\\\\\": \"src/\"}}}\n");
        file_put_contents($root . '/src/Helper.php', $php("final class Helper\n{\n    public static function shine(): string\n    {\n        return '';\n    }\n\n    public static function dim(): string\n    {\n        return '';\n    }\n}\n"));
        $caller = static fn(string $extra): string => $php("final class Caller\n{\n    public function run(): string\n    {\n{$extra}        Helper::shine();\n\n        return Helper::dim();\n    }\n}\n");
        file_put_contents($root . '/src/Caller.php', $caller(''));
        $pdo = $this->freshTestDatabase();
        try {
            $scanner = new \Knossos\Scan\ProjectScanService($pdo, self::repositoryRoot(), [$root]);
            $first = $scanner->scan($root);
            file_put_contents($root . '/src/Caller.php', $caller("        \$unused = 1;\n\n"));
            $scanner->scan($root);

            $diff = ArchitectureQueryService::forDatabase($pdo)->services()->diffQueries->snapshotDiff($first->projectId, $first->snapshotId);

            $calls = static fn(string $kind): array => array_values(array_filter(
                $diff->data['changes']['relationships'][$kind],
                static fn(array $change): bool => (($change['after'] ?? $change['before'])['kind'] ?? null) === 'calls',
            ));
            self::assertSame([], $calls('added'));
            self::assertSame([], $calls('removed'));
            self::assertCount(2, $calls('moved'));
            $moved = $calls('moved')[0];
            self::assertSame(2, $moved['after']['start_line'] - $moved['before']['start_line']);
            self::assertContains('start_line', $moved['changed_fields']);
            self::assertSame(count($diff->data['changes']['relationships']['moved']), $diff->data['changes']['relationships']['counts']['moved']);
            self::assertSame(0, $diff->data['changes']['relationships']['counts']['added'] + $diff->data['changes']['relationships']['counts']['removed']);

            // A second call to the same method is a new occurrence: one added, the first kept.
            file_put_contents($root . '/src/Caller.php', $caller("        \$unused = 1;\n\n        Helper::shine();\n"));
            $second = $scanner->scan($root);
            $again = ArchitectureQueryService::forDatabase($pdo)->services()->diffQueries->snapshotDiff($first->projectId, $first->snapshotId, $second->snapshotId);
            $added = array_values(array_filter($again->data['changes']['relationships']['added'], static fn(array $change): bool => $change['after']['kind'] === 'calls'));
            self::assertCount(1, $added);
            self::assertSame([], array_values(array_filter($again->data['changes']['relationships']['removed'], static fn(array $change): bool => $change['before']['kind'] === 'calls')));
        } finally {
            unset($pdo);
            $this->removeTempTree($root);
        }
    }
}

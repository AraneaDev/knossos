<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A CI script or a workflow is a project file Knossos does not scan. Asking
 * about one answered "not in the graph", and a note on one warned that the
 * component was not found, a "dynamic or upcoming symbol", though the next
 * scan turns any noted project file into a file component.
 */
final class UnscannedProjectFileTest extends KnossosTestCase
{
    #[Group('query')]
    public function testANoteOnAnUnscannedProjectFileSaysItBecomesAComponent(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        try {
            mkdir($root . '/tools');
            file_put_contents($root . '/tools/quality', "#!/bin/sh\necho ok\n");

            $result = ArchitectureQueryService::forDatabase($pdo)->upsertAnnotation($projectId, 'tools/quality', 'note', 'runs every lane', execute: true);

            $warnings = implode(' ', $result->warnings);
            self::assertStringContainsString('tools/quality is a project file', $warnings);
            self::assertStringContainsString('becomes a file component on the next scan', $warnings);
            self::assertStringNotContainsString('dynamic or upcoming symbol', $warnings);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A name that is no file keeps the old warning. */
    #[Group('query')]
    public function testANoteOnAMissingNameKeepsTheNotFoundWarning(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        try {
            $result = ArchitectureQueryService::forDatabase($pdo)->upsertAnnotation($projectId, 'App\\Future', 'note', 'coming soon', execute: true);

            self::assertStringContainsString('dynamic or upcoming symbol', implode(' ', $result->warnings));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testFileContextAnswersForAnUnscannedProjectFile(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        try {
            mkdir($root . '/tools');
            file_put_contents($root . '/tools/quality', "#!/bin/sh\necho ok\n");

            $result = ArchitectureQueryService::forDatabase($pdo)->fileContext($projectId, 'tools/quality');

            self::assertSame('not_scanned', $result->data['status']);
            self::assertSame('tools/quality', $result->data['path']);
            self::assertSame([], $result->data['commits'], 'No git here, so no commits; the key is still there.');
            self::assertStringContainsString('a project file Knossos does not scan', $result->summary);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A path that is not a file at all is still not found. */
    #[Group('query')]
    public function testFileContextOnAMissingPathIsNotFound(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        try {
            self::assertSame('not-found', ArchitectureQueryService::forDatabase($pdo)->fileContext($projectId, 'tools/nothing')->data['status']);
        } finally {
            $this->removeTempTree($root);
        }
    }
}

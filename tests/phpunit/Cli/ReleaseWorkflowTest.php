<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The release gate runs the suite once and says what it runs. */
final class ReleaseWorkflowTest extends KnossosTestCase
{
    #[Group('packaging')]
    public function testTheReleaseGateRunsPhpunitOnlyUnderCoverage(): void
    {
        $workflow = (string) file_get_contents(self::repositoryRoot() . '/.github/workflows/release-please.yml');

        self::assertStringContainsString('KNOSSOS_PHPUNIT_IN_COVERAGE=1', $workflow, 'The coverage lane already runs the whole suite; the tests lane must not repeat it.');
        self::assertStringNotContainsString('mutation included', $workflow, 'No quality profile runs mutation testing.');
    }

    /**
     * The release gate built both images from nothing, uncached, while the
     * push to main that cut the release publishes them under the same key.
     * It now reuses them when they are there and builds only when not.
     */
    #[Group('packaging')]
    public function testTheReleaseGateReusesThePublishedDependencyImages(): void
    {
        $release = (string) file_get_contents(self::repositoryRoot() . '/.github/workflows/release-please.yml');
        $quality = (string) file_get_contents(self::repositoryRoot() . '/.github/workflows/quality.yml');

        foreach (['release-please.yml' => $release, 'quality.yml' => $quality] as $name => $workflow) {
            self::assertStringContainsString('tools/dependency-image-key', $workflow, $name . ' must key the images the same way as the other workflow.');
        }
        self::assertStringContainsString('uses: ./.github/actions/quality-image', $release);
        self::assertStringContainsString('KNOSSOS_RUNTIME_IMAGE_GIVEN=1', $release);
        self::assertStringContainsString('packages: read', $release);
    }

    /** One place says what the dependency images are called today. */
    #[Group('packaging')]
    public function testTheDependencyImageKeyNamesBothImages(): void
    {
        $output = [];
        exec('cd ' . escapeshellarg(self::repositoryRoot()) . ' && KNOSSOS_SECURITY_STAMP=abc123 tools/dependency-image-key 2>&1', $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
        $lines = [];
        foreach ($output as $line) {
            [$key, $value] = explode('=', $line, 2) + [1 => ''];
            $lines[$key] = $value;
        }
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}-abc123$/', $lines['refresh'] ?? '');
        self::assertStringStartsWith('ghcr.io/araneadev/knossos:quality-deps-', $lines['quality'] ?? '');
        self::assertStringStartsWith('ghcr.io/araneadev/knossos:runtime-deps-', $lines['runtime'] ?? '');
        self::assertStringEndsWith('-' . $lines['refresh'], $lines['quality']);
    }
}

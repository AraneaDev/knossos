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

    /**
     * Exactly one way to the quality image runs: the published images when
     * they are there, a full build when not. The two steps' conditions are
     * each other's opposite, and the released image is the runtime image
     * either way.
     */
    #[Group('packaging')]
    public function testTheReleaseGateRunsExactlyOneImagePath(): void
    {
        $steps = self::steps((string) file_get_contents(self::repositoryRoot() . '/.github/workflows/release-please.yml'));

        $reuse = self::step($steps, 'uses: ./.github/actions/quality-image');
        $build = self::step($steps, 'name: Build pinned quality image');
        self::assertStringContainsString("steps.published.outputs.reuse == 'true'", self::condition($reuse));
        self::assertStringContainsString("steps.published.outputs.reuse != 'true'", self::condition($build));
        self::assertStringContainsString('KNOSSOS_RUNTIME_IMAGE_GIVEN=1', self::step($steps, 'name: Run full quality profile'));
        self::assertStringContainsString('docker tag knossos:quality-runtime knossos:release', self::step($steps, 'name: Verify the released runtime image reports its version'));
    }

    /**
     * Only a pull request tools/ci/release-pr calls a release skips the heavy
     * lanes and the coverage jobs. The branch name decided it before, and any
     * branch of that shape, a fork's included, skipped them.
     */
    #[Group('packaging')]
    public function testOnlyTheReleasePrVerdictSkipsTheHeavyLanes(): void
    {
        $quality = (string) file_get_contents(self::repositoryRoot() . '/.github/workflows/quality.yml');

        self::assertStringNotContainsString('release-please--branches--', $quality, 'A branch name must not decide which lanes run.');
        self::assertStringNotContainsString('head_ref, \'release-please', $quality);

        $decide = self::job($quality, 'release-pr');
        self::assertStringContainsString('tools/ci/release-pr --repo "$GITHUB_REPOSITORY" --head-repo "$HEAD_REPO"', $decide);
        self::assertStringContainsString('HEAD_REPO: ${{ github.event.pull_request.head.repo.full_name }}', $decide);
        self::assertStringContainsString('gh pr diff "$PR"', $decide);
        self::assertStringContainsString('pull-requests: read', $decide);
        self::assertStringNotContainsString('write', $decide, 'Deciding reads the pull request and writes nothing.');

        $verdict = "needs.release-pr.outputs.release == 'true'";
        $notRelease = "needs.release-pr.outputs.release != 'true'";
        self::assertStringContainsString($verdict, self::job($quality, 'lane'));
        foreach (['lane', 'coverage-shard', 'coverage', 'quality'] as $job) {
            self::assertMatchesRegularExpression('/^    needs: \[[^\]]*\brelease-pr\b/m', self::job($quality, $job), $job . ' must read the release-pr verdict.');
        }
        foreach (['coverage-shard', 'coverage'] as $job) {
            self::assertStringContainsString('if: github.event_name != \'schedule\' && ' . $notRelease, self::job($quality, $job));
        }
    }

    /** The required check's verdict is the tested script, not inline shell. */
    #[Group('packaging')]
    public function testTheQualityJobUsesTheVerdictScript(): void
    {
        $job = self::job((string) file_get_contents(self::repositoryRoot() . '/.github/workflows/quality.yml'), 'quality');

        self::assertStringContainsString('RELEASE_PR: ${{ needs.release-pr.outputs.release }}', $job);
        self::assertStringContainsString('tools/ci/quality-verdict "$LANES" "$SHARDS" "$MERGE" --release-pr', $job);
        self::assertStringContainsString('tools/ci/quality-verdict "$LANES" "$SHARDS" "$MERGE"' . "\n", $job);
        self::assertStringNotContainsString('expected=', $job, 'The rule lives in tools/ci/quality-verdict.');
        self::assertFileExists(self::repositoryRoot() . '/tools/ci/quality-verdict');
        self::assertFileExists(self::repositoryRoot() . '/tools/ci/release-pr');
    }

    /** One job of a workflow, from its key to the next job's. */
    private static function job(string $workflow, string $name): string
    {
        self::assertSame(1, preg_match('/^  ' . preg_quote($name, '/') . ':\n(.*?)(?=^  [a-z]|\z)/ms', $workflow, $match), 'No job ' . $name . '.');

        return $match[1];
    }

    /**
     * The steps of the workflow's one job, each as its own text, split where a
     * step begins: a `-` at the steps list's indent.
     *
     * @return list<string>
     */
    private static function steps(string $workflow): array
    {
        self::assertSame(1, preg_match('/^    steps:\n(.*)\z/ms', $workflow, $match), 'The job lists no steps.');
        $parts = preg_split('/^(?=      - )/m', $match[1]) ?: [];

        return array_values(array_filter($parts, static fn(string $part): bool => str_starts_with($part, '      - ')));
    }

    /** @param list<string> $steps */
    private static function step(array $steps, string $marker): string
    {
        $found = array_values(array_filter($steps, static fn(string $step): bool => str_contains($step, $marker)));
        self::assertCount(1, $found, 'One step holds ' . $marker);

        return $found[0];
    }

    private static function condition(string $step): string
    {
        self::assertSame(1, preg_match('/^\s+(?:- )?if: (.+)$/m', $step, $match), 'The step has no if:.');

        return $match[1];
    }
}

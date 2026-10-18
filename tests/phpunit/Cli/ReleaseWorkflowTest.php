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
        self::assertStringContainsString('HEAD_REPO: ${{ github.event.pull_request.head.repo.full_name }}', $decide);
        self::assertStringContainsString('LABELS: ${{ toJSON(github.event.pull_request.labels.*.name) }}', $decide);
        self::assertStringNotContainsString('write', $decide, 'Deciding reads the pull request and writes nothing.');

        // The script and its config come from the base commit, never from the
        // pull request being judged.
        self::assertStringContainsString("ref: \${{ github.event.pull_request.base.sha }}\n          path: .base\n", $decide);
        self::assertStringContainsString('BASE_SHA: ${{ github.event.pull_request.base.sha }}', $decide);
        self::assertStringContainsString('sh .base/tools/ci/release-pr --repo "$GITHUB_REPOSITORY" --head-repo "$HEAD_REPO"', $decide);
        self::assertStringContainsString('--config .base/release-please-config.json', $decide);
        self::assertSame(1, preg_match_all('#tools/ci/release-pr --#', $decide), 'Only the base copy runs.');
        self::assertStringContainsString('if [ ! -f .base/tools/ci/release-pr ]; then', $decide);

        // The diff judged is the merge commit the lanes test, against the base.
        self::assertStringContainsString('git diff --no-ext-diff --no-renames --name-only "$BASE_SHA" HEAD > "$dir/files"', $decide);
        self::assertStringContainsString('git diff --no-ext-diff --no-renames --unified=10 "$BASE_SHA" HEAD > "$dir/diff"', $decide);
        self::assertStringNotContainsString('gh pr', $decide);

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
        self::assertStringContainsString('sh "$verdict" "$LANES" "$SHARDS" "$MERGE" --release-pr', $job);
        self::assertStringContainsString('sh "$verdict" "$LANES" "$SHARDS" "$MERGE"' . "\n", $job);
        self::assertStringNotContainsString('expected=', $job, 'The rule lives in tools/ci/quality-verdict.');

        // On a pull request the base commit's copy decides; without one, only
        // all three results success pass.
        self::assertStringContainsString("ref: \${{ github.event.pull_request.base.sha }}\n          path: .base\n", $job);
        self::assertStringContainsString('verdict=.base/tools/ci/quality-verdict', $job);
        self::assertStringContainsString("[ \"\$LANES \$SHARDS \$MERGE\" = 'success success success' ]\n              exit\n", $job);

        // The pre-check that depends on no script: exactly these three shapes.
        self::assertStringContainsString("case \"\$RELEASE_PR|\$LANES \$SHARDS \$MERGE\" in\n            'true|success skipped skipped' | 'false|success success success' | '|success success success') ;;\n            *)\n", $job);
        self::assertLessThan(strpos($job, 'verdict=tools/ci/quality-verdict'), strpos($job, 'case "$RELEASE_PR|'), 'The pre-check runs before any script.');
        self::assertFileExists(self::repositoryRoot() . '/tools/ci/quality-verdict');
        self::assertFileExists(self::repositoryRoot() . '/tools/ci/release-pr');
    }

    /**
     * The title and every commit are checked in CI mode, and the release
     * subject is allowed only when tools/ci/release-pr said so.
     */
    #[Group('packaging')]
    public function testThePrTitleCheckRunsInCiModeAndAsksReleasePr(): void
    {
        $workflow = (string) file_get_contents(self::repositoryRoot() . '/.github/workflows/pr-title.yml');

        self::assertStringContainsString('tools/ci/release-pr --repo "$GITHUB_REPOSITORY" --head-repo "$HEAD_REPO"', $workflow);
        self::assertStringContainsString("0) echo 'flag=--release-pr' >> \"\$GITHUB_OUTPUT\" ;;", $workflow);
        self::assertSame(2, substr_count($workflow, 'RELEASE_PR: ${{ steps.release.outputs.flag }}'));
        self::assertSame(2, substr_count($workflow, 'bash tools/check-commit-style.sh --ci ${RELEASE_PR:+"$RELEASE_PR"} -- '));
        self::assertSame(2, substr_count($workflow, 'tools/check-commit-style.sh'), 'Every call of the check runs in CI mode.');
        self::assertStringContainsString('types: [opened, edited, reopened, synchronize, labeled, unlabeled]', $workflow);
        self::assertStringNotContainsString('pull-requests:', $workflow, 'The inputs come from git and the event, not the API.');

        // The same inputs quality.yml uses: labels from the event, the diff
        // from git with wide context, never the API's three lines.
        self::assertStringContainsString('LABELS: ${{ toJSON(github.event.pull_request.labels.*.name) }}', $workflow);
        self::assertStringContainsString('git diff --no-ext-diff --no-renames --name-only "$base" "$head" > "$dir/files"', $workflow);
        self::assertStringContainsString('git diff --no-ext-diff --no-renames --unified=10 "$base" "$head" > "$dir/diff"', $workflow);
        self::assertStringNotContainsString('gh pr', $workflow);

        // pull_request_target runs trusted: the only checkout is the base
        // branch, the pull request arrives only through git fetch, and nothing
        // moves the work tree onto it.
        self::assertSame(1, substr_count($workflow, 'uses: actions/checkout@'));
        self::assertStringContainsString('ref: ${{ github.event.pull_request.base.ref }}', $workflow);
        self::assertDoesNotMatchRegularExpression('/\\bgit (checkout|switch|restore|reset|worktree|merge|rebase|cherry-pick|apply|am|stash)(?=\\s|$)/m', $workflow);
        self::assertDoesNotMatchRegularExpression('/github\\.event\\.pull_request\\.head\\.(sha|ref)/', $workflow);
        self::assertSame(2, substr_count($workflow, 'git fetch --quiet'), 'The head is fetched as data, once per step that reads it.');
    }

    /**
     * The rollback runs after anything, hands the job's status to the tested
     * script, and the script alone maps that status to an action.
     */
    #[Group('packaging')]
    public function testTheReleaseRollbackIsTheTestedScript(): void
    {
        $workflow = (string) file_get_contents(self::repositoryRoot() . '/.github/workflows/release-please.yml');
        $step = self::step(self::steps($workflow), 'name: Remove the draft release and re-open it when its gate did not pass');

        self::assertSame('${{ always() && steps.release.outputs.release_created }}', self::condition($step));
        self::assertStringContainsString('GATE: ${{ job.status }}', $step);
        self::assertStringContainsString('tools/ci/release-rollback --release-created "$CREATED" --gate-result "$GATE"', $step);
        self::assertStringNotContainsString('--dry-run', $step);
        self::assertStringNotContainsString('labels', $step, 'Relabelling lives in tools/ci/release-rollback.');
        self::assertFileExists(self::repositoryRoot() . '/tools/ci/release-rollback');
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

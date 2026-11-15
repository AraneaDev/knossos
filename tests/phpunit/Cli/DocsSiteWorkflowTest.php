<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The documentation site is built on pull requests and deployed after a
 * release, and a failed build or deploy must never touch the release it follows.
 */
final class DocsSiteWorkflowTest extends KnossosTestCase
{
    #[Group('packaging')]
    public function testTheReleaseOnlyStartsTheSiteForAReleaseThatIsPublished(): void
    {
        $job = $this->job($this->release(), 'docs-site');

        self::assertStringContainsString('needs: [release-please, assets]', $job);
        self::assertStringContainsString('!inputs.dry_run', $job);
        self::assertStringContainsString("needs.release-please.outputs.release_created == 'true'", $job);
        // `assets` ends green after rolling a release back, so its result is not proof of a published release.
        // The job asks GitHub, so it stays right however many legs the release gains.
        self::assertStringContainsString('gh release view "$TAG" --repo "$GITHUB_REPOSITORY" --json isDraft --jq .isDraft', $job);
        self::assertStringContainsString('if [ "$draft" != false ]; then', $job);
        self::assertLessThan(strpos($job, 'gh workflow run docs-site.yml'), strpos($job, 'if [ "$draft" != false ]; then'));
        self::assertStringContainsString('gh workflow run docs-site.yml --repo "$GITHUB_REPOSITORY" --ref main -f ref="$TAG"', $job);
        self::assertStringContainsString('TAG: ${{ needs.release-please.outputs.tag_name }}', $job);
    }

    #[Group('packaging')]
    public function testNothingInTheReleaseCanSeeTheSite(): void
    {
        $workflow = $this->release();
        $job = $this->job($workflow, 'docs-site');

        self::assertStringContainsString('continue-on-error: true', $job, 'a failed dispatch cannot fail the run of a published release');
        self::assertStringNotContainsString('cancelled()', $job);
        self::assertStringNotContainsString('always()', $job);
        // No other job waits on it, and the rollback never reads it.
        foreach (['release-please', 'tarballs', 'assets', 'release-pr'] as $name) {
            $code = $this->withoutComments($this->job($workflow, $name));
            self::assertStringNotContainsString('docs-site', $code, $name);
            self::assertStringNotContainsString('pages', $code, $name);
        }
        // The site is built and deployed in its own workflow run, not in the release run's queue.
        self::assertStringNotContainsString('pages: write', $workflow);
        self::assertStringNotContainsString('deploy-pages', $workflow);
    }

    #[Group('packaging')]
    public function testAFailureToStartTheSiteIsReportedAndNothingElse(): void
    {
        $job = $this->job($this->release(), 'docs-site');

        self::assertStringContainsString("permissions:\n      actions: write", $job);
        self::assertStringNotContainsString('contents: write', $job);
        self::assertStringContainsString('if: ${{ failure() }}', $job);
        self::assertStringContainsString('::warning::The documentation site for $TAG was not published. Run the Documentation site workflow from main with ref $TAG.', $job);
        self::assertStringContainsString('>> "$GITHUB_STEP_SUMMARY"', $job);
    }

    #[Group('packaging')]
    public function testThePullRequestWorkflowBuildsAndOnlyAManualRunFromMainDeploys(): void
    {
        $workflow = $this->file('.github/workflows/docs-site.yml');

        self::assertStringContainsString("pull_request:\n    paths:\n      - \"docs/**\"\n      - \"website/**\"", $workflow);
        self::assertStringContainsString("workflow_dispatch:\n    inputs:\n      ref:", $workflow);
        self::assertStringContainsString("permissions:\n  contents: read", $workflow);
        $deploy = $this->job($workflow, 'deploy');
        self::assertStringContainsString("github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main'", $deploy);
        self::assertStringContainsString("pages: write\n      id-token: write", $deploy);
        self::assertStringContainsString("group: pages\n      cancel-in-progress: false", $deploy);
        self::assertSame(1, substr_count($workflow, 'pages: write'));
        // The same build steps as the release: one composite action.
        self::assertStringContainsString('uses: ./.github/actions/docs-site', $workflow);
        self::assertStringContainsString('failure() && github.event_name == \'workflow_dispatch\'', $workflow);
        self::assertSame(2, substr_count($workflow, '>> "$GITHUB_STEP_SUMMARY"'));
        self::assertStringContainsString("upload: \${{ github.event_name == 'workflow_dispatch' && 'true' || 'false' }}", $workflow);
    }

    #[Group('packaging')]
    public function testEveryActionIsPinnedByCommitWithItsVersionInAComment(): void
    {
        foreach (['.github/workflows/docs-site.yml', '.github/actions/docs-site/action.yml', '.github/workflows/release-please.yml'] as $path) {
            preg_match_all('#^\s*(?:- )?uses: (\S+)(.*)$#m', $this->file($path), $uses, PREG_SET_ORDER);
            self::assertNotEmpty($uses, $path);
            foreach ($uses as $use) {
                if (!str_starts_with($use[1], './')) {
                    self::assertMatchesRegularExpression('/@[0-9a-f]{40}$/', $use[1], $path);
                    self::assertMatchesRegularExpression('/^\s+# v\d+\.\d+\.\d+$/', $use[2], $path . ': ' . $use[1]);
                }
            }
        }
    }

    #[Group('packaging')]
    public function testTheBuildActionPutsNoExpressionInAShellScript(): void
    {
        $action = $this->file('.github/actions/docs-site/action.yml');

        // The ref arrives as an environment variable, never spliced into a script.
        preg_match_all('/^\s+run: (.+)$/m', $action, $runs);
        self::assertNotEmpty($runs[1]);
        foreach ($runs[1] as $run) {
            self::assertStringNotContainsString('${{', $run);
        }
        self::assertSame(2, substr_count($action, 'KNOSSOS_DOCS_REF: ${{ inputs.docs-ref }}'));
        self::assertStringContainsString('npm --prefix website ci --ignore-scripts', $action);
        self::assertStringContainsString('path: website/.vitepress/dist', $action);
    }

    /** A job's text without its comment lines, which may describe the jobs that follow it. */
    private function withoutComments(string $job): string
    {
        return (string) preg_replace('/^\s*#.*$/m', '', $job);
    }

    private function release(): string
    {
        return $this->file('.github/workflows/release-please.yml');
    }

    private function file(string $path): string
    {
        return (string) file_get_contents(self::repositoryRoot() . '/' . $path);
    }

    /** One job's text, from its key to the next job. */
    private function job(string $workflow, string $name): string
    {
        $start = strpos($workflow, "\n  " . $name . ":\n");
        self::assertNotFalse($start, 'job ' . $name);
        $end = preg_match('/\n  [a-z-]+:\n/', $workflow, $match, PREG_OFFSET_CAPTURE, $start + 5) === 1 ? $match[0][1] : strlen($workflow);

        return substr($workflow, $start, $end - $start);
    }
}

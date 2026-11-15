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
    public function testTheDeployJobsAreSkippedUnlessARealReleaseWasPublished(): void
    {
        $build = $this->job($this->release(), 'docs-site');

        self::assertStringContainsString('needs: [release-please, assets]', $build);
        self::assertStringContainsString('!inputs.dry_run', $build);
        self::assertStringContainsString("needs.release-please.outputs.release_created == 'true'", $build);
        // `assets` is the job that publishes; a rolled-back release has no site.
        self::assertStringContainsString("needs.assets.result == 'success'", $build);
        self::assertStringContainsString('ref: ${{ needs.release-please.outputs.sha }}', $build);
        self::assertStringContainsString('docs-ref: ${{ needs.release-please.outputs.tag_name }}', $build);
        self::assertStringContainsString('needs: [docs-site]', $this->job($this->release(), 'docs-deploy'));
    }

    #[Group('packaging')]
    public function testNothingInTheReleaseCanSeeTheSite(): void
    {
        $workflow = $this->release();

        foreach (['docs-site', 'docs-deploy'] as $name) {
            $job = $this->job($workflow, $name);
            self::assertStringContainsString('continue-on-error: true', $job, $name . ' cannot fail the run of a published release');
            self::assertStringNotContainsString('always()', $job);
            self::assertStringNotContainsString('cancelled()', $job);
        }
        // No other job waits on them, and the rollback never reads them.
        foreach (['release-please', 'tarballs', 'assets', 'release-pr'] as $name) {
            $job = $this->withoutComments($this->job($workflow, $name));
            self::assertStringNotContainsString('docs-site', $job, $name);
            self::assertStringNotContainsString('docs-deploy', $job, $name);
            self::assertStringNotContainsString('pages', $job, $name);
        }
        self::assertStringNotContainsString('docs-', $this->withoutComments($this->job($workflow, 'release-pr')));
    }

    #[Group('packaging')]
    public function testOnlyTheDeployJobHoldsThePagesPermissions(): void
    {
        $workflow = $this->release();

        self::assertSame(1, substr_count($workflow, 'pages: write'));
        self::assertSame(1, substr_count($workflow, 'id-token: write'));
        self::assertStringContainsString("pages: write\n      id-token: write", $this->job($workflow, 'docs-deploy'));
        self::assertStringContainsString("permissions:\n      contents: read", $this->job($workflow, 'docs-site'));
        self::assertStringContainsString("group: pages\n      cancel-in-progress: false", $this->job($workflow, 'docs-deploy'));
        self::assertStringContainsString('environment:', $this->job($workflow, 'docs-deploy'));
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
        self::assertStringContainsString('uses: ./.github/actions/docs-site', $this->release());
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

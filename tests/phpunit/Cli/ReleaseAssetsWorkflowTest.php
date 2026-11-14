<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The release workflow attaches the platform tarballs to the draft, and publishes only after that. */
final class ReleaseAssetsWorkflowTest extends KnossosTestCase
{
    #[Group('packaging')]
    public function testTheDraftIsPublishedOnlyAfterTheAssetsAreAttached(): void
    {
        $workflow = $this->workflow();
        $gate = $this->job($workflow, 'release-please');
        $assets = $this->job($workflow, 'assets');

        self::assertStringNotContainsString('--draft=false', $gate, 'The gate job leaves the release a draft.');
        self::assertStringNotContainsString('release-pr', $gate);
        self::assertSame(1, substr_count($workflow, '--draft=false'));
        self::assertGreaterThan(strpos($assets, 'gh release upload'), strpos($assets, '--draft=false'));
        self::assertStringContainsString('needs: [release-please, tarballs]', $assets);
        self::assertStringContainsString('dist/* --repo', $assets);
        self::assertStringContainsString('cp ../install.sh install.sh', $assets);
    }

    #[Group('packaging')]
    public function testAnAssetFailureEndsInTheRollback(): void
    {
        $assets = $this->job($this->workflow(), 'assets');

        // Runs on a cancelled run too, and folds the tarball builds into the gate result.
        self::assertStringContainsString('if: ${{ always() && (inputs.dry_run ||', $assets);
        self::assertStringContainsString("name: Remove the draft release and re-open it when its assets or gate failed\n        if: \${{ always() && !inputs.dry_run }}", $assets);
        self::assertStringContainsString("needs.tarballs.result == 'success'", $assets);
        self::assertStringContainsString('tools/ci/release-rollback --release-created "$CREATED" --gate-result "$GATE"', $assets);
        self::assertStringContainsString('gh release delete "$TAG" --yes', $assets);
    }

    #[Group('packaging')]
    public function testTheMatrixBuildsFourPlatformsAndADryRunTouchesNoRelease(): void
    {
        $workflow = $this->workflow();
        $tarballs = $this->job($workflow, 'tarballs');

        foreach (['ubuntu-24.04', 'ubuntu-24.04-arm', 'macos-14', 'macos-13'] as $runner) {
            self::assertStringContainsString('runner: ' . $runner . ',', $tarballs);
        }
        foreach (['linux-x86_64', 'linux-arm64', 'darwin-arm64', 'darwin-x86_64'] as $platform) {
            self::assertStringContainsString('platform: ' . $platform . ' ', $tarballs);
        }
        self::assertStringContainsString("dry_run:\n        description:", $workflow);
        self::assertStringContainsString('type: boolean', $workflow);
        self::assertStringContainsString('if: ${{ !inputs.dry_run }}', $this->job($workflow, 'release-please'));
        self::assertStringContainsString('!inputs.dry_run', $this->job($workflow, 'release-pr'));
        foreach (['Attach the assets to the draft release', 'Publish the release now that its gate passed and its assets are attached'] as $step) {
            $block = substr($this->job($workflow, 'assets'), (int) strpos($this->job($workflow, 'assets'), 'name: ' . $step));
            self::assertStringContainsString("if: \${{ !inputs.dry_run && needs.tarballs.result == 'success' }}", substr($block, 0, 200));
        }
    }

    #[Group('packaging')]
    public function testEveryActionIsPinnedByCommit(): void
    {
        preg_match_all('#^\s*(?:- )?uses: (\S+)#m', $this->workflow(), $uses);
        self::assertNotEmpty($uses[1]);
        foreach ($uses[1] as $action) {
            if (!str_starts_with($action, './')) {
                self::assertMatchesRegularExpression('/@[0-9a-f]{40}$/', $action);
            }
        }
    }

    private function workflow(): string
    {
        return (string) file_get_contents(self::repositoryRoot() . '/.github/workflows/release-please.yml');
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

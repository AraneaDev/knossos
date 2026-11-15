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
        self::assertStringContainsString('needs: [release-please, tarballs, image]', $assets);
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
    public function testTheRollbackAndEverythingItNeedsRunUnderAlways(): void
    {
        $assets = $this->job($this->workflow(), 'assets');
        $checkout = substr($assets, (int) strpos($assets, 'name: Checkout'), 200);

        // Without always() a cancelled run skips the checkout, the script is missing and the Release PR is not relabelled.
        self::assertStringContainsString('if: ${{ always() }}', $checkout);
        self::assertStringContainsString('actions/checkout@', $checkout);
        self::assertSame(1, preg_match('/name: Remove the draft release.*?\n        if: \$\{\{ always\(\) && !inputs\.dry_run \}\}/s', $assets));
        self::assertStringContainsString('tools/ci/release-rollback', $assets);
        self::assertStringNotContainsString('macos-13', $this->workflow(), 'A retired runner label never gets a runner.');
        self::assertStringContainsString('must be an image GitHub currently offers', str_replace("\n      # ", ' ', $this->workflow()));
    }

    #[Group('packaging')]
    public function testTheMatrixBuildsFourPlatformsAndADryRunTouchesNoRelease(): void
    {
        $workflow = $this->workflow();
        $tarballs = $this->job($workflow, 'tarballs');

        foreach (['ubuntu-24.04', 'ubuntu-24.04-arm', 'macos-15', 'macos-15-intel'] as $runner) {
            self::assertStringContainsString('runner: ' . $runner . ',', $tarballs);
        }
        foreach (['linux-x86_64', 'linux-arm64', 'darwin-arm64', 'darwin-x86_64'] as $platform) {
            self::assertStringContainsString('platform: ' . $platform . ' ', $tarballs);
        }
        self::assertStringContainsString("dry_run:\n        description:", $workflow);
        self::assertStringContainsString('type: boolean', $workflow);
        self::assertStringContainsString('if: ${{ !inputs.dry_run }}', $this->job($workflow, 'release-please'));
        self::assertStringContainsString('!inputs.dry_run', $this->job($workflow, 'release-pr'));
        $assets = $this->job($workflow, 'assets');
        $attach = substr($assets, (int) strpos($assets, 'name: Attach the assets to the draft release'));
        self::assertStringContainsString("if: \${{ !inputs.dry_run && needs.tarballs.result == 'success' }}", substr($attach, 0, 200));
        $publish = substr($assets, (int) strpos($assets, 'name: Publish the release now that its gate passed and its assets are attached'));
        self::assertStringContainsString("if: \${{ !inputs.dry_run && needs.tarballs.result == 'success' && needs.image.result == 'success' }}", substr($publish, 0, 250));
    }

    #[Group('packaging')]
    public function testEveryTarballIsStartedAndTiedToTheTagBeforeItIsUploaded(): void
    {
        $tarballs = $this->job($this->workflow(), 'tarballs');
        $smoke = strpos($tarballs, 'name: Start the tarball and check its version');
        $upload = strpos($tarballs, 'uses: actions/upload-artifact@');
        self::assertNotFalse($smoke);
        self::assertNotFalse($upload);
        self::assertLessThan($upload, $smoke);
        $step = substr($tarballs, $smoke, $upload - $smoke);
        self::assertStringContainsString('TAG: ${{ needs.release-please.outputs.tag_name }}', $step);
        self::assertStringContainsString('if [ "$DRY_RUN" != true ] && [ "$inside" != "${TAG#v}" ]; then', $step);
        self::assertStringContainsString('"$tree/bin/knossos" version)', $step);
        self::assertStringContainsString('"$tree/bin/knossos" doctor --json', $step);
        self::assertStringContainsString('KNOSSOS_DATA_DIR="$smoke/data"', $step);
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

    #[Group('packaging')]
    public function testTheImageIsBuiltOnANativeRunnerPerArchitectureAfterTheGateAndTheTarballs(): void
    {
        $workflow = $this->workflow();
        $image = $this->job($workflow, 'image');

        self::assertStringContainsString('needs: [release-please, tarballs]', $image);
        self::assertStringContainsString("if: \${{ !cancelled() && (inputs.dry_run || (needs.release-please.result == 'success' && needs.release-please.outputs.release_created && needs.tarballs.result == 'success')) }}", $image);
        self::assertStringContainsString('- { runner: ubuntu-24.04, platform: linux/amd64, arch: amd64 }', $image);
        self::assertStringContainsString('- { runner: ubuntu-24.04-arm, platform: linux/arm64, arch: arm64 }', $image);
        self::assertStringContainsString('target: runtime', $image);
        // No emulation: each architecture builds on its own runner.
        foreach (['qemu', 'binfmt', 'linux/amd64,linux/arm64'] as $absent) {
            self::assertStringNotContainsString($absent, $workflow);
        }
    }

    #[Group('packaging')]
    public function testADryRunBuildsBothImagesAndPushesNothing(): void
    {
        $image = $this->job($this->workflow(), 'image');
        $build = $this->step($image, 'Build the image for this architecture');
        $push = $this->step($image, 'Push the image by digest');
        $login = substr($image, (int) strpos($image, 'uses: docker/login-action@'), 120);

        self::assertStringContainsString('load: true', $build);
        self::assertStringNotContainsString('push:', $build);
        self::assertStringNotContainsString('outputs:', $build);
        self::assertStringContainsString('if: ${{ !inputs.dry_run }}', $push);
        self::assertStringContainsString('push-by-digest=true,name-canonical=true,push=true', $push);
        self::assertStringContainsString('name=ghcr.io/araneadev/knossos,', $push);
        self::assertStringContainsString('if: ${{ !inputs.dry_run }}', $login);
        self::assertStringContainsString('name: image-digest-${{ matrix.arch }}', $image);
        // In the assets job, everything that touches the registry is a real release's alone.
        foreach (['Tag the image with its version', 'Check that the image can be pulled without credentials', 'Record the image digest in the release notes'] as $name) {
            self::assertStringContainsString("if: \${{ !inputs.dry_run && needs.tarballs.result == 'success' && needs.image.result == 'success' }}", $this->step($this->job($this->workflow(), 'assets'), $name));
        }
    }

    #[Group('packaging')]
    public function testEachImageIsStartedAndCarriesWhatContainerModeRuns(): void
    {
        $check = $this->step($this->job($this->workflow(), 'image'), 'Start the image and check what container mode needs');

        self::assertStringContainsString('if [ "$DRY_RUN" != true ] && [ "$said" != "Knossos ${TAG#v}" ]; then', $check);
        // The same gate as the tarballs: doctor, on the architecture's own runner, before the push.
        self::assertStringContainsString('-e KNOSSOS_DATA_DIR=/tmp/check -e KNOSSOS_ROOTS_FILE=/tmp/check/roots.json knossos:image-check doctor --json', $check);
        foreach (['/opt/knossos/launcher/knossos', '/opt/knossos/tools/launcher/idle-stop', '/opt/knossos/tools/launcher/container-update'] as $path) {
            self::assertStringContainsString('test -x ' . $path, $check);
        }
        self::assertLessThan(strpos($this->job($this->workflow(), 'image'), 'name: Push the image by digest'), strpos($this->job($this->workflow(), 'image'), 'name: Start the image'));
    }

    #[Group('packaging')]
    public function testTheImageIsTaggedAndItsDigestRecordedRightBeforeThePublish(): void
    {
        $assets = $this->job($this->workflow(), 'assets');
        $order = array_map(static fn(string $needle): int|false => strpos($assets, $needle), [
            'gh release upload',
            'docker buildx imagetools create',
            'name: Check that the image can be pulled without credentials',
            'name: Record the image digest in the release notes',
            '--draft=false',
        ]);
        self::assertNotContains(false, $order);
        $sorted = $order;
        sort($sorted);
        assertSame($sorted, $order);

        $tag = $this->step($assets, 'Tag the image with its version');
        self::assertStringContainsString('docker buildx imagetools create -t "ghcr.io/araneadev/knossos:${TAG#v}" $sources', $tag);
        // No :latest: nothing pulls it, and one left on a rolled-back release would name a build nobody released.
        self::assertStringNotContainsString('knossos:latest', $this->workflow());
        self::assertStringContainsString('for arch in amd64 arm64; do', $tag);
        self::assertStringContainsString('echo "digest=$digest" >> "$GITHUB_OUTPUT"', $tag);
        $notes = $this->step($assets, 'Record the image digest in the release notes');
        self::assertStringContainsString('DIGEST: ${{ steps.image.outputs.digest }}', $notes);
        self::assertStringContainsString('--notes-file notes.md', $notes);
        // A private package fails the release instead of shipping container installs that cannot pull.
        $anonymous = $this->step($assets, 'Check that the image can be pulled without credentials');
        self::assertStringContainsString('https://ghcr.io/token?scope=repository:araneadev/knossos:pull', $anonymous);
        self::assertStringContainsString('https://ghcr.io/v2/araneadev/knossos/manifests/${TAG#v}', $anonymous);
        self::assertStringContainsString('Make it public once in the GitHub package settings', $anonymous);
        self::assertStringNotContainsString('password', $anonymous);
        // The rollback says plainly that the version tag stays, and why that is harmless.
        self::assertStringContainsString('IMAGE_DIGEST: ${{ steps.image.outputs.digest }}', $assets);
        self::assertStringContainsString('stays in the registry. It is harmless', $assets);
        // A failed image build ends in the rollback, like a failed tarball.
        self::assertStringContainsString("needs.tarballs.result == 'success' && needs.image.result == 'success' && needs.release-please.result == 'success') && 'success' || 'failure'", $assets);
    }

    #[Group('packaging')]
    public function testOnlyTheJobsThatPushTheImageMayWritePackages(): void
    {
        $workflow = $this->workflow();

        self::assertStringContainsString("permissions:\n  contents: write\n  pull-requests: write\n  packages: read", $workflow);
        assertSame(2, preg_match_all('/^ +packages: write$/m', $workflow));
        self::assertStringContainsString("permissions:\n      contents: read\n      packages: write", $this->job($workflow, 'image'));
        self::assertStringContainsString("permissions:\n      contents: write\n      pull-requests: write\n      packages: write", $this->job($workflow, 'assets'));
        foreach (['release-please', 'tarballs', 'release-pr'] as $job) {
            assertSame(0, preg_match_all('/^ +packages: write$/m', $this->job($workflow, $job)), $job);
        }
    }

    #[Group('packaging')]
    public function testTheImageJobsUseOnlyActionsThisRepositoryAlreadyPins(): void
    {
        $others = '';
        foreach (glob(self::repositoryRoot() . '/.github/workflows/*.yml') ?: [] as $file) {
            if (!str_ends_with($file, '/release-please.yml')) {
                $others .= (string) file_get_contents($file);
            }
        }
        preg_match_all('#uses: (docker/\S+)#', $this->job($this->workflow(), 'image') . $this->job($this->workflow(), 'assets'), $uses);
        self::assertNotEmpty($uses[1]);
        foreach ($uses[1] as $action) {
            self::assertStringContainsString($action, $others . $this->job($this->workflow(), 'release-please'), $action . ' is not pinned anywhere else in this repository');
        }
    }

    private function workflow(): string
    {
        return (string) file_get_contents(self::repositoryRoot() . '/.github/workflows/release-please.yml');
    }

    /** One step's text within a job, from its name to the next step. */
    private function step(string $job, string $name): string
    {
        $start = strpos($job, 'name: ' . $name);
        self::assertNotFalse($start, 'step ' . $name);
        $end = strpos($job, "\n      - ", $start);

        return substr($job, $start, $end === false ? null : $end - $start);
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

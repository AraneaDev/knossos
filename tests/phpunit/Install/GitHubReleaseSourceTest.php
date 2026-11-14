<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Install;

use Knossos\Install\GitHubReleaseSource;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The release source against a local directory and a fake HTTP getter, never the network. */
final class GitHubReleaseSourceTest extends KnossosTestCase
{
    use InstallFixtures;

    #[Group('install')]
    public function testADirectoryNamesItsNewestVersionForThisPlatform(): void
    {
        $dir = $this->work . '/release';
        $this->publish($dir, '0.9.0');
        $this->publish($dir, '0.10.0');
        $this->publish($dir, '1.0.0', 'darwin-arm64');
        $this->publish($dir, 'junk', 'linux-x86_64');
        mkdir($dir . '/knossos-x-linux-x86_64.tar.gz.d');

        assertSame('0.10.0', (new GitHubReleaseSource($dir, null, 'linux-x86_64'))->latest());
        assertSame('0.10.0', (new GitHubReleaseSource('file://' . $dir . '/', null, 'linux-x86_64'))->latest());
        assertSame('1.0.0', (new GitHubReleaseSource($dir, null, 'darwin-arm64'))->latest());
    }

    #[Group('install')]
    public function testADirectoryWithoutABuildForThePlatformSaysSo(): void
    {
        mkdir($this->work . '/empty');
        $this->refused(fn() => (new GitHubReleaseSource($this->work . '/empty', null, 'linux-x86_64'))->latest(), 'No release asset');
    }

    #[Group('install')]
    public function testAnAssetIsCopiedFromADirectory(): void
    {
        $dir = $this->work . '/release';
        $asset = $this->publish($dir, '0.9.0');
        $source = new GitHubReleaseSource($dir, null, 'linux-x86_64');

        $source->fetch('0.9.0', $asset, $this->work . '/got');

        assertSame(true, is_file($this->work . '/got'));
        $this->refused(fn() => $source->fetch('0.9.0', 'knossos-9.9.9-linux-x86_64.tar.gz', $this->work . '/none'), 'not found');
    }

    #[Group('install')]
    public function testAnUnsafeVersionOrAssetNameNeverReachesAPath(): void
    {
        $source = new GitHubReleaseSource($this->work, null, 'linux-x86_64');
        $this->refused(fn() => $source->fetch('../x', 'a', $this->work . '/x'), 'Not a valid version');
        $this->refused(fn() => $source->fetch('1.0.0', '../a', $this->work . '/x'), 'Not a valid asset name');
    }

    #[Group('install')]
    public function testTheLatestReleaseComesFromTheApiTagWithoutItsV(): void
    {
        $asked = [];
        $source = new GitHubReleaseSource(GitHubReleaseSource::DEFAULT_BASE, static function (string $url, ?string $to) use (&$asked): string {
            $asked[] = [$url, $to];

            return '{"tag_name": "v0.48.0"}';
        });

        assertSame('0.48.0', $source->latest());
        assertSame([['https://api.github.com/repos/AraneaDev/knossos/releases/latest', null]], $asked);
    }

    #[Group('install')]
    public function testAnUnreadableApiAnswerIsRefused(): void
    {
        $source = new GitHubReleaseSource(GitHubReleaseSource::DEFAULT_BASE, fn(): string => '<html>');
        $this->refused(fn() => $source->latest(), 'Not a valid version');
    }

    #[Group('install')]
    public function testAnAssetIsDownloadedFromTheTaggedReleaseUrl(): void
    {
        $asked = [];
        $source = new GitHubReleaseSource(GitHubReleaseSource::DEFAULT_BASE, static function (string $url, ?string $to) use (&$asked): string {
            $asked[] = [$url, $to];

            return '';
        });

        $source->fetch('0.48.0', 'SHA256SUMS', '/tmp/x');

        assertSame([['https://github.com/AraneaDev/knossos/releases/download/v0.48.0/SHA256SUMS', '/tmp/x']], $asked);
    }

    #[Group('install')]
    public function testACustomRemoteBaseIsAskedForItsLatestTheWayTheInstallerAsksIt(): void
    {
        $asked = [];
        $resolve = static function (string $url) use (&$asked): string {
            $asked[] = $url;

            return 'https://mirror.example/releases/tag/v0.49.0-rc.1';
        };
        $source = new GitHubReleaseSource('https://mirror.example/releases/', static fn(): string => '', null, $resolve);

        assertSame('0.49.0-rc.1', $source->latest());
        assertSame(['https://mirror.example/releases/latest'], $asked);
    }

    #[Group('install')]
    public function testACustomRemoteBaseThatLeadsNowhereNeedsAVersion(): void
    {
        foreach (['https://mirror.example/releases/latest', 'https://mirror.example/releases/tag/nightly', 'https://mirror.example/releases/tag/v..'] as $final) {
            $source = new GitHubReleaseSource('https://mirror.example/releases', static fn(): string => '', null, static fn(): string => $final);
            $error = self::captureThrown(static fn() => $source->latest(), \Knossos\Install\InstallException::class);
            assertSame(false, str_contains($error->getMessage(), 'api.github.com'), $final);
        }
    }
}

<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Install;

use Knossos\Install\ReleaseAsset;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Names, versions and checksum lines of a release. */
final class ReleaseAssetTest extends KnossosTestCase
{
    use InstallFixtures;

    #[Group('install')]
    public function testThePlatformIsNamedLikeTheTarball(): void
    {
        assertSame('linux-x86_64', ReleaseAsset::platform('Linux', 'x86_64'));
        assertSame('linux-arm64', ReleaseAsset::platform('Linux', 'aarch64'));
        assertSame('darwin-arm64', ReleaseAsset::platform('Darwin', 'arm64'));
        assertSame('darwin-x86_64', ReleaseAsset::platform('Darwin', 'amd64'));
        assertSame('knossos-0.48.0-linux-x86_64.tar.gz', ReleaseAsset::tarball('0.48.0', 'linux-x86_64'));
        assertSame(true, ReleaseAsset::platform() !== '');
    }

    #[Group('install')]
    public function testAnUnsupportedHostIsRefused(): void
    {
        $this->refused(fn() => ReleaseAsset::platform('Windows', 'x86_64'), 'Unsupported operating system');
        $this->refused(fn() => ReleaseAsset::platform('Linux', 'riscv64'), 'Unsupported architecture');
    }

    #[Group('install')]
    public function testOnlyAPlainVersionPasses(): void
    {
        assertSame('0.48.0', ReleaseAsset::assertVersion('0.48.0'));
        assertSame('1.0.0-rc.1', ReleaseAsset::assertVersion('1.0.0-rc.1'));
        foreach (['', 'v1.0.0', '1..0', '../1', '1.0/x', 'latest', '1.0.0 ', '1.0.0-'] as $bad) {
            $this->refused(fn() => ReleaseAsset::assertVersion($bad), 'Not a valid version');
        }
    }

    #[Group('install')]
    public function testTheChecksumIsFoundByExactAssetName(): void
    {
        $a = str_repeat('a', 64);
        $b = str_repeat('b', 64);
        $sums = "{$a}  knossos-0.1.0-linux-x86_64.tar.gz\n{$b} *knossos-0.1.0-darwin-arm64.tar.gz\nnot a line\n";
        assertSame($a, ReleaseAsset::expectedChecksum($sums, 'knossos-0.1.0-linux-x86_64.tar.gz'));
        assertSame($b, ReleaseAsset::expectedChecksum($sums, 'knossos-0.1.0-darwin-arm64.tar.gz'));
        assertSame(null, ReleaseAsset::expectedChecksum($sums, 'knossos-0.1.0-linux-arm64.tar.gz'));
        assertSame(null, ReleaseAsset::expectedChecksum($sums, 'x-0.1.0-linux-x86_64.tar.gz'));
    }
}

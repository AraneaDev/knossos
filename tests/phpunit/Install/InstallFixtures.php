<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Install;

use Knossos\Install\InstallException;

/** Builds installed data directories and release tarballs under a temp tree, and nothing else. */
trait InstallFixtures
{
    private string $work = '';

    /** HOME as it was before the test, or false when it was unset. */
    private string|false $homeBefore = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = (string) realpath(sys_get_temp_dir()) . '/knossos-install-test-' . bin2hex(random_bytes(4));
        mkdir($this->work . '/home', 0o755, true);
        // A record without bin_dir falls back to ~/.local/bin: that must never be the real one.
        $this->homeBefore = getenv('HOME');
        putenv('HOME=' . $this->work . '/home');
    }

    protected function tearDown(): void
    {
        putenv($this->homeBefore === false ? 'HOME' : 'HOME=' . $this->homeBefore);
        exec('rm -rf ' . escapeshellarg($this->work));
        parent::tearDown();
    }

    /** An unpacked release: a `bin/knossos` that answers `version`, its VERSION file and a launcher template naming it. */
    private function writeRelease(string $directory, string $version): void
    {
        mkdir($directory . '/bin', 0o755, true);
        file_put_contents($directory . '/bin/knossos', "#!/bin/sh\necho 'Knossos {$version}'\n");
        chmod($directory . '/bin/knossos', 0o755);
        file_put_contents($directory . '/VERSION', $version . "\n");
        mkdir($directory . '/launcher', 0o755, true);
        file_put_contents($directory . '/launcher/knossos', "#!/bin/sh\n# launcher of {$version}\nINSTALL_DIR='__KNOSSOS_INSTALL_DIR__'\nexec \"\$INSTALL_DIR/current/bin/knossos\" \"\$@\"\n");
    }

    /**
     * An install directory holding the given versions, `current` on the first and install.json to match.
     *
     * The record names `<work>/<name>-bin` as the bin directory, which is where self-update writes the launcher.
     */
    private function installed(string $name, string ...$versions): string
    {
        $data = $this->work . '/' . $name;
        foreach ($versions as $version) {
            $this->writeRelease($data . '/versions/' . $version, $version);
        }
        symlink('versions/' . $versions[0], $data . '/current');
        file_put_contents($data . '/install.json', sprintf(
            "{\n  \"mode\": \"native\",\n  \"version\": \"%s\",\n  \"image\": null,\n  \"bin_dir\": \"%s\",\n  \"installed_at\": \"2026-01-01T00:00:00Z\"\n}\n",
            $versions[0],
            $this->work . '/' . $name . '-bin',
        ));

        return $data;
    }

    /** Add a release tarball and its SHA256SUMS line to a release directory. */
    private function publish(string $releaseDir, string $version, string $platform = 'linux-x86_64', ?string $archiveMember = null): string
    {
        $stage = $this->work . '/stage-' . bin2hex(random_bytes(3));
        $this->writeRelease($stage . '/knossos-' . ($archiveMember ?? $version), $version);
        @mkdir($releaseDir, 0o755, true);
        $asset = sprintf('knossos-%s-%s.tar.gz', $version, $platform);
        exec(sprintf('tar -czf %s -C %s %s', escapeshellarg($releaseDir . '/' . $asset), escapeshellarg($stage), escapeshellarg('knossos-' . ($archiveMember ?? $version))));
        $sums = $releaseDir . '/SHA256SUMS';
        file_put_contents($sums, hash_file('sha256', $releaseDir . '/' . $asset) . '  ' . $asset . "\n", FILE_APPEND);

        return $asset;
    }

    /** Assert that a call is refused with an InstallException whose message holds a fragment. */
    private function refused(callable $call, string $fragment): void
    {
        $error = self::captureThrown($call, InstallException::class);
        self::assertStringContainsString($fragment, $error->getMessage());
    }
}

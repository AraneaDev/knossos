<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Install;

use Knossos\Install\ReleaseProbe;

/** A probe whose answers a test sets. */
final class FakeProbe implements ReleaseProbe
{
    public int $doctorRuns = 0;

    /** @var list<array{string, string, string, string}> release, plugin dir, launcher and data dir of each plugin call */
    public array $plugins = [];

    /** @param (\Closure(): void)|null $onPlugin run when the plugin is asked for, before it answers */
    public function __construct(public ?string $doctorFailure = null, public bool $versionWorks = true, public ?string $pluginFailure = null, public ?\Closure $onPlugin = null) {}

    /** {@inheritDoc} */
    public function doctor(string $releaseDir, string $scratchDir): ?string
    {
        ++$this->doctorRuns;

        return $this->doctorFailure;
    }

    /** {@inheritDoc} */
    public function version(string $releaseDir, string $scratchDir): ?string
    {
        return $this->versionWorks ? trim((string) file_get_contents($releaseDir . '/VERSION')) : null;
    }

    /** {@inheritDoc} Writes a marker naming the release, so a test can see which release the plugin came from. */
    public function plugin(string $releaseDir, string $pluginDir, string $launcher, string $dataDir, string $scratchDir): ?string
    {
        $this->plugins[] = [basename($releaseDir), $pluginDir, $launcher, $dataDir];
        if ($this->onPlugin !== null) {
            ($this->onPlugin)();
        }
        if ($this->pluginFailure !== null) {
            return $this->pluginFailure;
        }
        @mkdir($pluginDir, 0o755, true);
        file_put_contents($pluginDir . '/from', trim((string) file_get_contents($releaseDir . '/VERSION')) . "\n");

        return null;
    }
}

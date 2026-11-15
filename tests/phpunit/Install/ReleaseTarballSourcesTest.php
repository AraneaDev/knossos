<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Install;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The release tarball carries every source directory the image carries.
 *
 * Both lists say what Knossos needs at run time: the Dockerfile's `sources`
 * stage for the image, `copy_tree` in tools/release-tarball for a host. A
 * directory added to the first only would make a release that fails at run
 * time on every host, which tests/shell/release-tarball.sh, with its fixed
 * list of paths, would not notice.
 */
final class ReleaseTarballSourcesTest extends KnossosTestCase
{
    /**
     * What the image carries for container mode alone, and why the tarball does without it in copy_tree.
     *
     * The launcher template is in both, at `launcher/knossos`, but the tarball
     * copies it by itself; the entrypoint of the per-project container means
     * nothing on a host that runs the release natively, and neither does the
 * helper that installs and updates a container install.
     */
    private const CONTAINER_ONLY = [
        'tools/launcher/knossos' => 'copied to launcher/knossos by itself',
        'tools/launcher/idle-stop' => 'the container entrypoint',
        'tools/launcher/container-update' => 'installs and updates a container install from the host',
    ];

    #[Group('packaging')]
    public function testEverySourceTheImageCopiesIsInTheTarball(): void
    {
        $image = array_values(array_diff($this->imageSources(), array_keys(self::CONTAINER_ONLY)));
        $tarball = $this->tarballSources();

        self::assertGreaterThanOrEqual(10, count($image));
        assertSame([], array_values(array_diff($image, $tarball)), 'In the Dockerfile sources stage but not in tools/release-tarball: keep the two in step.');
    }

    #[Group('packaging')]
    public function testTheImageCarriesTheContainerModeScriptsAndTheTarballTheLauncher(): void
    {
        $dockerfile = (string) file_get_contents(self::repositoryRoot() . '/Dockerfile');
        assertSame(array_keys(self::CONTAINER_ONLY), array_values(array_intersect($this->imageSources(), array_keys(self::CONTAINER_ONLY))));
        self::assertStringContainsString("COPY tools/launcher/knossos ./launcher/knossos\n", $dockerfile);
        self::assertStringContainsString("COPY tools/launcher/idle-stop ./tools/launcher/idle-stop\n", $dockerfile);
        self::assertStringContainsString("COPY tools/launcher/container-update ./tools/launcher/container-update\n", $dockerfile);
        self::assertStringContainsString('cp "$ROOT/tools/launcher/knossos" "$TREE/launcher/knossos"', (string) file_get_contents(self::repositoryRoot() . '/tools/release-tarball'));
    }

    /** @return list<string> the source paths of the COPY lines in the Dockerfile's `sources` stage */
    private function imageSources(): array
    {
        $dockerfile = (string) file_get_contents(self::repositoryRoot() . '/Dockerfile');
        $start = strpos($dockerfile, 'AS sources');
        self::assertNotFalse($start, 'the Dockerfile has a sources stage');
        $end = strpos($dockerfile, "\nFROM ", $start);
        $stage = substr($dockerfile, $start, $end === false ? null : $end - $start);
        preg_match_all('/^COPY (\S+) \S+$/m', $stage, $copies);

        return $copies[1];
    }

    /** @return list<string> the paths `copy_tree` takes in tools/release-tarball */
    private function tarballSources(): array
    {
        $script = (string) file_get_contents(self::repositoryRoot() . '/tools/release-tarball');
        self::assertSame(1, preg_match('/^copy_tree \\\\\n((?:.*\\\\\n)*.*\n)/m', $script, $match), 'tools/release-tarball calls copy_tree once');
        $words = preg_split('/\s+/', str_replace("\\\n", ' ', $match[1]), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }
}

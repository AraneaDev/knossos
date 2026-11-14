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
    #[Group('packaging')]
    public function testEverySourceTheImageCopiesIsInTheTarball(): void
    {
        $image = $this->imageSources();
        $tarball = $this->tarballSources();

        self::assertGreaterThanOrEqual(10, count($image));
        assertSame([], array_values(array_diff($image, $tarball)), 'In the Dockerfile sources stage but not in tools/release-tarball: keep the two in step.');
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

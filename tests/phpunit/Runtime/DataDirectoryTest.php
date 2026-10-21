<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Runtime;

use Knossos\Runtime\DataDirectory;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** KNOSSOS_DATA_DIR is read one way: unset and empty name nothing, a relative value is anchored to the working directory. */
#[Group('cli')]
final class DataDirectoryTest extends KnossosTestCase
{
    private string|false $dataDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataDir = getenv('KNOSSOS_DATA_DIR');
    }

    protected function tearDown(): void
    {
        putenv($this->dataDir === false ? 'KNOSSOS_DATA_DIR' : 'KNOSSOS_DATA_DIR=' . $this->dataDir);
        parent::tearDown();
    }

    public function testUnsetNamesNoDirectory(): void
    {
        putenv('KNOSSOS_DATA_DIR');

        self::assertNull(DataDirectory::resolve());
    }

    public function testEmptyNamesNoDirectory(): void
    {
        putenv('KNOSSOS_DATA_DIR=');

        self::assertNull(DataDirectory::resolve());
    }

    public function testAbsoluteIsKeptAsGiven(): void
    {
        putenv('KNOSSOS_DATA_DIR=/srv/knossos-data/');

        self::assertSame('/srv/knossos-data/', DataDirectory::resolve());
        self::assertSame('/srv/knossos-data/knossos.sqlite', (new RuntimeFactory(self::repositoryRoot()))->defaultDatabasePath());
    }

    public function testRelativeIsAnchoredToTheWorkingDirectory(): void
    {
        putenv('KNOSSOS_DATA_DIR=graph-data');
        $cwd = (string) getcwd();

        self::assertSame(rtrim($cwd, '/') . '/graph-data', DataDirectory::resolve());
        self::assertSame(rtrim($cwd, '/') . '/graph-data/knossos.sqlite', (new RuntimeFactory(self::repositoryRoot()))->defaultDatabasePath());
    }

    public function testNamedKeepsARelativeValueAsSet(): void
    {
        putenv('KNOSSOS_DATA_DIR=graph-data');
        self::assertSame('graph-data', DataDirectory::named());

        putenv('KNOSSOS_DATA_DIR=');
        self::assertNull(DataDirectory::named());

        putenv('KNOSSOS_DATA_DIR');
        self::assertNull(DataDirectory::named());
    }

    /** The one place the graph's file name and its conventional directory are spelled out. */
    public function testTheDatabasePathsJoinWithoutDoublingASlash(): void
    {
        self::assertSame('/data/knossos.sqlite', DataDirectory::databaseIn('/data/'));
        self::assertSame('/repo/.knossos/knossos.sqlite', DataDirectory::databaseUnder('/repo/'));
        self::assertSame('/.knossos/knossos.sqlite', DataDirectory::databaseUnder('/'));
    }
}

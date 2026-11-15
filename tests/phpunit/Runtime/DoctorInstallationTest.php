<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Runtime;

use Knossos\Runtime\DoctorService;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\Install\InstallFixtures;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The `installation` check of doctor: which kind of Knossos this is and where it lives. */
final class DoctorInstallationTest extends KnossosTestCase
{
    use InstallFixtures;

    #[Group('doctor-service')]
    public function testASourceCheckoutSaysSo(): void
    {
        mkdir($this->work . '/checkout');

        $check = $this->installationCheck($this->work . '/checkout', $this->work . '/data/knossos.sqlite');

        assertSame(['name' => 'installation', 'status' => 'ok', 'detail' => 'source checkout at ' . $this->work . '/checkout'], $check);
    }

    #[Group('doctor-service')]
    public function testAnInstalledReleaseNamesItsModeVersionAndLocation(): void
    {
        $data = $this->installed('d', '1.2.3');

        $check = $this->installationCheck($data . '/versions/1.2.3', $data . '/knossos.sqlite');

        assertSame('native 1.2.3 at ' . $data . '/versions/1.2.3', $check['detail']);
        // A container install has no versions/: it names its image and the install directory instead.
        file_put_contents($data . '/install.json', '{"mode": "container", "version": "1.2.3"}');
        assertSame('container 1.2.3 from an unrecorded image, installed in ' . $data, $this->installationCheck($data . '/versions/1.2.3', $data . '/knossos.sqlite')['detail']);
        $digest = 'ghcr.io/araneadev/knossos@sha256:' . str_repeat('b', 64);
        file_put_contents($data . '/install.json', sprintf('{"mode": "container", "version": "1.2.3", "image": "%s"}', $digest));
        assertSame('container 1.2.3 from ' . $digest . ', installed in ' . $data, $this->installationCheck($data . '/versions/1.2.3', $data . '/knossos.sqlite')['detail']);
    }

    /** @return array{name: string, status: string, detail: string} */
    private function installationCheck(string $root, string $database): array
    {
        $report = (new DoctorService(SqliteConnection::open(':memory:'), $root, $database))->run();
        foreach ($report['checks'] as $check) {
            if ($check['name'] === 'installation') {
                return $check;
            }
        }
        self::fail('doctor reported no installation check');
    }
}

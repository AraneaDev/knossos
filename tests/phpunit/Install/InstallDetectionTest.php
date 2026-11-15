<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Install;

use Knossos\Runtime\DoctorService;
use Knossos\Tests\Phpunit\KnossosTestCase;

/**
 * install.sh asks a host's PHP for the extensions doctor checks, and no others.
 *
 * Native mode is chosen only on a host that would pass `knossos doctor`, so
 * the extension list in the installer's probe must be DoctorService's list.
 * One that drifts either chooses native mode on a host doctor then fails, or
 * turns a working host away.
 */
final class InstallDetectionTest extends KnossosTestCase
{
    public function testTheInstallerProbesExactlyTheExtensionsDoctorChecks(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/install.sh');

        self::assertSame(1, preg_match('/foreach \(\[([^\]]*)\] as \$e\) \{ if \(!extension_loaded\(\$e\)\)/', $script, $match), 'install.sh has no extension probe');
        preg_match_all('/"([a-z_]+)"/', $match[1], $names);

        self::assertSame(DoctorService::REQUIRED_EXTENSIONS, $names[1]);
    }

    public function testTheInstallerReadsOnlyItsMarkedAnswerAndSendsPhpWarningsToStderr(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/install.sh');

        self::assertStringContainsString("php -d display_errors=stderr -d display_startup_errors=0 -r '\$missing = []; foreach", $script);
        self::assertStringContainsString("sed -n 's/^KNOSSOS-MISSING-EXTENSIONS://p'", $script);
    }
}

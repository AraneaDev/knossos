<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Configuration\EnrichersFile;
use Knossos\Roots\AllowedRoots;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Where enrichers.json and pane-autostart.json live.
 *
 * Beside the roots file, so `KNOSSOS_ROOTS_FILE` moves them with it, unless
 * `KNOSSOS_SETTINGS_DIR` names a directory: the container launcher points the
 * roots file at the read-only list the host keeps, and these settings must
 * stay writable in the graph directory.
 */
final class SettingsDirectoryTest extends KnossosTestCase
{
    #[Group('cli')]
    public function testTheSettingsFollowTheRootsFileUnlessADirectoryIsNamed(): void
    {
        $saved = ['KNOSSOS_ROOTS_FILE' => getenv('KNOSSOS_ROOTS_FILE'), 'KNOSSOS_SETTINGS_DIR' => getenv('KNOSSOS_SETTINGS_DIR')];
        try {
            putenv('KNOSSOS_ROOTS_FILE');
            putenv('KNOSSOS_SETTINGS_DIR');
            assertSame('/graph', AllowedRoots::settingsDirectory('/graph/knossos.sqlite'));

            putenv('KNOSSOS_ROOTS_FILE=/host/install/roots.json');
            assertSame('/host/install', AllowedRoots::settingsDirectory('/graph/knossos.sqlite'));
            assertSame('/host/install/enrichers.json', EnrichersFile::forDatabase('/graph/knossos.sqlite')->path);

            putenv('KNOSSOS_SETTINGS_DIR=/graph/');
            assertSame('/graph', AllowedRoots::settingsDirectory('/graph/knossos.sqlite'));
            assertSame('/graph/enrichers.json', EnrichersFile::forDatabase('/graph/knossos.sqlite')->path);
            // The roots file itself does not move with the settings.
            assertSame('/host/install/roots.json', AllowedRoots::defaultConfigPath('/graph/knossos.sqlite'));
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }
}

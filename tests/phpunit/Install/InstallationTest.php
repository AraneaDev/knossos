<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Install;

use Knossos\Install\Installation;
use Knossos\Install\UpdateCheck;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Telling an installed release from a checkout, and the cached update answer. */
final class InstallationTest extends KnossosTestCase
{
    use InstallFixtures;

    #[Group('install')]
    public function testCodeUnderVersionsIsAnInstallation(): void
    {
        $data = $this->installed('d', '1.0.0');
        $installation = new Installation($data . '/versions/1.0.0');

        assertSame(false, $installation->isSourceCheckout());
        assertSame('native', $installation->mode());
        assertSame('native 1.0.0 at ' . $data . '/versions/1.0.0', $installation->describe());
    }

    #[Group('install')]
    public function testAGitlessCheckoutIsStillACheckout(): void
    {
        $data = $this->installed('d', '1.0.0');
        mkdir($this->work . '/checkout/bin', 0o755, true);

        $installation = new Installation($this->work . '/checkout');
        assertSame(true, $installation->isSourceCheckout());
        assertSame('source checkout at ' . $this->work . '/checkout', $installation->describe());
        // A sibling whose name merely starts with "versions" is not under versions/.
        mkdir($data . '/versions-old/x', 0o755, true);
        assertSame(true, (new Installation($data . '/versions-old/x'))->isSourceCheckout());
        assertSame(true, (new Installation($this->work . '/checkout'))->isSourceCheckout());
        assertSame(true, (new Installation($this->work . '/missing'))->isSourceCheckout());
    }

    #[Group('install')]
    public function testTheInstallDirectoryComesFromTheCodeAndNeverFromTheDataDirectory(): void
    {
        $data = $this->installed('d', '1.0.0');
        $foreign = $this->work . '/foreign graph';
        mkdir($foreign . '/versions/9.9.9', 0o755, true);
        $previous = getenv('KNOSSOS_DATA_DIR');
        putenv('KNOSSOS_DATA_DIR=' . $foreign);
        try {
            // Reached through `current`, as the launcher reaches it.
            $installation = new Installation($data . '/current');
            assertSame($data, $installation->installDir);
            assertSame($data, $installation->directory());
            assertSame('1.0.0', $installation->record()['version']);
        } finally {
            putenv($previous === false ? 'KNOSSOS_DATA_DIR' : 'KNOSSOS_DATA_DIR=' . $previous);
        }
    }

    #[Group('install')]
    public function testAPartialUnpackOrANameThatIsNoVersionIsNotAnInstallation(): void
    {
        $data = $this->installed('d', '1.0.0');
        mkdir($data . '/versions/2.0.0.partial.123', 0o755, true);
        mkdir($data . '/versions/notes', 0o755, true);

        assertSame(null, (new Installation($data . '/versions/2.0.0.partial.123'))->installDir);
        assertSame(null, (new Installation($data . '/versions/notes'))->installDir);
        $error = self::captureThrown(static fn() => (new Installation($data . '/versions/notes'))->directory(), \Knossos\Install\InstallException::class);
        assertSame('This is a source checkout: update it with git pull.', $error->getMessage());
        assertSame(null, (new Installation($data . '/versions/notes'))->record());
    }

    #[Group('install')]
    public function testCodeUnderVersionsIsAReleaseOnlyWhenTheRecordBesideItNamesThatVersion(): void
    {
        // A checkout that happens to be cloned to <x>/versions/0.47: nothing beside it vouches for it.
        mkdir($this->work . '/src/versions/0.47/bin', 0o755, true);
        $checkout = new Installation($this->work . '/src/versions/0.47');
        assertSame(true, $checkout->isSourceCheckout());
        assertSame($this->work . '/src', $checkout->layoutDir);
        assertSame('source checkout at ' . $this->work . '/src/versions/0.47', $checkout->describe());

        foreach (['not json', '{"version": "0.47"}', '{"mode": "native", "version": "0.46"}', '{"mode": 1, "version": "0.47"}'] as $record) {
            file_put_contents($this->work . '/src/install.json', $record);
            assertSame(true, (new Installation($this->work . '/src/versions/0.47'))->isSourceCheckout(), $record);
        }

        file_put_contents($this->work . '/src/install.json', '{"mode": "native", "version": "0.47"}');
        $installed = new Installation($this->work . '/src/versions/0.47');
        assertSame($this->work . '/src', $installed->installDir);
        assertSame($this->work . '/src', $installed->layoutDir);
        // A version that is no longer the recorded one is not the installation either.
        $data = $this->installed('d', '1.0.0', '0.9.0');
        assertSame(null, (new Installation($data . '/versions/0.9.0'))->installDir);
        assertSame($data, (new Installation($data . '/versions/1.0.0'))->installDir);
    }

    #[Group('install')]
    public function testTheBakedGraphDirectoryOfThePluginIsReadBack(): void
    {
        $data = $this->installed('d', '1.0.0');
        $installation = new Installation($data . '/current');
        assertSame(null, $installation->pluginDataDir());

        mkdir($data . '/plugin/hooks/scripts', 0o755, true);
        foreach ([["/graph's here", "KNOSSOS_INSTALLED_DATA_DIR='/graph'\\''s here'"], [null, "KNOSSOS_INSTALLED_DATA_DIR=''"], [null, "KNOSSOS_INSTALLED_DATA_DIR='relative'"]] as [$expected, $line]) {
            file_put_contents($data . '/plugin/hooks/scripts/lib.sh', "# x\n" . $line . "\ncase x in esac\n");
            assertSame($expected, $installation->pluginDataDir(), $line);
        }
        file_put_contents($data . '/plugin/hooks/scripts/lib.sh', "KNOSSOS_INSTALLED_DATA_DIR='__KNOSSOS_DATA_DIR__'\n");
        assertSame(null, $installation->pluginDataDir());
    }

    #[Group('install')]
    public function testTheRecordIsReadWhateverItHolds(): void
    {
        mkdir($this->work . '/d/versions/1.0.0', 0o755, true);
        $root = $this->work . '/d/versions/1.0.0';

        file_put_contents($this->work . '/d/install.json', '{"mode": "container", "version": "1.0.0"}');
        assertSame('container', (new Installation($root))->mode());
        file_put_contents($this->work . '/d/install.json', '{"mode": "weird", "version": "1.0.0"}');
        assertSame(null, (new Installation($root))->mode());
        assertSame('unrecorded mode 1.0.0 at ' . $root, (new Installation($root))->describe());
    }

    #[Group('install')]
    public function testTheCacheIsDueAfterADayOrWhenUnreadable(): void
    {
        $check = new UpdateCheck($this->work . '/c');
        assertSame(null, $check->read());
        assertSame(true, $check->due());

        $check->record('1.2.0', 1_000_000);
        assertSame(['checked_at' => gmdate('Y-m-d\TH:i:s\Z', 1_000_000), 'latest' => '1.2.0'], $check->read());
        assertSame(false, $check->due(1_000_000 + 86_399));
        assertSame(true, $check->due(1_000_000 + 86_400));
        assertSame([], glob($this->work . '/c/*.tmp.*'));

        file_put_contents($this->work . '/c/update-check.json', '{"checked_at": "never", "latest": "1.2.0"}');
        assertSame(true, $check->due());
        file_put_contents($this->work . '/c/update-check.json', '{"latest": 3}');
        assertSame(null, $check->read());
        file_put_contents($this->work . '/c/update-check.json', '[1]');
        assertSame(null, $check->read());
    }

    #[Group('install')]
    public function testRecordingIntoAnUncreatableDirectoryIsSilent(): void
    {
        file_put_contents($this->work . '/file', 'x');

        (new UpdateCheck($this->work . '/file/sub'))->record('1.0.0');

        assertSame(null, (new UpdateCheck($this->work . '/file/sub'))->read());
    }

    #[Group('install')]
    public function testOnlyANewerVersionIsNewer(): void
    {
        $check = new UpdateCheck($this->work . '/c');
        assertSame(null, $check->newerThan('1.0.0'));
        $check->record('1.10.0');
        assertSame('1.10.0', $check->newerThan('1.9.0'));
        assertSame(null, $check->newerThan('1.10.0'));
        assertSame(null, $check->newerThan('2.0.0'));
    }

    #[Group('install')]
    public function testAFailedLookupIsRecordedAndKeepsTheKnownLatest(): void
    {
        $check = new UpdateCheck($this->work . '/c');

        $check->record(null, 1_000_000);
        assertSame(['checked_at' => gmdate('Y-m-d\TH:i:s\Z', 1_000_000), 'latest' => null], $check->read());
        assertSame(false, $check->due(1_000_000 + 3600));
        assertSame(true, $check->due(1_000_000 + 86_400));
        assertSame(null, $check->newerThan('1.0.0'));

        $check->record('1.5.0', 2_000_000);
        $check->record(null, 2_100_000);
        assertSame(['checked_at' => gmdate('Y-m-d\TH:i:s\Z', 2_100_000), 'latest' => '1.5.0'], $check->read());
        assertSame('1.5.0', $check->newerThan('1.0.0'));
    }

    #[Group('install')]
    public function testACorruptCacheIsDueAndIsReplaced(): void
    {
        mkdir($this->work . '/c');
        file_put_contents($this->work . '/c/update-check.json', '{broken');
        $check = new UpdateCheck($this->work . '/c');

        assertSame(true, $check->due());
        $check->record(null);

        assertSame(false, $check->due());
        assertSame(null, $check->read()['latest']);
    }

    #[Group('install')]
    public function testAStampFromTheFutureIsDue(): void
    {
        $check = new UpdateCheck($this->work . '/c');
        $check->record('1.0.0', 2_000_000);

        assertSame(true, $check->due(1_000_000));
        assertSame(false, $check->due(2_000_000));
    }
}

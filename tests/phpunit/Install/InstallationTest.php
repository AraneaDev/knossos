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
        $installation = new Installation($data, $data . '/versions/1.0.0');

        assertSame(false, $installation->isSourceCheckout());
        assertSame('native', $installation->mode());
        assertSame('native 1.0.0 at ' . $data . '/versions/1.0.0', $installation->describe());
    }

    #[Group('install')]
    public function testAGitlessCheckoutIsStillACheckout(): void
    {
        $data = $this->installed('d', '1.0.0');
        mkdir($this->work . '/checkout/bin', 0o755, true);

        $installation = new Installation($data, $this->work . '/checkout');
        assertSame(true, $installation->isSourceCheckout());
        assertSame('source checkout at ' . $this->work . '/checkout', $installation->describe());
        // A sibling whose name merely starts with "versions" is not under versions/.
        mkdir($data . '/versions-old/x', 0o755, true);
        assertSame(true, (new Installation($data, $data . '/versions-old/x'))->isSourceCheckout());
        assertSame(true, (new Installation($this->work . '/nowhere', $this->work . '/checkout'))->isSourceCheckout());
        assertSame(true, (new Installation($data, $this->work . '/missing'))->isSourceCheckout());
    }

    #[Group('install')]
    public function testTheRecordIsReadWhateverItHolds(): void
    {
        mkdir($this->work . '/d/versions/1.0.0', 0o755, true);
        $installation = new Installation($this->work . '/d', $this->work . '/d/versions/1.0.0');
        assertSame(null, $installation->record());
        assertSame(null, $installation->mode());
        assertSame('unrecorded mode unknown version at ' . $this->work . '/d/versions/1.0.0', $installation->describe());

        file_put_contents($this->work . '/d/install.json', '{"mode": "container", "version": "1.0.0"}');
        assertSame('container', $installation->mode());
        file_put_contents($this->work . '/d/install.json', '{"mode": "weird"}');
        assertSame(null, $installation->mode());
        file_put_contents($this->work . '/d/install.json', 'nope');
        assertSame(null, $installation->record());
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

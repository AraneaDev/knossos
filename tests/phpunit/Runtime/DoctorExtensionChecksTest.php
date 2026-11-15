<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Runtime;

use Knossos\Runtime\DoctorService;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The `php.extension.<name>` checks of `knossos doctor`, one per extension the release needs.
 *
 * Every extension in {@see DoctorService::REQUIRED_EXTENSIONS} is loaded where
 * the suite runs (the rest of it needs them), so each check answers `loaded`.
 * install.sh asks a host's PHP for the same list before it chooses native mode
 * (InstallDetectionTest ties the two).
 */
#[Group('doctor-service')]
final class DoctorExtensionChecksTest extends KnossosTestCase
{
    /** @return iterable<string, array{string}> */
    public static function requiredExtensions(): iterable
    {
        foreach (DoctorService::REQUIRED_EXTENSIONS as $extension) {
            yield $extension => [$extension];
        }
    }

    public function testTheListNamesWhatTheReleaseNeeds(): void
    {
        assertSame(['json', 'mbstring', 'pdo', 'pdo_sqlite', 'tokenizer'], DoctorService::REQUIRED_EXTENSIONS);
    }

    #[DataProvider('requiredExtensions')]
    public function testEachRequiredExtensionIsACheckThatIsOkWhenLoaded(string $extension): void
    {
        assertSame(true, extension_loaded($extension), $extension . ' is required for the rest of the suite');
        $root = self::scratchPath('knossos-doctor-extensions');
        mkdir($root, 0o700, true);
        try {
            $result = (new DoctorService(SqliteConnection::open(':memory:'), $root, ':memory:'))->run();
        } finally {
            rmdir($root);
        }

        $checks = array_values(array_filter($result['checks'], static fn(array $check): bool => $check['name'] === 'php.extension.' . $extension));
        assertSame(1, count($checks), 'php.extension.' . $extension . ' not found in the result');
        assertSame('ok', $checks[0]['status']);
        assertSame('loaded', $checks[0]['detail']);
    }
}

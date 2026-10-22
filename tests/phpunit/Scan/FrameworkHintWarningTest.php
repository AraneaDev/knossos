<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Configuration\ProjectConfigurationLoader;
use Knossos\Scan\{ProjectScanService, ScanPlanner};
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A `frameworks` hint that no worker or rule reads changes nothing, and a
 * scan that accepted it silently would let the author believe it did.
 */
final class FrameworkHintWarningTest extends KnossosTestCase
{
    /** Only the hints without effect are named, in one warning. */
    #[Group('scan')]
    public function testAHintWithoutEffectIsNamedInOneWarning(): void
    {
        $warnings = $this->scanWithHints(['react', 'laravel']);

        $hints = self::hintWarnings($warnings);
        self::assertSame(['Framework hints have no effect yet: react. Knossos detects those frameworks from imports and manifests.'], $hints);
        self::assertStringNotContainsString('laravel', $hints[0]);
    }

    /** A hint the scan acts on raises no warning. */
    #[Group('scan')]
    public function testAnEffectiveHintRaisesNoWarning(): void
    {
        self::assertSame([], self::hintWarnings($this->scanWithHints(['laravel'])));
    }

    /** An effective hint must be one knossos.json accepts, or the warning would name a subset of nothing. */
    #[Group('scan')]
    public function testEveryEffectiveHintIsAnAcceptedHint(): void
    {
        self::assertSame([], array_values(array_diff(ScanPlanner::EFFECTIVE_HINTS, ProjectConfigurationLoader::FRAMEWORKS)));
    }

    /**
     * @param list<string> $frameworks
     * @return list<string>
     */
    private function scanWithHints(array $frameworks): array
    {
        $root = sys_get_temp_dir() . '/knossos-stale-hints-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o700, true);
        try {
            file_put_contents($root . '/src/A.php', "<?php\nnamespace App;\nfinal class A {}\n");
            file_put_contents($root . '/knossos.json', (string) json_encode(['version' => 1, 'frameworks' => $frameworks]));

            return (new ProjectScanService($this->freshTestDatabase(), self::repositoryRoot(), [$root]))->scan($root)->warnings;
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * @param list<string> $warnings
     * @return list<string>
     */
    private static function hintWarnings(array $warnings): array
    {
        return array_values(array_filter($warnings, static fn(string $warning): bool => str_starts_with($warning, 'Framework hints')));
    }
}

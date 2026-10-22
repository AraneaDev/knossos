<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Configuration\ProjectConfigurationLoader;
use Knossos\Result\ResultEnvelope;
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
        $envelope = $this->scanWithHints(['react', 'laravel']);

        $hints = self::hintWarnings($envelope->warnings);
        self::assertSame(['FRAMEWORK_HINT_NO_EFFECT: Framework hints have no effect yet: react. Knossos detects those frameworks from imports and manifests.'], $hints);
        self::assertStringNotContainsString('laravel', $hints[0]);
        // The configuration still echoes every hint, the idle one included.
        self::assertSame(['react', 'laravel'], $envelope->data['configuration']['framework_hints']);
    }

    /** A hint the scan acts on raises no warning. */
    #[Group('scan')]
    public function testAnEffectiveHintRaisesNoWarning(): void
    {
        self::assertSame([], self::hintWarnings($this->scanWithHints(['laravel'])->warnings));
    }

    /** An effective hint must be one knossos.json accepts, or the warning would name a subset of nothing. */
    #[Group('scan')]
    public function testEveryEffectiveHintIsAnAcceptedHint(): void
    {
        self::assertSame([], array_values(array_diff(array_keys(ScanPlanner::EFFECTIVE_HINTS), ProjectConfigurationLoader::FRAMEWORKS)));
    }

    /** Each effective hint, alone in a tree with no manifest, turns on a framework the scan acts on. */
    #[Group('scan')]
    public function testEveryEffectiveHintChangesThePreparation(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-hint-plan-' . bin2hex(random_bytes(6));
        mkdir($root, 0o700, true);
        try {
            $planner = new ScanPlanner($this->freshTestDatabase(), [$root]);
            $frameworks = static function (array $hints) use ($planner, $root): array {
                file_put_contents($root . '/knossos.json', (string) json_encode(['version' => 1, 'frameworks' => $hints]));
                $preparation = $planner->prepare($root, null, null, null, 'auto', 0, null);

                return [$preparation->laravel, $preparation->symfony, $preparation->livewire, $preparation->rustFrameworks];
            };
            $none = $frameworks([]);
            foreach (array_keys(ScanPlanner::EFFECTIVE_HINTS) as $hint) {
                self::assertNotSame($none, $frameworks([$hint]), $hint);
            }
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * @param list<string> $frameworks
     */
    private function scanWithHints(array $frameworks): ResultEnvelope
    {
        $root = sys_get_temp_dir() . '/knossos-stale-hints-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o700, true);
        try {
            file_put_contents($root . '/src/A.php', "<?php\nnamespace App;\nfinal class A {}\n");
            file_put_contents($root . '/knossos.json', (string) json_encode(['version' => 1, 'frameworks' => $frameworks]));

            return (new ProjectScanService($this->freshTestDatabase(), self::repositoryRoot(), [$root]))->scan($root);
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
        return array_values(array_filter($warnings, static fn(string $warning): bool => str_starts_with($warning, 'FRAMEWORK_HINT_NO_EFFECT:')));
    }
}

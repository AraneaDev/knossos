<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\LanguageDescriptor;
use Knossos\Scan\ScanPlanner;
use Knossos\Scan\ScanRequestBuilder;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\SchemaSubset;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * The request the core sends each language's worker is the one
 * `schemas/scanner/v1/scan-request.schema.json` describes, limits included,
 * so a worker can refuse a request without them instead of guessing.
 */
#[Group('scan')]
final class ScanRequestSchemaTest extends KnossosTestCase
{
    public function testTheCoreRequestForEveryLanguageMatchesTheSchema(): void
    {
        $schema = json_decode((string) file_get_contents(self::repositoryRoot() . '/schemas/scanner/v1/scan-request.schema.json'), true, 512, JSON_THROW_ON_ERROR);
        $root = sys_get_temp_dir() . '/knossos-stale-request-schema-' . bin2hex(random_bytes(5));
        $files = ['src/a.php' => "<?php\n", 'src/b.ts' => "export {};\n", 'src/c.py' => "\n", 'src/lib.rs' => "\n"];
        foreach ($files as $path => $contents) {
            @mkdir(dirname($root . '/' . $path), 0o777, true);
            file_put_contents($root . '/' . $path, $contents);
        }
        try {
            $planner = new ScanPlanner($this->freshTestDatabase(), [$root]);
            $plan = $planner->finalize($planner->prepare($root, null, null, null, 'full', 0, null));
            foreach (LanguageDescriptor::defaults(self::repositoryRoot()) as $descriptor) {
                $own = array_values(array_filter(
                    $plan->preparation->discovery->files,
                    static fn(object $file): bool => in_array($file->language, $descriptor->languages, true),
                ));
                assertNotSame([], $own, $descriptor->key);
                $request = ScanRequestBuilder::build($descriptor, $plan, $own)
                    + ['files' => array_map(static fn(object $file): string => $file->relativePath, $own)];

                assertSame([], SchemaSubset::errors($schema, $request, $schema), $descriptor->key);
            }
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * Livewire facts are only worth asking the PHP worker for when the project
     * requires `livewire/livewire`; an `app/Livewire/` directory alone is a
     * naming coincidence, so the request keeps to `laravel` without the package.
     */
    public function testLivewireIsRequestedWhenComposerRequiresIt(): void
    {
        $requestedFrameworks = function (array $require, ?array $knossos = null): array {
            $root = sys_get_temp_dir() . '/knossos-stale-livewire-request-' . bin2hex(random_bytes(5));
            mkdir($root . '/app/Livewire', 0o777, true);
            file_put_contents($root . '/composer.json', json_encode(['require' => $require], JSON_THROW_ON_ERROR));
            file_put_contents($root . '/app/Livewire/Counter.php', "<?php\n");
            if ($knossos !== null) {
                file_put_contents($root . '/knossos.json', json_encode($knossos, JSON_THROW_ON_ERROR));
            }
            try {
                $planner = new ScanPlanner($this->freshTestDatabase(), [$root]);
                $plan = $planner->finalize($planner->prepare($root, null, null, null, 'full', 0, null));
                $descriptor = array_values(array_filter(
                    LanguageDescriptor::defaults(self::repositoryRoot()),
                    static fn(LanguageDescriptor $candidate): bool => $candidate->key === 'php',
                ))[0];
                $own = array_values(array_filter(
                    $plan->preparation->discovery->files,
                    static fn(object $file): bool => in_array($file->language, $descriptor->languages, true),
                ));

                return ScanRequestBuilder::build($descriptor, $plan, $own)['frameworks'];
            } finally {
                $this->removeTempTree($root);
            }
        };

        assertSame(['laravel', 'livewire'], $requestedFrameworks(['laravel/framework' => '^13.0', 'livewire/livewire' => '^4.0']));
        assertSame(['laravel'], $requestedFrameworks(['laravel/framework' => '^13.0']));
        // The `knossos.json` hint turns Livewire on where composer.json does not require it.
        assertSame(['laravel', 'livewire'], $requestedFrameworks(['laravel/framework' => '^13.0'], ['version' => 1, 'frameworks' => ['livewire']]));
    }

    public function testTheSchemaRefusesMissingAndNonPositiveLimits(): void
    {
        $schema = json_decode((string) file_get_contents(self::repositoryRoot() . '/schemas/scanner/v1/scan-request.schema.json'), true, 512, JSON_THROW_ON_ERROR);
        $request = ['root' => '/r', 'files' => ['a.php']];

        assertSame(['$: missing limits'], SchemaSubset::errors($schema, $request, $schema));
        assertSame(['$.limits.max_files: below 1'], SchemaSubset::errors($schema, $request + ['limits' => ['max_files' => 0, 'max_file_bytes' => 1]], $schema));
        assertSame(['$.limits.max_file_bytes: expected integer'], SchemaSubset::errors($schema, $request + ['limits' => ['max_files' => 1, 'max_file_bytes' => '1']], $schema));
    }
}

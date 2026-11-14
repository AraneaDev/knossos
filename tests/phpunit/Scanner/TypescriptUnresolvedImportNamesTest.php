<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\ScanLimits;
use PHPUnit\Framework\Attributes\Group;

/**
 * A class imported from a package that is not installed keeps the name the
 * import asks for. The compiler resolves every such import to one placeholder
 * it calls `unknown`, and taking that name made one external class of them all.
 */
#[Group('typescript-scanner')]
final class TypescriptUnresolvedImportNamesTest extends KnossosTestCase
{
    public function testAnUnresolvedImportIsReferencedByTheNameItImports(): void
    {
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan(['limits' => ScanLimits::DEFAULT,
                'root' => self::repositoryRoot() . '/tests/Fixtures/ts-unresolved-imports',
                'files' => ['src/local.ts', 'src/panel.ts'],
                'config_files' => ['tsconfig.json'],
            ]), false);
        } finally {
            $client->shutdown();
        }
        $edges = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                if (in_array($edge->kind, ['extends', 'implements', 'constructs', 'injects'], true)) {
                    $edges[] = sprintf('%s %s', $edge->kind, preg_replace('/^[^:]+:/', '', $edge->targetReference));
                }
            }
        }
        sort($edges);

        assertSame([], array_values(array_filter($edges, static fn(string $edge): bool => str_contains($edge, 'unknown'))), implode("\n", $edges));
        assertSame([
            'constructs class:src/local.ts#Local',
            'constructs external_class:HttpClient',
            'extends external_class:Base',
            'implements external_class:Shape',
            'injects external_class:HttpClient',
        ], $edges);
    }
}

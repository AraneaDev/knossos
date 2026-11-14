<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\ScanLimits;
use PHPUnit\Framework\Attributes\Group;

/**
 * A class that asks Angular's `inject()` for another depends on it as one
 * that takes it in its constructor does. Only a class asked for in a property
 * initialiser or directly in the constructor counts: a constant token, a call
 * in a method or a nested function, and another module's `inject` do not.
 */
#[Group('typescript-scanner')]
final class TypescriptAngularInjectTest extends KnossosTestCase
{
    public function testInjectCallsAreInjectionsOfTheClassTheyAskFor(): void
    {
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan(['limits' => ScanLimits::DEFAULT,
                'root' => self::repositoryRoot() . '/tests/Fixtures/ts-angular-inject',
                'files' => ['src/own.ts', 'src/panel.ts', 'src/services.ts'],
                'config_files' => ['tsconfig.json'],
            ]), false);
        } finally {
            $client->shutdown();
        }
        $injects = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                if ($edge->kind === 'injects') {
                    $injects[] = sprintf('%s -> %s (line %d)', substr($edge->sourceReference, (int) strrpos($edge->sourceReference, '#') + 1), preg_replace('/^[^:]+:/', '', $edge->targetReference), $edge->evidence->startLine);
                }
            }
        }
        sort($injects);

        assertSame([
            'Panel -> class:src/services.ts#Api (line 9)',
            'Panel -> class:src/services.ts#Logger (line 19)',
            'Panel -> class:src/services.ts#Logger (line 20)',
            'Panel -> class:src/services.ts#Store (line 10)',
            'Panel -> class:src/services.ts#Unused (line 11)',
        ], $injects);
    }
}

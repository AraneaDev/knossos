<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Classification;

use Knossos\Classification\ExampleModuleRule;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Example code is what a repository keeps to show how it is used. */
#[Group('classification')]
final class ExampleModuleRuleTest extends TestCase
{
    public function testExampleDirectoriesOutsideASourceRootAreExampleCode(): void
    {
        foreach ([
            'examples/custom-adapters/adapter-template.ts',
            'examples/api-integration/python/argos_client.py',
            'example/main.rs',
            'packages/client/samples/basic.ts',
            'demo/app.js',
            'docs/guide/examples/usage.ts',
        ] as $path) {
            self::assertTrue(ExampleModuleRule::isExamplePath($path), $path);
        }
        foreach ([
            'src/demo/screen.ts',
            'src/examples.ts',
            'lib/samples/parser.py',
            'examples.ts',
            'docs/guide.ts',
        ] as $path) {
            self::assertFalse(ExampleModuleRule::isExamplePath($path), $path);
        }
    }
}

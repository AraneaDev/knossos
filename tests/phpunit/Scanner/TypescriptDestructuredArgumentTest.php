<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * `async hold(step, { tape }) { await tape.hold(step.ms); }` in plain
 * JavaScript: the destructured `tape` has no type, so the call resolved to
 * nothing and the recorder's `hold` read as dead. A call in the same file
 * that hands the function `{ tape }` says what `tape` holds, so the method
 * call is a probable call of that value's member. A declared type, by
 * annotation or JSDoc, already types the binding and keeps its certain edge.
 */
#[Group('typescript-scanner')]
final class TypescriptDestructuredArgumentTest extends KnossosTestCase
{
    public function testAMethodCallOnADestructuredParameterReachesTheArgumentsMember(): void
    {
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan([
                'root' => self::repositoryRoot() . '/tests/Fixtures/ts-destructured-arguments',
                'files' => ['src/documented.js', 'src/recorder.js', 'src/steps.js', 'src/typed.ts'],
                'config_files' => ['tsconfig.json'],
            ]), false);
        } finally {
            $client->shutdown();
        }
        $calls = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                if ($edge->kind === 'calls' && str_contains($edge->targetReference, 'src/recorder.js#recorder.{object}')) {
                    $calls[] = $edge->confidence->value . ' ' . $edge->sourceReference . ' -> ' . substr($edge->targetReference, (int) strrpos($edge->targetReference, '::') + 2);
                }
            }
        }
        sort($calls);

        // `idle` is called by nothing, and `count.toFixed()` is no member of this project.
        self::assertSame([
            'certain ts:function:src/documented.js#holdDocumented -> hold',
            'certain ts:function:src/typed.ts#stopTyped -> stop',
            'probable ts:function:src/steps.js#measure -> stop',
            'probable ts:method:src/steps.js#STEP_HANDLERS::cut -> stop',
            'probable ts:method:src/steps.js#STEP_HANDLERS::hold -> hold',
        ], $calls);
    }
}

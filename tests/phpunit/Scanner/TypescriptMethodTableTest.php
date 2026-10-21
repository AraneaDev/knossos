<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * `STEP_HANDLERS[step.do](step)` picks a method out of a table by a key only
 * the run time knows. Written as methods, `async type(step) { ... }`, the
 * entries had no reference at all and read as dead. The lookup references
 * each of them as possible; a literal key references the one it names, here
 * or in an imported table, certainly. A computed key references every entry
 * of a table, however many it holds.
 */
#[Group('typescript-scanner')]
final class TypescriptMethodTableTest extends KnossosTestCase
{
    public function testALookupReferencesTheMethodsItCanReach(): void
    {
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan([
                'root' => self::repositoryRoot() . '/tests/Fixtures/ts-method-tables',
                'files' => ['src/named.js', 'src/registry.js', 'src/steps.js'],
                'config_files' => ['tsconfig.json'],
            ]), false);
        } finally {
            $client->shutdown();
        }
        $uses = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                if ($edge->kind === 'references' && str_contains($edge->targetReference, '::')) {
                    $uses[] = $edge->confidence->value . ' ' . $edge->sourceReference . ' -> ' . $edge->targetReference;
                }
            }
        }
        sort($uses);

        // `stop` is never asked for; `pick` reaches every entry of its table, however large.
        $possible = array_map(static fn(int $n): string => 'possible ts:function:src/registry.js#pick -> ts:method:src/registry.js#REGISTRY::entry' . $n, range(1, 65));
        $expected = [
            'certain ts:function:src/named.js#begin -> ts:method:src/named.js#COMMANDS::start',
            'certain ts:function:src/named.js#waitOnce -> ts:method:src/steps.js#STEP_HANDLERS::wait',
            'certain ts:function:src/registry.js#first -> ts:method:src/registry.js#REGISTRY::entry1',
            'certain ts:function:src/steps.js#typeAgain -> ts:method:src/steps.js#STEP_HANDLERS::type',
            'possible ts:function:src/steps.js#run -> ts:method:src/steps.js#STEP_HANDLERS::type',
            'possible ts:function:src/steps.js#run -> ts:method:src/steps.js#STEP_HANDLERS::wait',
            'possible ts:function:src/steps.js#typeAgain -> ts:method:src/steps.js#STEP_HANDLERS::wait',
            ...$possible,
        ];
        sort($expected);
        self::assertSame($expected, $uses);
        self::assertCount(65, array_filter($uses, static fn(string $use): bool => str_starts_with($use, 'possible ts:function:src/registry.js#pick ')));
    }
}

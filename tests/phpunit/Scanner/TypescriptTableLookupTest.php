<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * `handlers[message.type]` picks a function out of a table by a key only the
 * run time knows. No identifier names the entry, so every function in the
 * table read as dead code. The lookup references each of them; a literal key
 * references only the entry it names.
 */
#[Group('typescript-scanner')]
final class TypescriptTableLookupTest extends KnossosTestCase
{
    public function testALookupReferencesTheEntriesItCanReach(): void
    {
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan([
                'root' => self::repositoryRoot() . '/tests/Fixtures/ts-table-lookups',
                'files' => ['src/dispatch.ts', 'src/handlers.ts'],
                'config_files' => ['tsconfig.json'],
            ]), false);
        } finally {
            $client->shutdown();
        }
        $uses = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                if ($edge->kind === 'references' && str_contains($edge->targetReference, '::')) {
                    $uses[] = $edge->sourceReference . ' -> ' . $edge->targetReference;
                }
            }
        }
        sort($uses);

        // `stop` is never asked for by name, and `nap` is only called.
        self::assertSame([
            'ts:function:src/dispatch.ts#dispatch -> ts:method:src/handlers.ts#handlers::ping',
            'ts:function:src/dispatch.ts#dispatch -> ts:method:src/handlers.ts#handlers::pong',
            'ts:function:src/dispatch.ts#start -> ts:method:src/handlers.ts#commands::start',
        ], $uses);
    }
}

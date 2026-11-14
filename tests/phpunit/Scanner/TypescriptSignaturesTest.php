<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\ScanLimits;
use PHPUnit\Framework\Attributes\Group;

/**
 * A function or method node carries its signature as written: parameter
 * names, annotated types and the declared return, nothing inferred, bounded
 * in parameters and in the length of a type.
 */
#[Group('typescript-scanner')]
final class TypescriptSignaturesTest extends KnossosTestCase
{
    public function testFunctionsAndMethodsCarryTheSignatureTheirSourceWrites(): void
    {
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan(['limits' => ScanLimits::DEFAULT,
                'root' => self::repositoryRoot() . '/tests/Fixtures/ts-signatures',
                'files' => ['src/api.ts'],
                'config_files' => ['tsconfig.json'],
            ]), false);
        } finally {
            $client->shutdown();
        }
        $signatures = [];
        $kinds = [];
        foreach ($contributions[0]->nodes as $node) {
            $name = substr($node->canonicalName, (int) strrpos($node->canonicalName, '#') + 1);
            $kinds[$name] = $node->kind;
            if (array_key_exists('signature', $node->attributes)) {
                $signatures[$name] = $node->attributes['signature'];
            }
        }
        ksort($signatures);

        assertSame(['Store::get', 'Store::handler', 'Store::many', 'Store::wide', 'debounce', 'load', 'scan', 'untyped'], array_keys($signatures));
        assertSame([
            'params' => [
                ['name' => 'root', 'type' => 'string'],
                ['name' => 'limit', 'type' => 'number', 'optional' => true],
                ['name' => 'mode', 'optional' => true],
                ['name' => 'rest', 'type' => 'string[]', 'variadic' => true],
            ],
            'returns' => 'Promise<void>',
        ], $signatures['scan']);
        assertSame(['params' => [['name' => 'a'], ['name' => 'b']]], $signatures['untyped']);
        assertSame(['params' => [['name' => '{…}', 'type' => 'Options'], ['name' => '[…]', 'type' => 'string[]']], 'returns' => 'Promise<Options>'], $signatures['load']);
        assertSame(['params' => [['name' => 'id', 'type' => 'number']], 'returns' => 'Options | undefined'], $signatures['Store::get']);
        assertSame(['params' => [['name' => 'event', 'type' => 'Event']], 'returns' => 'void'], $signatures['Store::handler']);

        $wide = $signatures['Store::wide'];
        assertSame(true, $wide['truncated']);
        assertSame(80, mb_strlen($wide['params'][0]['type']));
        assertSame('{ alpha: string; beta: string; ', substr($wide['params'][0]['type'], 0, 31));
        assertSame(12, count($signatures['Store::many']['params']));
        assertSame(true, $signatures['Store::many']['truncated']);

        assertSame(['function', 'method'], array_values(array_unique(array_map(static fn(string $name): string => $kinds[$name], ['scan', 'Store::get']))));
        assertSame(false, array_key_exists('Options', $signatures));
    }
}

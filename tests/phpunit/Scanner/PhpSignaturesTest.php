<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\ScanLimits;
use PHPUnit\Framework\Attributes\Group;

/**
 * A PHP function or method node carries its signature as declared: parameter
 * names, native types and the return type, nothing from docblocks, bounded in
 * parameters and in the length of a type.
 */
#[Group('php-scanner')]
final class PhpSignaturesTest extends KnossosTestCase
{
    public function testFunctionsAndMethodsCarryTheSignatureTheyDeclare(): void
    {
        $client = $this->phpWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan(['limits' => ScanLimits::DEFAULT,
                'root' => self::repositoryRoot() . '/tests/Fixtures/php-signatures',
                'files' => ['src/Api.php'],
            ]), false);
        } finally {
            $client->shutdown();
        }
        $signatures = [];
        foreach ($contributions[0]->nodes as $node) {
            if (in_array($node->kind, ['function', 'method'], true)) {
                $signatures[$node->canonicalName] = $node->attributes['signature'] ?? null;
            }
        }

        assertSame(['params' => [['name' => 'id', 'type' => 'int']], 'returns' => '?App\Model\User'], $signatures['App\Finder::find']);
        assertSame(['params' => [['name' => 'finder', 'type' => 'App\Finder'], ['name' => 'limit', 'type' => 'int', 'optional' => true]]], $signatures['App\Api::__construct']);
        assertSame([
            'params' => [
                ['name' => 'root', 'type' => 'string'],
                ['name' => 'limit', 'type' => '?int', 'optional' => true],
                ['name' => 'rest', 'type' => 'string', 'variadic' => true],
            ],
            'returns' => 'void',
        ], $signatures['App\Api::scan']);
        assertSame(['params' => [['name' => 'a'], ['name' => 'b']]], $signatures['App\Api::untyped']);
        assertSame([
            'params' => [
                ['name' => 'key', 'type' => 'int|string|null'],
                ['name' => 'items', 'type' => '(Countable&Traversable)|null'],
                ['name' => 'both', 'type' => 'Countable&Traversable'],
            ],
            'returns' => 'static',
        ], $signatures['App\Api::union']);
        assertSame(['params' => [['name' => 'rows', 'type' => 'array'], ['name' => 'each', 'type' => 'callable', 'optional' => true]], 'returns' => 'iterable'], $signatures['App\helper']);

        $wide = $signatures['App\Api::wide'];
        assertSame(true, $wide['truncated']);
        assertSame(80, mb_strlen($wide['params'][0]['type']));
        assertSame('App\Some\Very\Long\Namespace\Name\That\Goes\On\AndOn|App\Another\Equally\Long\N…', $wide['params'][0]['type']);
        assertSame('void', $wide['returns']);
        assertSame(12, count($signatures['App\Api::many']['params']));
        assertSame(true, $signatures['App\Api::many']['truncated']);
        assertSame(false, array_key_exists('truncated', $signatures['App\Api::scan']));
    }
}

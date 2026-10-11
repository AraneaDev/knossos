<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Framework;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertSame;

/**
 * The URLs a TypeScript caller requests become `endpoint` nodes carrying the
 * path template the core joins to a route: a template literal shows each
 * substitution as `{}`, and a leading one marks the prefix unknown.
 */
#[Group('framework')]
final class TypescriptEndpointsTest extends KnossosTestCase
{
    private const API = [
        'src/api.js' => "export const one = (id) => fetch(`/api/orders/\${id}`);\n"
            . "export const two = () => fetch(\"/api/orders?page=2\");\n"
            . "export const three = (base) => fetch(`\${base}/orders/new`);\n"
            . "export const four = () => fetch(\"https://api.example.com/v1/orders\");\n"
            . "export const five = (id) => axios.delete(`/api/orders/\${id}/lines`);\n",
    ];

    public function testRequestUrlsBecomeEndpointsWithTheirPathTemplate(): void
    {
        $endpoints = $this->endpoints(self::API, ['src/api.js']);

        $order = $endpoints['GET /api/orders/{}'];
        assertSame(['framework' => 'web', 'method' => 'GET', 'uri' => '/api/orders/{}', 'template' => '/api/orders/{}', 'prefix' => 'known', 'external' => false], $order);
        assertSame('/api/orders', $endpoints['GET /api/orders?page=2']['template']);
        $unknown = $endpoints['GET {}/orders/new'];
        assertSame(['{}/orders/new', '/orders/new', 'unknown'], [$unknown['uri'], $unknown['template'], $unknown['prefix']]);
        assertSame(true, $endpoints['GET https://api.example.com/v1/orders']['external']);
        assertArrayHasKey('DELETE /api/orders/{}/lines', $endpoints);
    }

    /**
     * @param array<string, string> $tree
     * @param list<string> $files
     * @return array<string, array<string, mixed>> endpoint attributes by canonical name
     */
    private function endpoints(array $tree, array $files): array
    {
        $root = $this->writeTree($tree);
        try {
            $client = $this->typescriptWorkerClient();
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => $files]), false);
            $client->shutdown();
            $found = [];
            foreach ($contributions as $contribution) {
                foreach ($contribution->nodes as $node) {
                    if ($node->kind === 'endpoint') {
                        $found[$node->canonicalName][] = $node->attributes;
                    }
                }
            }
            return array_map(static fn(array $all): array => $all[0], $found);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** @param array<string, string> $files */
    private function writeTree(array $files): string
    {
        $root = sys_get_temp_dir() . '/knossos-stale-endpoints-' . bin2hex(random_bytes(6));
        foreach ($files as $path => $contents) {
            @mkdir(dirname("{$root}/{$path}"), 0o777, true);
            file_put_contents("{$root}/{$path}", $contents);
        }
        return (string) realpath($root);
    }
}

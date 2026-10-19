<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Framework;

use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

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

    private const INSTANCES = [
        'src/http.js' => "import axios from \"axios\";\n"
            . "export const api = axios.create({ baseURL: \"/api\" });\n"
            . "export const remote = axios.create({ baseURL: \"https://x.example.com\" });\n"
            . "export const bare = axios.create();\n",
        'src/orders.js' => "import { api, remote, bare } from \"./http\";\n"
            . "export const show = (id) => api.get(`/orders/\${id}`);\n"
            . "export const store = () => api.request({ method: \"post\", url: \"/orders\" });\n"
            . "export const other = () => remote.get(\"/orders\");\n"
            . "export const plain = () => bare.get(\"/orders\");\n",
        'node_modules/axios/package.json' => '{"name":"axios","version":"1.0.0","types":"index.d.ts"}',
        'node_modules/axios/index.d.ts' => "export interface Instance {\n  get(url: string): Promise<unknown>;\n  request(config: { method?: string; url?: string }): Promise<unknown>;\n}\n"
            . "declare const axios: { create(config?: { baseURL?: string }): Instance; delete(url: string): Promise<unknown> };\nexport default axios;\n",
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

    public function testRequestsThroughAnAxiosInstanceCarryTheInstancesBaseUrl(): void
    {
        $endpoints = $this->endpoints(self::INSTANCES, ['src/http.js', 'src/orders.js']);

        foreach (['GET /api/orders/{}', 'POST /api/orders', 'GET {}/orders'] as $name) {
            assertArrayHasKey($name, $endpoints);
        }
        assertSame('known', $endpoints['GET /api/orders/{}']['prefix']);
        assertSame('known', $endpoints['POST /api/orders']['prefix']);
        assertSame(['unknown', '/orders'], [$endpoints['GET {}/orders']['prefix'], $endpoints['GET {}/orders']['template']]);
        assertSame(3, count($endpoints));
        foreach ($endpoints as $attributes) {
            assertSame(false, $attributes['external']);
        }
    }

    public function testAnInstanceBaseAndTheRequestUrlJoinWithOneSlashAndAnAbsoluteUrlStandsAlone(): void
    {
        $endpoints = $this->endpoints([
            'src/http.js' => "import axios from \"axios\";\nexport const api = axios.create({ baseURL: \"/api\" });\nexport const slashed = axios.create({ baseURL: \"/v1/\" });\n",
            'src/use.js' => "import { api, slashed } from \"./http\";\n"
                . "export const a = () => api.get(\"orders\");\n"
                . "export const b = (x) => api.get(`\${x}/orders`);\n"
                . "export const c = () => api.get(\"https://other.example.com/x\");\n"
                . "export const d = () => slashed.get(\"/orders\");\n",
            'node_modules/axios/package.json' => self::INSTANCES['node_modules/axios/package.json'],
            'node_modules/axios/index.d.ts' => self::INSTANCES['node_modules/axios/index.d.ts'],
        ], ['src/http.js', 'src/use.js']);

        assertSame(['/api/orders', '/api/orders', 'known'], [$endpoints['GET /api/orders']['uri'], $endpoints['GET /api/orders']['template'], $endpoints['GET /api/orders']['prefix']]);
        assertSame(['/api/{}/orders', 'known'], [$endpoints['GET /api/{}/orders']['template'], $endpoints['GET /api/{}/orders']['prefix']]);
        assertSame(true, $endpoints['GET https://other.example.com/x']['external']);
        assertArrayHasKey('GET /v1/orders', $endpoints);
        assertSame(4, count($endpoints));
    }

    public function testAnUnknownBaseAndAKnownDirectRequestAreDistinctEndpoints(): void
    {
        $tree = self::INSTANCES;
        $tree['src/direct.js'] = "export const direct = () => fetch(\"/orders\");\n";
        [$endpoints, $edges] = $this->scanEndpoints($tree, ['src/http.js', 'src/orders.js', 'src/direct.js']);

        assertSame(['/orders', 'known'], [$endpoints['GET /orders']['template'], $endpoints['GET /orders']['prefix']]);
        assertSame(['/orders', 'unknown'], [$endpoints['GET {}/orders']['template'], $endpoints['GET {}/orders']['prefix']]);
        assertContains('ts:function:src/orders.js#show -> ts:endpoint:GET /api/orders/{}', $edges);
        assertContains('ts:function:src/orders.js#plain -> ts:endpoint:GET {}/orders', $edges);
        assertContains('ts:function:src/direct.js#direct -> ts:endpoint:GET /orders', $edges);
    }

    public function testRequestWithoutAMethodIsAGetAndOneWithAComputedMethodIsAnyMethod(): void
    {
        $endpoints = $this->endpoints([
            'src/http.js' => "import axios from \"axios\";\nexport const api = axios.create({ baseURL: \"/api\" });\n",
            'src/use.js' => "import { api } from \"./http\";\n"
                . "export const a = () => api.request({ url: \"/a\" });\n"
                . "export const b = (m) => api.request({ method: m, url: \"/b\" });\n",
            'node_modules/axios/package.json' => self::INSTANCES['node_modules/axios/package.json'],
            'node_modules/axios/index.d.ts' => self::INSTANCES['node_modules/axios/index.d.ts'],
        ], ['src/http.js', 'src/use.js']);

        $names = array_keys($endpoints);
        sort($names);
        assertSame(['ANY /api/b', 'GET /api/a'], $names);
    }

    public function testTheDirectAxiosImportReadsHeadAndRequestLikeAnInstance(): void
    {
        $endpoints = $this->endpoints([
            'src/use.js' => "import axios from \"axios\";\n"
                . "export const a = () => axios.head(\"/api/a\");\n"
                . "export const b = () => axios.request({ method: \"post\", url: \"/api/b\" });\n"
                . "export const c = () => axios.request({ url: \"/api/c\" });\n"
                . "export const d = (m) => axios.request({ method: m, url: \"/api/d\" });\n"
                . "export const e = (id) => axios.request({ method: \"get\", url: `/api/e/\${id}` });\n",
        ], ['src/use.js']);

        $names = array_keys($endpoints);
        sort($names);
        assertSame(['ANY /api/d', 'GET /api/c', 'GET /api/e/{}', 'HEAD /api/a', 'POST /api/b'], $names);
    }

    public function testAnInstanceExportedAsTheDefaultIsRecognised(): void
    {
        $endpoints = $this->endpoints([
            'src/http.js' => "import axios from \"axios\";\nexport default axios.create({ baseURL: \"/api\" });\n",
            'src/use.js' => "import api from \"./http\";\nexport const a = () => api.get(\"/x\");\n",
            'node_modules/axios/package.json' => self::INSTANCES['node_modules/axios/package.json'],
            'node_modules/axios/index.d.ts' => self::INSTANCES['node_modules/axios/index.d.ts'],
        ], ['src/http.js', 'src/use.js']);

        assertSame(['GET /api/x'], array_keys($endpoints));
    }

    public function testAFetchWithAComputedMethodIsAnyMethod(): void
    {
        $endpoints = $this->endpoints([
            'src/use.js' => "export const a = (m) => fetch(\"/api/a\", { method: m });\n"
                . "export const b = () => fetch(\"/api/b\", { method: \"put\" });\n"
                . "export const c = () => fetch(\"/api/c\");\n"
                . "export const d = (method) => fetch(\"/api/d\", { method });\n",
        ], ['src/use.js']);

        $names = array_keys($endpoints);
        sort($names);
        assertSame(['ANY /api/a', 'ANY /api/d', 'GET /api/c', 'PUT /api/b'], $names);
    }

    public function testAnInstanceCreatedThroughAnyNameForAxiosOrReExportedIsRecognised(): void
    {
        $endpoints = $this->endpoints([
            'src/http.js' => "import http from \"axios\";\nexport const api = http.create({ baseURL: \"/api\" });\n",
            'src/index.js' => "export { api } from \"./http\";\n",
            'src/use.js' => "import { api } from \"./index\";\nexport const a = () => api.get(\"/x\");\n",
            'node_modules/axios/package.json' => self::INSTANCES['node_modules/axios/package.json'],
            'node_modules/axios/index.d.ts' => self::INSTANCES['node_modules/axios/index.d.ts'],
        ], ['src/http.js', 'src/index.js', 'src/use.js']);

        assertSame(['GET /api/x'], array_keys($endpoints));
    }

    public function testASubstitutionInsideASegmentMakesTheSegmentAParameterAndABareOneNamesNoPath(): void
    {
        [$endpoints, $edges] = $this->scanEndpoints(['src/api.js' => "export const a = (s) => fetch(`/api/orders\${s}`);\n"
            . "export const b = (k) => fetch(`/api/\${k}-list`);\n"
            . "export const c = (x, y) => fetch(`/api/\${x}/lines/\${y}`);\n"
            . "export const d = (x, y) => fetch(`\${x}\${y}`);\n"
            . "export const e = (id) => fetch(`https://h.example.com/\${id}`);\n"], ['src/api.js']);

        assertSame('/api/{}', $endpoints['GET /api/orders{}']['template']);
        assertSame('/api/{}', $endpoints['GET /api/{}-list']['template']);
        assertSame('/api/{}/lines/{}', $endpoints['GET /api/{}/lines/{}']['template']);
        assertSame(true, $endpoints['GET https://h.example.com/{}']['external']);
        assertSame(4, count($endpoints));
        assertSame(4, count($edges));
    }

    /**
     * @param array<string, string> $tree
     * @param list<string> $files
     * @return array<string, array<string, mixed>> endpoint attributes by canonical name
     */
    private function endpoints(array $tree, array $files): array
    {
        return $this->scanEndpoints($tree, $files)[0];
    }

    /**
     * @param array<string, string> $tree
     * @param list<string> $files
     * @return array{array<string, array<string, mixed>>, list<string>} endpoint attributes by canonical name, and the "source -> target" of each calls_endpoint edge
     */
    private function scanEndpoints(array $tree, array $files): array
    {
        $root = $this->writeTree($tree);
        try {
            $client = $this->typescriptWorkerClient();
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => $files]), false);
            $client->shutdown();
            $found = [];
            $edges = [];
            foreach ($contributions as $contribution) {
                foreach ($contribution->edges as $edge) {
                    if ($edge->kind === 'calls_endpoint') {
                        $edges[] = "{$edge->sourceReference} -> {$edge->targetReference}";
                    }
                }
                foreach ($contribution->nodes as $node) {
                    if ($node->kind === 'endpoint') {
                        $found[$node->canonicalName][] = $node->attributes;
                    }
                }
            }
            return [array_map(static fn(array $all): array => $all[0], $found), $edges];
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

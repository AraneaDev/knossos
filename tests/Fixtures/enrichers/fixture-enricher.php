<?php

declare(strict_types=1);

/*
 * A stand-in external enricher speaking the worker protocol over stdio.
 *
 * argv[1] is the mode, or `@<file>` to read the mode from that file on each
 * start (so a test can switch modes without changing the command). argv[2] is
 * an optional marker file written as soon as the process starts, so a test can
 * tell whether the enricher was started at all.
 *
 * Modes:
 *   ok                  the rest call -> endpoint -> Django view chain for the endpoint-routes fixture
 *   ok-less             as ok, without the endpoint node and the edges that touch it
 *   ok-changed          as ok, with the endpoint renamed and its confidence lowered to possible
 *   ok-extends          as ok, plus an extends edge between two native classes of the enricher-flow fixture
 *   ok-reporting        as ok, plus a warning and an error diagnostic of the enricher's own
 *   env                 one node reporting FIXTURE_FLAG, whether a test secret leaked, and whether PATH is set
 *   echo-input          one ext:echo node per native node received, carrying it in `received`
 *   no-confidence       one node and no confidence on it
 *   no-capability       a manifest without the enrichment capability
 *   garbage             a line that is not JSON in reply to scan
 *   stderr-then-garbage noise on stderr, then the same line
 *   hang                never answers scan
 *   exit-1              exits with status 1 on scan
 *   exit-0              exits cleanly with status 0 on scan, without answering
 *   slow-steps          answers initialize and scan 700 ms late each, so only a per-run timeout catches it
 */

$mode = $argv[1] ?? 'ok';
if (str_starts_with($mode, '@')) {
    $mode = trim((string) file_get_contents(substr($mode, 1)));
}
$marker = $argv[2] ?? null;
if (is_string($marker) && $marker !== '') {
    file_put_contents($marker, $mode . "\n", FILE_APPEND);
}

while (($line = fgets(STDIN)) !== false) {
    $request = json_decode($line, true);
    if (!is_array($request)) {
        continue;
    }
    $method = $request['method'] ?? '';
    $id = $request['id'] ?? null;

    if ($method === 'initialize') {
        if ($mode === 'slow-steps') {
            usleep(700_000);
        }
        respond($id, [
            'id' => 'fixture.enricher',
            'version' => '0.1.0',
            'protocol_version' => '1.0',
            'output_schema_version' => '1.0',
            'languages' => ['ext'],
            'file_extensions' => [],
            'capabilities' => $mode === 'no-capability' ? [] : ['enrichment'],
        ]);
        continue;
    }
    if ($method === 'shutdown') {
        respond($id, []);
        exit(0);
    }
    if ($method !== 'scan') {
        continue;
    }

    $native = $request['params']['native_nodes'] ?? [];
    switch ($mode) {
        case 'garbage':
            fwrite(STDOUT, "this is not json\n");
            fflush(STDOUT);
            continue 2;
        case 'stderr-then-garbage':
            fwrite(STDERR, str_repeat("enricher noise on stderr\n", 50));
            fwrite(STDOUT, "this is not json\n");
            fflush(STDOUT);
            continue 2;
        case 'hang':
            sleep(60);
            continue 2;
        case 'exit-0':
            exit(0);
        case 'slow-steps':
            usleep(700_000);
            notify(chain($native, 'ok'));
            break;
        case 'exit-1':
            fwrite(STDERR, "enricher failed\n");
            exit(1);
        case 'echo-input':
            $nodes = [];
            foreach ($native as $index => $received) {
                $nodes[] = node('echo' . $index, 'ext:echo', (string) $received['id'], (string) $received['file'], (int) $received['line'], ['received' => $received]);
            }
            notify(['nodes' => $nodes, 'edges' => []]);
            break;
        case 'env':
            $attributes = ['flag' => getenv('FIXTURE_FLAG'), 'secret' => getenv('KNOSSOS_ENRICHER_TEST_SECRET'), 'path_set' => getenv('PATH') !== false];
            notify(['nodes' => [node('env', 'ext:env', 'env', 'frontend/src/components/OrderList.vue', 1, $attributes)], 'edges' => []]);
            break;
        case 'no-confidence':
            $node = node('bare', 'ext:bare', 'bare', 'frontend/src/components/OrderList.vue', 1);
            unset($node['confidence']);
            notify(['nodes' => [$node], 'edges' => []]);
            break;
        case 'ok-reporting':
            notify(chain($native, 'ok'), [
                ['severity' => 'warning', 'code' => 'FIXTURE_NOISE', 'message' => 'A warning of the enricher\'s own.'],
                ['severity' => 'error', 'code' => 'FIXTURE_FAULT', 'message' => 'An error of the enricher\'s own.'],
            ]);
            break;
        default:
            notify(chain($native, $mode));
    }
    respond($id, []);
}

/**
 * The ok-family facts: OrderList.vue's search() fetch as a rest call, the
 * endpoint it calls, and the Django view in urls.py that endpoint stands for.
 *
 * @param list<array<string, mixed>> $native
 * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
 */
function chain(array $native, string $mode): array
{
    $component = nativeNode($native, 'module', 'frontend/src/components/OrderList.vue')['id'] ?? null;
    $view = nativeNode($native, 'function', 'backend.app.urls.search');
    $vue = 'frontend/src/components/OrderList.vue';
    $urls = 'backend/app/urls.py';
    $nodes = [node('call-search', 'ext:rest_call', 'GET /task/search/ from OrderList.vue', $vue, 9)];
    $edges = [];
    if ($component !== null) {
        $edges[] = edge('calls_endpoint', $component, 'call-search', $vue, 9);
    }
    if ($mode !== 'ok-less') {
        $endpoint = node('endpoint-search', 'ext:endpoint', '/task/search/', $urls, 8);
        if ($mode === 'ok-changed') {
            $endpoint['display_name'] = 'task search (changed)';
            $endpoint['confidence'] = 'possible';
        }
        $nodes[] = $endpoint;
        $edges[] = edge('calls_endpoint', 'call-search', 'endpoint-search', $vue, 9);
        if ($view !== null) {
            // Cites the view's own declaration, as a represents link must.
            $edges[] = edge('represents', 'endpoint-search', (string) $view['id'], (string) $view['file'], (int) $view['line']);
        }
    }

    $orphan = nativeNode($native, 'class', 'backend.app.handlers.Orphan');
    $base = nativeNode($native, 'class', 'backend.app.handlers.Base');
    if ($mode === 'ok-extends' && $orphan !== null && $base !== null) {
        // Native to native: an enricher's claim that Orphan inherits Base's handle().
        $edges[] = edge('extends', (string) $orphan['id'], (string) $base['id'], (string) $orphan['file'], (int) $orphan['line']);
    }

    return ['nodes' => $nodes, 'edges' => $edges];
}

/**
 * The native node Knossos sent, by kind and qualified name.
 *
 * @param list<array<string, mixed>> $native
 * @return array<string, mixed>|null
 */
function nativeNode(array $native, string $kind, string $qualifiedName): ?array
{
    foreach ($native as $node) {
        if (($node['kind'] ?? null) === $kind && ($node['qualified_name'] ?? null) === $qualifiedName) {
            return $node;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $attributes
 * @return array<string, mixed>
 */
function node(string $id, string $kind, string $name, string $path, int $line, array $attributes = []): array
{
    return [
        'local_id' => $id,
        'kind' => $kind,
        'canonical_name' => $name,
        'display_name' => $name,
        'origin' => 'enricher',
        'confidence' => 'probable',
        'evidence' => ['path' => $path, 'start_line' => $line, 'end_line' => $line],
        'attributes' => (object) $attributes,
    ];
}

/** @return array<string, mixed> */
function edge(string $kind, string $source, string $target, string $path, int $line): array
{
    return [
        'kind' => $kind,
        'source' => $source,
        'target' => $target,
        'origin' => 'enricher',
        'confidence' => 'probable',
        'evidence' => ['path' => $path, 'start_line' => $line, 'end_line' => $line],
        'attributes' => (object) [],
    ];
}

/**
 * @param array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $facts
 * @param list<array<string, mixed>> $diagnostics
 */
function notify(array $facts, array $diagnostics = []): void
{
    write([
        'jsonrpc' => '2.0',
        'method' => 'scan/contribution',
        'params' => ['owner_key' => 'fixture', 'nodes' => $facts['nodes'], 'edges' => $facts['edges'], 'diagnostics' => $diagnostics],
    ]);
}

function respond(mixed $id, array $result): void
{
    write(['jsonrpc' => '2.0', 'id' => $id, 'result' => (object) $result]);
}

/** @param array<string, mixed> $message */
function write(array $message): void
{
    fwrite(STDOUT, json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    fflush(STDOUT);
}

<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Query\StalenessProbe;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

/**
 * A webpack project declaring its module roots in `resolve.modules`, and a
 * Vue CLI one adding them in `chainWebpack`: a bare import resolves under a
 * root webpack tries before `node_modules`, and under one it tries after only
 * once no package answers. Written out by the test, since a fixture's
 * `node_modules` is never committed.
 */
final class WebpackModuleRootsScanTest extends KnossosTestCase
{
    private const FILES = [
        'package.json' => '{"name":"shop","dependencies":{"vue":"^2.7.0","config":"1.0.0"}}',
        'webpack.config.js' => "const path = require('path');\nconst base = ['.js'];\n"
            . "module.exports = {\n  resolve: {\n    modules: [path.resolve('src'), 'node_modules', path.resolve(__dirname, 'lib'), rootsOf()],\n"
            . "    extensions: [...base, '.vue'],\n  },\n  module: { rules: [{ test: /x/, resolve: { modules: rulesOwn() } }] },\n};\n",
        'vue.config.js' => "module.exports = { chainWebpack: (config) => { config.resolve.modules.prepend('src').add('vendor'); config.resolve.extensions.add('.vue'); } };\n",
        'src/main.js' => "import Search from 'checkout/Search';\nimport settings from 'config';\nimport { format } from 'helpers/format';\nimport { pad } from 'strings/pad';\n"
            . "export default [Search, settings, format, pad];\n",
        'src/checkout/Search.vue' => "<template><div/></template>\n<script>\nexport default { name: 'Search' };\n</script>\n",
        'lib/config/index.js' => "export default 'local';\n",
        'lib/helpers/format.js' => "export const format = (value) => String(value);\n",
        'vendor/strings/pad.js' => "export const pad = (value) => ` \${value}`;\n",
        'node_modules/config/package.json' => '{"name":"config","main":"index.js"}',
        'node_modules/config/index.js' => "module.exports = 'package';\n",
    ];

    private const SCRIPT_CONFIG_FILES = [
        'package.json' => '{"name":"shop","scripts":{"dev":"webpack-dev-server --inline --config build/webpack.dev.conf.js"},"dependencies":{"vue":"^2.7.0"}}',
        'build/webpack.dev.conf.js' => "const merge = require('webpack-merge');\nconst base = require('./webpack.base.conf');\nconst utils = require('./utils');\nmodule.exports = merge(base, { devtool: 'eval' });\n",
        'build/webpack.base.conf.js' => "const path = require('path');\n"
            . "module.exports = { resolve: { modules: [path.resolve(__dirname, '../src'), 'node_modules'], extensions: ['.js', '.vue'] } };\n",
        'build/utils.js' => "module.exports = { resolve: { modules: ['vendor'] } };\n",
        'src/main.js' => "import Search from 'checkout/Search';\nimport { pad } from 'strings/pad';\nexport default [Search, pad];\n",
        'src/checkout/Search.vue' => "<template><div/></template>\n<script>\nexport default { name: 'Search' };\n</script>\n",
        'vendor/strings/pad.js' => "export const pad = (value) => ` \${value}`;\n",
    ];

    #[Group('scan')]
    public function testImportsResolveUnderEachRootWhereWebpackTriesIt(): void
    {
        $root = $this->writeTree(self::FILES);
        try {
            [$targets, $unread] = $this->mainImports($root, ['src/main.js', 'src/checkout/Search.vue', 'lib/config/index.js', 'lib/helpers/format.js', 'vendor/strings/pad.js']);

            // Before node_modules, and an extensionless component under it.
            assertContains('ts:module:src/checkout/Search.vue', $targets);
            // After node_modules: only once no package answers.
            assertContains('ts:module:lib/helpers/format.js', $targets);
            assertContains('ts:module:vendor/strings/pad.js', $targets);
            assertNotContains('ts:module:lib/config/index.js', $targets);
            // Only the root it cannot read; the rule's own resolve and the spread extension say nothing.
            assertNotSame([], $unread);
            foreach ($unread as $message) {
                assertStringContainsString('webpack.config.js: resolve.modules could not be read', $message);
            }
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * A config under another name, as a package script passes it with
     * `--config`, that merges a base config it requires: the roots and
     * extensions the base declares hold, with `__dirname` read against the
     * base's own directory.
     */
    #[Group('scan')]
    public function testRootsComeFromTheConfigAScriptPassesAndTheBaseItRequires(): void
    {
        $root = $this->writeTree(self::SCRIPT_CONFIG_FILES);
        try {
            [$targets] = $this->mainImports($root, ['src/main.js', 'src/checkout/Search.vue', 'vendor/strings/pad.js']);

            assertContains('ts:module:src/checkout/Search.vue', $targets);
            // A required helper is not a config: its `resolve` is not webpack's.
            assertNotContains('ts:module:vendor/strings/pad.js', $targets);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * `-c` among other commands of one script, naming a config whose name does
     * not say webpack; it imports its base twice by a path with an extension,
     * and the base imports itself. A `--config` that leaves the root is not
     * read.
     */
    #[Group('scan')]
    public function testAShortFlagAndAnImportedBaseResolveAndACycleEnds(): void
    {
        $root = $this->writeTree([
            'package.json' => '{"name":"shop","scripts":{"build":"cross-env NODE_ENV=production webpack -c config/prod.mjs && echo done","count":7,"out":"webpack --config=../outside.js"}}',
            'config/prod.mjs' => "import common from './webpack.common.mjs';\nimport './webpack.common.mjs';\nexport default common;\n",
            'config/webpack.common.mjs' => "import path from 'path';\nimport './webpack.common.mjs';\nexport default { resolve: { modules: [path.join(__dirname, '../app'), 'node_modules'] } };\n",
            'src/main.js' => "import { thing } from 'widgets/thing';\nexport default thing;\n",
            'app/widgets/thing.js' => "export const thing = 1;\n",
        ]);
        try {
            [$targets] = $this->mainImports($root, ['src/main.js', 'app/widgets/thing.js']);

            assertContains('ts:module:app/widgets/thing.js', $targets);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A manifest that is not JSON names no config; the conventional ones still hold. */
    #[Group('scan')]
    public function testAManifestThatIsNotJsonLeavesTheConventionalConfigs(): void
    {
        $root = $this->writeTree([
            'package.json' => '{"scripts": ',
            'webpack.config.js' => "module.exports = { resolve: { modules: ['app', 'node_modules'] } };\n",
            'src/main.js' => "import { thing } from 'widgets/thing';\nexport default thing;\n",
            'app/widgets/thing.js' => "export const thing = 1;\n",
        ]);
        try {
            [$targets] = $this->mainImports($root, ['src/main.js', 'app/widgets/thing.js']);

            assertContains('ts:module:app/widgets/thing.js', $targets);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * Through the core: the base config under `build/`, a directory discovery
     * leaves out, is read, verified at commit and kept for freshness, so an
     * edit to it marks the graph stale.
     */
    #[Group('scan')]
    public function testAnEditToARequiredConfigOutsideDiscoveryMakesTheGraphStale(): void
    {
        $root = $this->writeTree(self::SCRIPT_CONFIG_FILES);
        try {
            $pdo = $this->freshTestDatabase();
            $result = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
            assertSame([], $result->data['degraded_languages']);
            $imports = $pdo->query(
                "SELECT t.canonical_name FROM edges e JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id "
                . "WHERE e.kind = 'imports' AND s.canonical_name = 'src/main.js'",
            )->fetchAll(\PDO::FETCH_COLUMN);
            assertContains('src/checkout/Search.vue', $imports);

            file_put_contents("{$root}/build/webpack.base.conf.js", "module.exports = {};\n");

            assertSame('stale', (new StalenessProbe($pdo))->probe($result->projectId)['state'] ?? null);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** @param array<string, string> $files */
    private function writeTree(array $files): string
    {
        $root = sys_get_temp_dir() . '/knossos-stale-webpack-' . bin2hex(random_bytes(6));
        foreach ($files as $path => $contents) {
            @mkdir(dirname("{$root}/{$path}"), 0o777, true);
            file_put_contents("{$root}/{$path}", $contents);
        }
        return (string) realpath($root);
    }

    /**
     * What `src/main.js` imports, and the BUNDLER_CONFIG_UNREAD messages.
     *
     * @param list<string> $files
     * @return array{list<string>, list<string>}
     */
    private function mainImports(string $root, array $files): array
    {
        // Through the worker client, as the core drives it, so the worker's coverage is counted.
        $client = $this->typescriptWorkerClient();
        $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => $files]), false);
        $client->shutdown();
        $targets = [];
        $unread = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                if ($edge->kind === 'imports' && $edge->sourceReference === 'ts:module:src/main.js') {
                    $targets[] = $edge->targetReference;
                }
            }
            foreach ($contribution->diagnostics as $diagnostic) {
                if ($diagnostic->code === 'BUNDLER_CONFIG_UNREAD') {
                    $unread[] = $diagnostic->message;
                }
            }
        }
        return [$targets, $unread];
    }
}

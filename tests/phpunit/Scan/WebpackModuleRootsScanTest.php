<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertNotSame;
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

    #[Group('scan')]
    public function testImportsResolveUnderEachRootWhereWebpackTriesIt(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-webpack-' . bin2hex(random_bytes(6));
        foreach (self::FILES as $path => $contents) {
            @mkdir(dirname("{$root}/{$path}"), 0o777, true);
            file_put_contents("{$root}/{$path}", $contents);
        }
        $root = (string) realpath($root);
        try {
            // Through the worker client, as the core drives it, so the worker's coverage is counted.
            $client = $this->typescriptWorkerClient();
            $contributions = iterator_to_array($client->scan([
                'root' => $root,
                'files' => ['src/main.js', 'src/checkout/Search.vue', 'lib/config/index.js', 'lib/helpers/format.js', 'vendor/strings/pad.js'],
            ]), false);
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
}

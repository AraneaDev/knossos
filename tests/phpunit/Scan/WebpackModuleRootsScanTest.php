<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertNotSame;

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
        try {
            $pdo = $this->freshTestDatabase();
            $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;
            $targets = $this->importsOf($pdo, $projectId, 'src/main.js');

            // Before node_modules, and an extensionless component under it.
            assertContains('src/checkout/Search.vue', $targets);
            // After node_modules: only once no package answers.
            assertContains('lib/helpers/format.js', $targets);
            assertContains('vendor/strings/pad.js', $targets);
            assertNotContains('lib/config/index.js', $targets);

            $statement = $pdo->prepare("SELECT message FROM diagnostics WHERE project_id = ? AND code = 'BUNDLER_CONFIG_UNREAD'");
            $statement->execute([$projectId]);
            $messages = $statement->fetchAll(PDO::FETCH_COLUMN);
            assertNotSame([], $messages);
            foreach ($messages as $message) {
                // The rule's own resolve and the spread extension say nothing.
                assertNotSame(false, strpos((string) $message, 'webpack.config.js: resolve.modules could not be read'));
            }
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** @return list<string> */
    private function importsOf(PDO $pdo, string $projectId, string $source): array
    {
        $statement = $pdo->prepare(
            "SELECT t.canonical_name FROM edges e JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id "
            . "WHERE e.project_id = ? AND e.kind = 'imports' AND s.canonical_name = ?",
        );
        $statement->execute([$projectId, $source]);
        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }
}

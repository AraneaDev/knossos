<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use Knossos\Install\Installation;

/**
 * Where `install-agent-plugin` materialises the plugin, and which binary its hooks call.
 *
 * A source checkout keeps what it always had: `<root>/.plugin`, and the
 * checkout's own `bin/knossos`. An installed release must not use either:
 * its root is `<install dir>/versions/<version>`, which self-update replaces
 * and later deletes, so a plugin made there would run old code after the
 * first update and lose its marketplace directory after the second. There the
 * plugin goes to `<install dir>/plugin` and the hooks call the launcher, both
 * of which outlive every version. Options may name other paths, but never
 * one inside `versions/`, so a hand-run install cannot bring the defect back.
 */
final readonly class PluginTarget
{
    /**
     * Where a checkout materialises the plugin, relative to the installation root.
     *
     * The install registers this directory as the marketplace, never the
     * installation root itself. Claude Code snapshots a local marketplace into
     * its plugin cache, so handing it the root copied the entire working tree,
     * `vendor/`, `node_modules/`, build caches, the graph database and any
     * `.mcp.json` along with it. A directory holding only the plugin's own
     * files copies only those, and carries nothing that could register a
     * second, unintended MCP server.
     */
    private const CHECKOUT_DIRECTORY = '/.plugin';

    /**
     * @param string $directory the marketplace directory the plugin is written to
     * @param string $bin the knossos binary baked into the hooks' `lib.sh`
     */
    private function __construct(public string $directory, public string $bin) {}

    /**
     * The target for the code at `$root`, with the `--plugin-dir` and `--bin` options applied.
     *
     * @throws InvalidArgumentException for a relative path, or one inside an installed release's `versions/`
     * @throws \Knossos\Install\InstallException when an installed release cannot tell where its launcher is, or when
     *         the code sits in a `versions/` directory whose `install.json` does not record it
     */
    public static function resolve(string $root, ?string $directory, ?string $bin): self
    {
        $installation = new Installation($root);
        // Under versions/ without a record that names it: making the plugin here would put it back inside versions/.
        $installation->assertNotUnrecorded();
        $installDir = $installation->installDir;
        $directory = self::absolute($directory, 'plugin-dir') ?? ($installDir === null ? $root . self::CHECKOUT_DIRECTORY : $installation->pluginDir());
        $bin = self::absolute($bin, 'bin') ?? ($installDir === null ? $root . '/bin/knossos' : $installation->launcher());
        if ($installDir !== null) {
            foreach (['plugin-dir' => $directory, 'bin' => $bin] as $name => $path) {
                $physical = self::physical($path);
                if (str_starts_with($physical . '/', $installDir . '/versions/')) {
                    throw new InvalidArgumentException(sprintf(
                        '%s must not be inside %s/versions: a later self-update deletes that directory. Leave it out to use %s.',
                        $name,
                        $installDir,
                        $name === 'bin' ? $installation->launcher() : $installation->pluginDir(),
                    ));
                }
            }
        }

        return new self($directory, $bin);
    }

    /** An option's value, refused when relative, or null when it was not given. */
    private static function absolute(?string $value, string $name): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!str_starts_with($value, '/')) {
            throw new InvalidArgumentException(sprintf('%s must be an absolute path, got "%s".', $name, $value));
        }

        return $value;
    }

    /** The path with every part that exists resolved, so a link cannot hide a path under `versions/`. */
    private static function physical(string $path): string
    {
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }
        $parent = dirname($path);

        return $parent === $path ? $path : rtrim(self::physical($parent), '/') . '/' . basename($path);
    }
}

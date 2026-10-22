<?php

declare(strict_types=1);

namespace Knossos\Discovery;

use Knossos\Discovery\Manifest\ManifestPaths;

/**
 * Derives the entry points that need more than one unit, or the file list, to
 * see: the sources behind published build output, classes a config names,
 * the files in directories a framework loads, glob patterns matched against
 * discovered files, and the scripts an HTML page loads.
 */
final class EntryPointResolver
{
    /**
     * The units with the cross-unit entry points added, the passes run in a
     * fixed order: build-output sources, class names, loaded directories,
     * globs, HTML scripts.
     *
     * @param list<ProjectUnit> $units sorted by kind and config path
     * @param list<DiscoveredFile> $files sorted by path
     * @param array<string, string> $webRoots URL prefix => project folder, from `knossos.json`
     * @param list<DiscoveryDiagnostic> $diagnostics appended to with the script references no single file answers to
     * @param array<string, list<string>> $compiled `tsc` output discovery left out beside its source => the sources it is built from
     * @return list<ProjectUnit>
     */
    public static function resolve(array $units, array $files, array $webRoots = [], array &$diagnostics = [], array $compiled = []): array
    {
        $units = self::withBuildOutputSources($units);
        $units = self::withClassNameEntryPoints($units);
        $units = self::withLoadedDirectoryEntryPoints($units, $files);
        $units = self::withGlobEntryPoints($units, $files);
        $units = self::withMarkdownScriptEntryPoints($units, $files);

        return self::withHtmlScriptEntryPoints($units, $files, $webRoots, $diagnostics, $compiled);
    }

    /** What Vite tries after a module path that names no file, in order. */
    private const MARKDOWN_IMPORT_SUFFIXES = ['', '.vue', '.ts', '.mts', '.tsx', '.js', '.mjs', '.jsx', '/index.ts', '/index.js'];

    /**
     * Set, on each markdown unit, the scanned files its page scripts import,
     * for a page inside a VitePress or VuePress site; a page outside every
     * site is never compiled and gets none.
     *
     * A site is the directory holding `.vitepress/config.*` or
     * `.vuepress/config.*`. A specifier counts when it is a path: relative
     * to the page, or under the site's theme through VitePress's `@theme/`
     * alias. A bare package is not this project's to enter, and a path that
     * names no scanned file is passed over quietly, as Vite would fail the
     * build on it anyway.
     *
     * @param list<ProjectUnit> $units
     * @param list<DiscoveredFile> $files
     * @return list<ProjectUnit>
     */
    private static function withMarkdownScriptEntryPoints(array $units, array $files): array
    {
        $scanned = [];
        $sites = [];
        foreach ($files as $file) {
            $scanned[$file->relativePath] = true;
            if (preg_match('#^(?:(.*)/)?\.(vitepress|vuepress)/config\.[cm]?[jt]s$#', $file->relativePath, $match) === 1) {
                $sites[$match[1]] = $match[2];
            }
        }
        // The deepest site holding a page is the one that compiles it.
        krsort($sites, SORT_STRING);
        $resolved = [];
        foreach ($units as $unit) {
            $imports = $unit->metadata['script_imports'] ?? null;
            if ($unit->kind !== 'markdown' || !is_array($imports)) {
                $resolved[] = $unit;
                continue;
            }
            $site = self::siteOf($unit->configPath, $sites);
            $paths = [];
            foreach ($site === null ? [] : $imports as $import) {
                $specifier = is_array($import) ? ($import['specifier'] ?? null) : null;
                $path = is_string($specifier) ? self::markdownImportTarget($unit->configPath, $specifier, $site, $scanned) : null;
                if ($path !== null) {
                    $paths[$path] = true;
                }
            }
            $paths = array_map(strval(...), array_keys($paths));
            sort($paths, SORT_STRING);
            $resolved[] = new ProjectUnit($unit->kind, $unit->configPath, $unit->contentHash, [
                ...$unit->metadata,
                'entry_points' => $paths,
            ]);
        }

        return $resolved;
    }

    /**
     * The site a page belongs to, as its directory and kind, or null.
     *
     * @param array<string, string> $sites directory => `vitepress` or `vuepress`, deepest first
     * @return ?array{0: string, 1: string}
     */
    private static function siteOf(string $page, array $sites): ?array
    {
        foreach ($sites as $directory => $kind) {
            $directory = (string) $directory;
            if ($directory === '' || str_starts_with($page, $directory . '/')) {
                return [$directory, $kind];
            }
        }

        return null;
    }

    /**
     * The scanned file one page import names, or null.
     *
     * @param array{0: string, 1: string} $site
     * @param array<string, true> $scanned
     */
    private static function markdownImportTarget(string $page, string $specifier, array $site, array $scanned): ?string
    {
        [$directory, $kind] = $site;
        if (str_starts_with($specifier, './') || str_starts_with($specifier, '../')) {
            $joined = ManifestPaths::joinPath(ManifestPaths::manifestDirectory($page), $specifier);
        } elseif ($kind === 'vitepress' && str_starts_with($specifier, '@theme/')) {
            $joined = ManifestPaths::joinPath($directory, '.vitepress/theme/' . substr($specifier, strlen('@theme/')));
        } else {
            return null;
        }
        $path = ManifestPaths::withinProject($joined);
        if ($path === null) {
            return null;
        }
        foreach (self::MARKDOWN_IMPORT_SUFFIXES as $suffix) {
            if (isset($scanned[$path . $suffix])) {
                return $path . $suffix;
            }
        }

        return null;
    }

    /**
     * Set, on each HTML unit, the scanned files its script references name,
     * and report each local reference that names none or several.
     *
     * The page records what it asked for ({@see Manifest\HtmlManifest});
     * only here, with the file list, can that be turned into files, and only
     * a file in the scan can be an entry point. A miss is a warning and
     * never an error: a page loading a script the scan cannot place must not
     * fail a scan or a gate.
     *
     * @param list<ProjectUnit> $units
     * @param list<DiscoveredFile> $files
     * @param array<string, string> $webRoots
     * @param list<DiscoveryDiagnostic> $diagnostics
     * @param array<string, list<string>> $compiled
     * @return list<ProjectUnit>
     */
    private static function withHtmlScriptEntryPoints(array $units, array $files, array $webRoots, array &$diagnostics, array $compiled): array
    {
        $scanned = [];
        foreach ($files as $file) {
            $scanned[$file->relativePath] = true;
        }
        $resolved = [];
        foreach ($units as $unit) {
            $refs = $unit->metadata['script_refs'] ?? null;
            if ($unit->kind !== 'html' || !is_array($refs)) {
                $resolved[] = $unit;
                continue;
            }
            $paths = [];
            foreach ($refs as $ref) {
                if (!is_array($ref) || !is_string($ref['url'] ?? null) || !is_int($ref['line'] ?? null)) {
                    continue;
                }
                $result = HtmlScriptResolver::resolve($unit->configPath, $ref['url'], $scanned, $webRoots, $compiled);
                if (is_string($result)) {
                    $paths[$result] = true;
                } elseif ($result !== null) {
                    $diagnostics[] = self::scriptMiss($unit->configPath, $ref['url'], $ref['line'], $result);
                }
            }
            $paths = array_map(strval(...), array_keys($paths));
            sort($paths, SORT_STRING);
            $resolved[] = new ProjectUnit($unit->kind, $unit->configPath, $unit->contentHash, [
                ...$unit->metadata,
                'entry_points' => $paths,
            ]);
        }

        return $resolved;
    }

    /** The warning for one script reference no single scanned file answers to. */
    private static function scriptMiss(string $page, string $url, int $line, HtmlScriptMiss $miss): DiscoveryDiagnostic
    {
        $where = sprintf('%s:%d loads %s', $page, $line, $url);
        $message = match ($miss->reason) {
            'ambiguous' => sprintf('%s, which matches more than one scanned file (%s); map its URL prefix to one folder with web_roots in knossos.json.', $where, implode(', ', $miss->candidates)),
            'mapped-missing' => sprintf('%s, which web_roots maps to %s, and that is not a scanned file.', $where, implode(', ', $miss->candidates)),
            'outside' => sprintf('%s, which points above the project root.', $where),
            default => sprintf('%s, which matches no scanned file; if the server maps its URL prefix to a folder, name it with web_roots in knossos.json.', $where),
        };

        return new DiscoveryDiagnostic(
            'warning',
            $miss->reason === 'ambiguous' ? 'DISCOVERY_HTML_SCRIPT_AMBIGUOUS' : 'DISCOVERY_HTML_SCRIPT_UNRESOLVED',
            $message,
            $page,
        );
    }

    /**
     * The sources a published build output is compiled from, when no tsconfig says.
     *
     * `dist/esm.mjs` is not in the scan (the build directory is excluded), and
     * what its consumers call is `src/esm.ts`, compiled to it the way
     * `rootDir: src` and `outDir: dist` lay a library out. A bundler builds
     * many libraries without a tsconfig `outDir`, so this is the fallback
     * {@see self::withBuildOutputSources()} uses for an entry no declared
     * layout covers. Each extension the source may have is named; a twin no
     * file answers to publishes nothing.
     *
     * @return list<string>
     */
    private static function sourceTwins(string $path, string $directory): array
    {
        $inside = $directory === '' ? $path : substr($path, strlen($directory) + 1);
        if (preg_match('#^(?:dist|build|lib|out)/(.+?)(?:\.d)?\.[cm]?[jt]sx?$#', $inside, $match) !== 1) {
            return [];
        }
        $prefix = ($directory === '' ? '' : $directory . '/') . 'src/' . $match[1];

        return array_map(static fn(string $extension): string => $prefix . $extension, ['.ts', '.tsx', '.mts', '.cts', '.js', '.jsx', '.mjs', '.cjs']);
    }

    /**
     * Add, to each YAML unit, the PHP files below the directories it loads
     * every class from: Doctrine migration paths and tagged service resources.
     *
     * @param list<ProjectUnit> $units
     * @param list<DiscoveredFile> $files
     * @return list<ProjectUnit>
     */
    private static function withLoadedDirectoryEntryPoints(array $units, array $files): array
    {
        return array_map(static function (ProjectUnit $unit) use ($files): ProjectUnit {
            $directories = $unit->metadata['loaded_directories'] ?? [];
            if ($unit->kind !== 'yaml' || !is_array($directories) || $directories === []) {
                return $unit;
            }
            $exclusions = array_values(array_filter($unit->metadata['loaded_exclusions'] ?? [], is_string(...)));
            $paths = array_fill_keys($unit->metadata['entry_points'] ?? [], true);
            foreach ($files as $file) {
                if (!str_ends_with($file->relativePath, '.php') || self::excludedResource($file->relativePath, $exclusions)) {
                    continue;
                }
                foreach ($directories as $directory) {
                    if (is_string($directory) && str_starts_with($file->relativePath, $directory . '/')) {
                        $paths[$file->relativePath] = true;
                    }
                }
            }
            $paths = array_map(strval(...), array_keys($paths));
            sort($paths, SORT_STRING);

            return new ProjectUnit($unit->kind, $unit->configPath, $unit->contentHash, [
                ...$unit->metadata,
                'entry_points' => $paths,
            ]);
        }, $units);
    }

    /**
     * Add, to each unit, the discovered files its entry globs match.
     *
     * `migrations: ['src/migrations/*.ts']` names every migration without
     * naming one; the walk's file list is what the pattern is matched against.
     * `**` spans directories, `*` and `?` stay within one.
     *
     * @param list<ProjectUnit> $units
     * @param list<DiscoveredFile> $files
     * @return list<ProjectUnit>
     */
    private static function withGlobEntryPoints(array $units, array $files): array
    {
        return array_map(static function (ProjectUnit $unit) use ($files): ProjectUnit {
            $globs = array_values(array_filter($unit->metadata['entry_globs'] ?? [], is_string(...)));
            if ($globs === []) {
                return $unit;
            }
            $patterns = array_map(static function (string $glob): string {
                $regex = '';
                for ($at = 0, $length = strlen($glob); $at < $length; ++$at) {
                    if (substr($glob, $at, 3) === '**/') {
                        $regex .= '(?:.*/)?';
                        $at += 2;
                    } elseif (substr($glob, $at, 2) === '**') {
                        $regex .= '.*';
                        ++$at;
                    } elseif ($glob[$at] === '*') {
                        $regex .= '[^/]*';
                    } elseif ($glob[$at] === '?') {
                        $regex .= '[^/]';
                    } else {
                        $regex .= preg_quote($glob[$at], '#');
                    }
                }

                return '#^' . $regex . '$#';
            }, $globs);
            $paths = array_fill_keys($unit->metadata['entry_points'] ?? [], true);
            foreach ($files as $file) {
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $file->relativePath) === 1) {
                        $paths[$file->relativePath] = true;
                        break;
                    }
                }
            }
            $paths = array_map(strval(...), array_keys($paths));
            sort($paths, SORT_STRING);

            return new ProjectUnit($unit->kind, $unit->configPath, $unit->contentHash, [
                ...$unit->metadata,
                'entry_points' => $paths,
            ]);
        }, $units);
    }

    /**
     * Whether a path falls under a resource `exclude:`: the path itself, a
     * directory above it, or a glob matching it.
     *
     * @param list<string> $exclusions
     */
    private static function excludedResource(string $path, array $exclusions): bool
    {
        foreach ($exclusions as $exclusion) {
            if ($path === $exclusion || str_starts_with($path, rtrim($exclusion, '/') . '/') || fnmatch($exclusion, $path, FNM_PATHNAME)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add, to each YAML unit, the files its class names are declared in.
     *
     * Symfony and Doctrine wire classes up by name in YAML, and the container
     * instantiates them; nothing in PHP references them. Composer's PSR-4 map
     * turns a class name into its file, which is what entry points match on.
     *
     * @param list<ProjectUnit> $units
     * @return list<ProjectUnit>
     */
    private static function withClassNameEntryPoints(array $units): array
    {
        $prefixes = [];
        foreach ($units as $unit) {
            if ($unit->kind !== 'composer' || !is_array($unit->metadata['psr4'] ?? null)) {
                continue;
            }
            $directory = ManifestPaths::manifestDirectory($unit->configPath);
            foreach ($unit->metadata['psr4'] as $namespace => $paths) {
                foreach (is_array($paths) ? $paths : [$paths] as $path) {
                    if (is_string($namespace) && is_string($path) && $namespace !== '') {
                        $prefixes[] = [$namespace, ManifestPaths::joinPath($directory, trim($path, '/'))];
                    }
                }
            }
        }
        if ($prefixes === []) {
            return $units;
        }
        // Longest namespace first, as Composer resolves them.
        usort($prefixes, static fn(array $a, array $b): int => strlen($b[0]) <=> strlen($a[0]));

        return array_map(static function (ProjectUnit $unit) use ($prefixes): ProjectUnit {
            $classNames = $unit->metadata['class_names'] ?? [];
            if ($unit->kind !== 'yaml' || !is_array($classNames) || $classNames === []) {
                return $unit;
            }
            $paths = array_fill_keys($unit->metadata['entry_points'] ?? [], true);
            foreach ($classNames as $className) {
                foreach ($prefixes as [$namespace, $directory]) {
                    if (is_string($className) && str_starts_with($className, $namespace)) {
                        $rest = str_replace('\\', '/', substr($className, strlen($namespace)));
                        $paths[ManifestPaths::joinPath($directory, $rest . '.php')] = true;
                        break;
                    }
                }
            }
            $paths = array_keys($paths);
            sort($paths, SORT_STRING);

            return new ProjectUnit($unit->kind, $unit->configPath, $unit->contentHash, [
                ...$unit->metadata,
                'entry_points' => $paths,
            ]);
        }, $units);
    }

    /** Output extension => the source extensions the compiler emits it from. */
    private const BUILD_OUTPUT_SOURCES = [
        'js' => ['ts', 'tsx', 'js', 'jsx'],
        'mjs' => ['mts', 'mjs'],
        'cjs' => ['cts', 'cjs'],
    ];

    /**
     * Add, beside each package entry point inside a tsconfig's `outDir`, the
     * sources the compiler emits it from.
     *
     * A compiled package's `main`, `bin` and scripts name its build output,
     * which discovery never walks, so the name matched nothing and the source
     * behind it looked unreferenced. Without a `rootDir` the compiler infers
     * one, so the tsconfig's own directory and its `src/` are both offered.
     * Matching downstream is by exact path, so a candidate naming no file
     * costs nothing.
     *
     * @param list<ProjectUnit> $units
     * @return list<ProjectUnit>
     */
    private static function withBuildOutputSources(array $units): array
    {
        $layouts = [];
        foreach ($units as $unit) {
            $outDir = $unit->kind === 'typescript' ? ManifestPaths::layoutPath($unit->metadata['out_dir'] ?? null) : null;
            if ($outDir === null || $outDir === '') {
                continue;
            }
            $directory = ManifestPaths::manifestDirectory($unit->configPath);
            $rootDir = ManifestPaths::layoutPath($unit->metadata['root_dir'] ?? null);
            $layouts[] = [
                ManifestPaths::joinPath($directory, $outDir),
                $rootDir !== null ? [ManifestPaths::joinPath($directory, $rootDir)] : [$directory, ManifestPaths::joinPath($directory, 'src')],
            ];
        }

        return array_map(static function (ProjectUnit $unit) use ($layouts): ProjectUnit {
            if ($unit->kind !== 'node') {
                return $unit;
            }
            $metadata = $unit->metadata;
            $named = is_array($metadata['public_entry_points'] ?? null) ? $metadata['public_entry_points'] : null;
            foreach (['entry_points', 'public_entry_points'] as $key) {
                if (is_array($metadata[$key] ?? null) && $metadata[$key] !== []) {
                    $metadata[$key] = self::withSourcesOf($metadata[$key], $layouts);
                }
            }
            // A published entry the manifest names and no declared layout
            // covers: the bundler default.
            if ($named !== null && is_array($metadata['public_entry_points'] ?? null)) {
                $directory = ManifestPaths::manifestDirectory($unit->configPath);
                $published = array_fill_keys($metadata['public_entry_points'], true);
                foreach ($named as $entryPoint) {
                    if (!is_string($entryPoint) || self::underLayout($entryPoint, $layouts)) {
                        continue;
                    }
                    foreach (self::sourceTwins($entryPoint, $directory) as $twin) {
                        $published[$twin] = true;
                    }
                }
                $published = array_map(strval(...), array_keys($published));
                sort($published, SORT_STRING);
                $metadata['public_entry_points'] = $published;
            }

            return new ProjectUnit($unit->kind, $unit->configPath, $unit->contentHash, $metadata);
        }, $units);
    }

    /**
     * Whether a path lies in the `outDir` of a declared build layout.
     *
     * @param list<array{0: string, 1: list<string>}> $layouts
     */
    private static function underLayout(string $path, array $layouts): bool
    {
        foreach ($layouts as [$outDir]) {
            if (str_starts_with($path, $outDir . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Entry points with, beside each one in an `outDir`, the sources it is built from.
     *
     * @param list<mixed> $entryPoints
     * @param list<array{0: string, 1: list<string>}> $layouts
     * @return list<string>
     */
    private static function withSourcesOf(array $entryPoints, array $layouts): array
    {
        $paths = array_fill_keys(array_values(array_filter($entryPoints, is_string(...))), true);
        foreach ($entryPoints as $entryPoint) {
            foreach ($layouts as [$outDir, $sourceDirs]) {
                if (!is_string($entryPoint) || !str_starts_with($entryPoint, $outDir . '/')) {
                    continue;
                }
                $rest = substr($entryPoint, strlen($outDir) + 1);
                // A declaration the compiler emitted (`index.d.ts`) stands for
                // the source it describes, as the `.js` beside it does.
                if (preg_match('/^(.*)\.d\.(m|c)?ts$/', $rest, $declaration) === 1) {
                    $stem = $declaration[1];
                    $extension = ($declaration[2] ?? '') . 'js';
                } else {
                    $extension = strtolower(pathinfo($rest, PATHINFO_EXTENSION));
                    $stem = substr($rest, 0, -strlen($extension) - 1);
                }
                foreach (self::BUILD_OUTPUT_SOURCES[$extension] ?? [] as $sourceExtension) {
                    foreach ($sourceDirs as $sourceDir) {
                        $paths[ManifestPaths::joinPath($sourceDir, $stem . '.' . $sourceExtension)] = true;
                    }
                }
            }
        }
        $paths = array_map(strval(...), array_keys($paths));
        sort($paths, SORT_STRING);

        return $paths;
    }
}

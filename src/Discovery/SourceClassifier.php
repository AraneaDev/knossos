<?php

declare(strict_types=1);

namespace Knossos\Discovery;

use Knossos\Classification\ToolConfigModuleRule;
use Knossos\Scanner\Protocol\RelativePath;

/**
 * Says what a path is from its name, and for an extensionless file from its
 * shebang: a source language, a unit kind, a configuration file, a manifest,
 * a name a stable id can hold, or `tsc` output beside its source.
 *
 * Static and stateless, so discovery, the drift probe and the watcher's
 * fingerprint answer every path the same way.
 */
final class SourceClassifier
{
    /**
     * The language of each extension a scanner emits nodes for. The keys are
     * also the extensions a manifest's entry point may name
     * ({@see Manifest\ManifestPaths::entryPointExtensions()}), in the order
     * the readers' patterns try them.
     *
     * @var array<string, string>
     */
    public const LANGUAGE_BY_EXTENSION = [
        'php' => 'php',
        'js' => 'javascript', 'jsx' => 'javascript', 'mjs' => 'javascript', 'cjs' => 'javascript',
        'ts' => 'typescript', 'tsx' => 'typescript', 'mts' => 'typescript', 'cts' => 'typescript',
        'vue' => 'typescript', 'svelte' => 'typescript', 'astro' => 'typescript',
        'py' => 'python', 'pyi' => 'python',
        'rs' => 'rust',
    ];

    /** Bytes read when probing an extensionless file's shebang; one short line is enough. */
    private const SHEBANG_PROBE_BYTES = 256;

    /**
     * The extensions routed to any of these languages.
     *
     * @param list<string> $languages
     * @return list<string>
     */
    public static function extensionsFor(array $languages): array
    {
        return array_keys(array_filter(self::LANGUAGE_BY_EXTENSION, static fn(string $language): bool => in_array($language, $languages, true)));
    }

    /** Whether a path names a package or build manifest, which makes its directory a manifest root. */
    public static function isManifest(string $relativePath): bool
    {
        return UnitKind::tryFrom((string) self::unitKindFor($relativePath))?->isManifestRoot() === true;
    }

    /**
     * The files, less JavaScript `tsc` compiled beside its TypeScript source.
     *
     * Without an `outDir` the compiler writes `errors.js` next to `errors.ts`,
     * and every import resolves to the `.ts`, so the `.js` read as a module
     * nothing uses. It is build output: a same-named `.ts` or `.tsx` sits
     * beside it and it ends with the source-map comment the compiler writes.
     * Both are facts discovery already holds (the path list and the bytes it
     * hashed), so the decision changes only when they do.
     *
     * @param list<DiscoveredFile> $files
     * @return list<DiscoveredFile>
     */
    public static function withoutCompiledSiblings(array $files): array
    {
        $paths = [];
        foreach ($files as $file) {
            $paths[$file->relativePath] = true;
        }

        return array_values(array_filter(
            $files,
            static fn(DiscoveredFile $file): bool => !self::isCompiledSibling(
                $file->relativePath,
                $file->absolutePath,
                static fn(string $sibling): bool => isset($paths[$sibling]),
            ),
        ));
    }

    /**
     * The TypeScript sources `tsc` would compile a JavaScript path from, in
     * the order tried: `.ts` and `.tsx` for `.js` and `.jsx`, `.mts` for
     * `.mjs`, `.cts` for `.cjs`; none for any other path.
     *
     * @return list<string>
     */
    public static function compiledFrom(string $relativePath): array
    {
        if (preg_match('/^(.*)\.(js|jsx|mjs|cjs)$/', $relativePath, $stem) !== 1) {
            return [];
        }
        $sources = match ($stem[2]) {
            'mjs' => ['mts'],
            'cjs' => ['cts'],
            default => ['ts', 'tsx'],
        };

        return array_map(static fn(string $source): string => $stem[1] . '.' . $source, $sources);
    }

    /**
     * Whether a JavaScript file is `tsc` output beside its TypeScript source:
     * a same-named `.ts` or `.tsx` (`.mts` for `.mjs`, `.cts` for `.cjs`)
     * exists, and the file ends with the source-map comment the compiler
     * writes. Public because the drift probe must skip exactly what discovery
     * skips; each caller says how a sibling's existence is known.
     *
     * @param callable(string): bool $exists whether a project-relative path exists
     */
    public static function isCompiledSibling(string $relativePath, string $absolutePath, callable $exists): bool
    {
        $sibling = false;
        foreach (self::compiledFrom($relativePath) as $source) {
            $sibling = $sibling || $exists($source);
        }
        if (!$sibling) {
            return false;
        }
        $size = @filesize($absolutePath);
        $tail = $size === false ? false : @file_get_contents($absolutePath, false, null, max(0, $size - 512));

        return is_string($tail) && preg_match('~//# sourceMappingURL=\S+\s*$~', $tail) === 1;
    }

    /**
     * Whether a path is the project's own Knossos configuration, which the
     * walk reads whatever the ignores say about it.
     *
     * The exception exists because a project that ignores its own settings
     * file would be configuring a scan that never reads the configuration. It
     * is public for the same reason {@see self::languageFor()} is: the drift
     * oracles decide the same question about a path they were handed, and a
     * second copy of this list would let a probe count a path discovery
     * exempts, reporting drift no rescan can clear.
     */
    public static function isConfigurationFile(string $relativePath): bool
    {
        return in_array(strtolower(basename($relativePath)), ['knossos.json', 'knossos.jsonc'], true);
    }

    /**
     * The language a file belongs to, or null when it is not source.
     *
     * Extension first, then a shebang for extensionless files. Executable entry
     * points routinely have no extension — `artisan`, `bin/console`, this project's
     * own `workers/php/bin/worker` — and skipping them makes whatever they invoke
     * look unreferenced, so dead-code detection reports a live entry point as a
     * deletion candidate.
     *
     * Public because the drift oracles have to answer the same question this
     * loop answers, about a path they were handed rather than one they walked
     * to: whether a file appearing beside the graph is source the scanner would
     * have tracked, or a README the graph was never going to hold. Two
     * definitions of "source" would let a probe report drift a rescan cannot
     * clear.
     *
     * @param string|null $absolutePath needed only to read a shebang; omit and
     *        extensionless files are simply not classified
     */
    public static function languageFor(string $relativePath, ?string $absolutePath = null): ?string
    {
        return self::classify($relativePath, $absolutePath)['language'];
    }

    /**
     * {@see self::languageFor()} and {@see self::unitKindFor()} at once, with
     * an extensionless file's shebang read once for both: what the walk and
     * the drift probe ask of every path.
     *
     * @param string|null $absolutePath needed only to read a shebang
     * @return array{language: ?string, unitKind: ?string}
     */
    public static function classify(string $relativePath, ?string $absolutePath = null): array
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        $shebang = $extension === '' && $absolutePath !== null ? self::shebang($absolutePath) : null;

        return [
            'language' => $extension === '' ? ($shebang === null ? null : self::languageFromShebang($shebang)) : (self::LANGUAGE_BY_EXTENSION[$extension] ?? null),
            'unitKind' => self::unitKindByName($relativePath) ?? ($shebang !== null && self::isShellShebang($shebang) ? UnitKind::Shell->value : null),
        ];
    }

    /**
     * The language a script's shebang line names, or null.
     *
     * Only the first line is read, and only for a file with no extension, so the
     * cost is one bounded read of the handful of extensionless files in a tree
     * (LICENSE, Dockerfile, Makefile) rather than of every file.
     */
    private static function languageFromShebang(string $first): ?string
    {
        // Matches both `#!/usr/bin/php` and `#!/usr/bin/env php`, and tolerates a
        // version suffix such as `php8.3`. Anchored to a word boundary so a path
        // like /opt/phpstorm/bin/foo cannot be read as a PHP script.
        return match (true) {
            preg_match('#\b(php)[0-9.]*\b#i', $first) === 1 => 'php',
            preg_match('#\b(node|nodejs|bun|deno)[0-9.]*\b#i', $first) === 1 => 'javascript',
            preg_match('#\b(python)[0-9.]*\b#i', $first) === 1 => 'python',
            default => null,
        };
    }

    /** The first line of a file when it is a shebang (`#!...`), or null. */
    private static function shebang(string $absolutePath): ?string
    {
        $handle = @fopen($absolutePath, 'rb');
        if (!is_resource($handle)) {
            return null;
        }
        try {
            $first = (string) fgets($handle, self::SHEBANG_PROBE_BYTES);
        } finally {
            fclose($handle);
        }

        return str_starts_with($first, '#!') ? $first : null;
    }

    /**
     * Whether an extensionless file's shebang runs it with sh, bash, dash or
     * zsh (`#!/bin/sh`, `#!/usr/bin/env bash`), so it is a shell script like a
     * `.sh` file. The interpreter is the last path segment of the program or
     * the first word after `env`, in any case.
     */
    private static function isShellShebang(string $first): bool
    {
        return preg_match('#^\#!\s*(?:\S*/)?(?:env\s+(?:-\S+\s+)*)?(?:sh|bash|dash|zsh)(?:\s|$)#i', $first) === 1;
    }

    /**
     * Which manifest kind a filename is, or null when it is not one.
     *
     * Public because it is half of what "an input this scanner reads" means,
     * and the drift oracles need the same half: a path that is a unit here but
     * not a language file has no `files` row, and a probe that asked only
     * {@see self::languageFor()} treated editing composer.json as nothing at
     * all. {@see \Knossos\Query\Drift\ScannedPaths} asks both.
     *
     * @param string|null $absolutePath needed only to read the shebang of an
     *        extensionless shell script; omit and such a file is no unit
     */
    public static function unitKindFor(string $relativePath, ?string $absolutePath = null): ?string
    {
        return self::classify($relativePath, $absolutePath)['unitKind'];
    }

    /** The unit kind a path's name alone says, the shebang of an extensionless shell script aside. */
    private static function unitKindByName(string $relativePath): ?string
    {
        $basename = strtolower(basename($relativePath));
        if ($basename === '.gitignore') {
            return UnitKind::Gitignore->value;
        }
        // A README writes down the command that runs a one-off script.
        if (preg_match('/^readme(?:\.[a-z]+)?\.md$/', $basename) === 1) {
            return UnitKind::Readme->value;
        }
        // A VitePress or VuePress page compiles to a Vue component, and its
        // `<script setup>` is the only thing that reaches the components it
        // imports. Read as a unit for those imports, as an HTML page is;
        // whether the page is in a site at all is decided once every file
        // is known ({@see EntryPointResolver}).
        if (str_ends_with($basename, '.md')) {
            return UnitKind::Markdown->value;
        }
        // A shell script starts the programs it runs, which nothing imports.
        if (str_ends_with($basename, '.sh') || str_ends_with($basename, '.bash')) {
            return UnitKind::Shell->value;
        }
        // A container's CMD and ENTRYPOINT start a script nothing imports.
        if ($basename === 'dockerfile' || str_starts_with($basename, 'dockerfile.') || str_ends_with($basename, '.dockerfile')) {
            return UnitKind::Dockerfile->value;
        }
        if ($basename === 'composer.json') {
            return UnitKind::Composer->value;
        }
        if ($basename === 'knossos.json' || $basename === 'knossos.jsonc') {
            return UnitKind::Knossos->value;
        }
        if ($basename === 'package.json') {
            return UnitKind::Node->value;
        }
        // An Azure Functions binding manifest. The basename is generic enough
        // that another tool could own it, so the reader below asks for the
        // `scriptFile` key rather than assuming the shape; a function.json that
        // is something else contributes no entry points and costs one unit.
        if ($basename === 'function.json') {
            return UnitKind::AzureFunction->value;
        }
        // An HTML shell is the only thing that reaches a single-page
        // application's entry module, and nothing in the project imports it.
        // Read as a unit rather than as a file: it contributes entry points,
        // not nodes, and no scanner parses HTML.
        if (str_ends_with($basename, '.html') || str_ends_with($basename, '.htm')) {
            return UnitKind::Html->value;
        }
        // Compose files, CI workflows and deployment manifests all name source
        // files by path. Read for those paths only; no YAML parser is involved
        // and none is needed, for the same reason the Composer script reader
        // tokenises shell commands crudely.
        if (str_ends_with($basename, '.yml') || str_ends_with($basename, '.yaml')) {
            return UnitKind::Yaml->value;
        }
        // NEON, PHPStan's config format, is YAML's shape: the rules and
        // extensions it registers are class names nothing in PHP references.
        if (str_ends_with($basename, '.neon') || str_ends_with($basename, '.neon.dist')) {
            return UnitKind::Yaml->value;
        }
        // A Claude Code plugin runs its hooks and MCP servers from commands in
        // these files, which name the scripts by path; nothing imports them.
        $normalized = str_replace('\\', '/', $relativePath);
        if (in_array($basename, ['hooks.json', '.mcp.json', 'plugin.json'], true)
            || preg_match('#(?:^|/)\.claude/settings(?:\.[a-z]+)?\.json$#', strtolower($normalized)) === 1) {
            return UnitKind::AgentConfig->value;
        }
        if (in_array($basename, ['knip.json', 'knip.jsonc', '.knip.json', '.knip.jsonc'], true)) {
            return UnitKind::Knip->value;
        }
        if ($basename === 'pyproject.toml') {
            return UnitKind::Python->value;
        }
        // requirements.txt and its per-environment siblings (requirements-dev.txt,
        // requirements-prod.txt) are the legacy Python dependency manifest — the
        // pyproject.toml of projects that never migrated to PEP 621. Recorded as
        // their own unit so an edit invalidates the analyzer cache the way a
        // composer.json edit does.
        if ($basename === 'requirements.txt' || (str_starts_with($basename, 'requirements-') && str_ends_with($basename, '.txt'))) {
            return UnitKind::Requirements->value;
        }
        // A jsconfig.json is a tsconfig for JavaScript: the TypeScript worker reads it the same way.
        foreach (['tsconfig', 'jsconfig'] as $config) {
            if ($basename === $config . '.json' || (str_starts_with($basename, $config . '.') && str_ends_with($basename, '.json'))) {
                return UnitKind::Typescript->value;
            }
        }
        if ($basename === 'cargo.toml') {
            return UnitKind::Cargo->value;
        }

        // A tool config is read TWICE: as an ordinary source module by the
        // language worker, and as a unit here for the files it tells its tool
        // to load. The two `if` blocks in discover() are independent, so one
        // file may be both — which is why this needs no scanner change.
        if (ToolConfigModuleRule::isToolConfigPath($relativePath) || $basename === 'cypress.json') {
            return UnitKind::ToolConfig->value;
        }

        return null;
    }

    /**
     * Whether a project-relative path can be carried through ids, protocol
     * messages and JSON results. Shared with the drift oracles so a name the
     * walk skips is never reported as an addition; see
     * {@see RelativePath::isSupported()}.
     */
    public static function isSupportedPath(string $relative): bool
    {
        return RelativePath::isSupported($relative);
    }
}

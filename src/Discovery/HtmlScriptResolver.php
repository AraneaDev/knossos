<?php

declare(strict_types=1);

namespace Knossos\Discovery;

use Knossos\Discovery\Manifest\ManifestPaths;

/**
 * Which scanned file a script URL in an HTML page names, if exactly one does.
 *
 * A URL is a path on the web server, not on disk, and the page cannot say
 * how the server maps one to the other. A wrong answer would make a file an
 * entry point and hide real dead code, which is worse than the false
 * positive it removes, so this never guesses: a reference resolves only when
 * exactly one scanned file answers to it, and otherwise comes back as a
 * {@see HtmlScriptMiss} for the caller to report.
 */
final class HtmlScriptResolver
{
    /**
     * The one scanned file `$url` names from `$page`, a miss saying why
     * there is not one, or null for a reference that is not a local script.
     *
     * Ignored silently: remote URLs (`https:`, `//host`), any other scheme
     * (`data:`, `blob:`), bare package specifiers (`react`), and targets
     * whose extension no scanner reads (`.css`, `.json`). The query and
     * fragment are dropped and percent-encoding decoded first.
     *
     * A relative URL resolves from the page's folder and nowhere else. A
     * root URL resolves through the longest `$webRoots` prefix it starts
     * with, and never falls back when one matches. Without a mapping it is
     * looked for in the page's folder with its first segment dropped, which
     * is how a server that mounts that folder at `/static/` names its files,
     * and as the full path under the page's folder, each parent folder up to
     * the project root, and the `public/` and `static/` folders in each of
     * them, where bundlers keep the files they serve at the web root. The
     * first segment is dropped beside the page only: above it, a file that
     * merely shares the rest of the path would be a guess.
     *
     * `tsc` output that discovery leaves out beside its source
     * (`main.js` beside `main.ts`) stands for that source. A URL's `..` is
     * applied first, so it cannot climb out of a mapped folder.
     *
     * @param array<string, true> $files scanned project-relative paths
     * @param array<string, string> $webRoots URL prefix (`/static/`) => project folder (`web/public/`)
     * @param array<string, list<string>> $compiled `tsc` output discovery left out => the sources it is built from
     */
    public static function resolve(string $page, string $url, array $files, array $webRoots, array $compiled = []): string|HtmlScriptMiss|null
    {
        $path = self::localPath($url);
        if ($path === null) {
            return null;
        }
        $pageFolder = ManifestPaths::manifestDirectory($page);
        $root = str_starts_with($path, '/');
        // `..` is applied before anything else, so `/static/../x.js` is the
        // root URL `/x.js` and no mapped folder can be climbed out of.
        $relative = self::normalise($root ? $path : $pageFolder . '/' . $path);
        if ($relative === null) {
            return new HtmlScriptMiss('outside');
        }
        if (!$root) {
            return self::chosen(self::scanned($relative, $files, $compiled), 'none', [$relative]);
        }
        $mapped = self::mapped('/' . $relative, $webRoots);
        if ($mapped === null) {
            return new HtmlScriptMiss('outside');
        }
        if ($mapped !== false) {
            return self::chosen(self::scanned($mapped, $files, $compiled), 'mapped-missing', [$mapped]);
        }
        $candidates = self::heuristicCandidates($relative, $pageFolder);
        $matches = [];
        foreach ($candidates as $candidate) {
            foreach (self::scanned($candidate, $files, $compiled) as $match) {
                $matches[$match] = true;
            }
        }

        return self::chosen(array_map(strval(...), array_keys($matches)), 'none', $candidates);
    }

    /**
     * The scanned files a candidate path stands for: itself, or, for `tsc`
     * output discovery dropped beside its source, that source.
     *
     * @param array<string, true> $files
     * @param array<string, list<string>> $compiled
     * @return list<string>
     */
    private static function scanned(string $candidate, array $files, array $compiled): array
    {
        if (isset($files[$candidate])) {
            return [$candidate];
        }

        return array_values(array_filter($compiled[$candidate] ?? [], static fn(string $source): bool => isset($files[$source])));
    }

    /**
     * The one match, or a miss: ambiguous for several, `$reason` for none.
     *
     * @param list<string> $matches
     * @param list<string> $tried
     */
    private static function chosen(array $matches, string $reason, array $tried): string|HtmlScriptMiss
    {
        if (count($matches) === 1) {
            return $matches[0];
        }
        sort($matches, SORT_STRING);

        return $matches === [] ? new HtmlScriptMiss($reason, $tried) : new HtmlScriptMiss('ambiguous', $matches);
    }

    /**
     * The URL's path, decoded, when it names a local script; null otherwise.
     *
     * A root path keeps its leading slash. Anything without `/`, `./` or
     * `../` in front and without a file extension is a package name.
     */
    private static function localPath(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1) {
            return null;
        }
        $path = rawurldecode((string) preg_replace('/[?#].*$/s', '', $url));
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (str_ends_with($path, '/') || !in_array($extension, ManifestPaths::entryPointExtensions(), true)) {
            return null;
        }

        return $path;
    }

    /**
     * The project path a configured web root maps a root URL to: false when
     * no prefix matches, null when the mapped path leaves the project.
     *
     * @param array<string, string> $webRoots
     */
    private static function mapped(string $path, array $webRoots): string|false|null
    {
        $best = null;
        foreach ($webRoots as $prefix => $folder) {
            if (str_starts_with($path, $prefix) && ($best === null || strlen($prefix) > strlen($best))) {
                $best = $prefix;
            }
        }
        if ($best === null) {
            return false;
        }

        return self::normalise($webRoots[$best] . '/' . substr($path, strlen($best)));
    }

    /**
     * The paths a root URL could name without a mapping, distinct and in the order tried.
     *
     * @return list<string>
     */
    private static function heuristicCandidates(string $relative, string $pageFolder): array
    {
        $candidates = [ManifestPaths::joinPath($pageFolder, $relative) => true];
        $slash = strpos($relative, '/');
        if ($slash !== false) {
            $candidates[ManifestPaths::joinPath($pageFolder, substr($relative, $slash + 1))] = true;
        }
        for ($folder = $pageFolder; ; $folder = ManifestPaths::manifestDirectory($folder)) {
            foreach ([$folder, ManifestPaths::joinPath($folder, 'public'), ManifestPaths::joinPath($folder, 'static')] as $root) {
                $candidates[ManifestPaths::joinPath($root, $relative)] = true;
            }
            if ($folder === '') {
                break;
            }
        }

        return array_map(strval(...), array_keys($candidates));
    }

    /**
     * A path with its `.` and `..` segments applied and no leading or
     * doubled slash, or null when it climbs above the project root.
     */
    private static function normalise(string $path): ?string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment !== '..') {
                $segments[] = $segment;
            } elseif (array_pop($segments) === null) {
                return null;
            }
        }

        return $segments === [] ? null : implode('/', $segments);
    }
}

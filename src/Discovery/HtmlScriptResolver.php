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
     * looked for under the page's folder, each parent folder up to the
     * project root, and the `public/` and `static/` folders in each of them,
     * where bundlers keep the files they serve at the web root. At each, the
     * full path is tried and the path with its first segment dropped, which
     * is how a server that mounts a folder at `/static/` names its files.
     *
     * @param array<string, true> $files scanned project-relative paths
     * @param array<string, string> $webRoots URL prefix (`/static/`) => project folder (`web/public/`)
     */
    public static function resolve(string $page, string $url, array $files, array $webRoots): string|HtmlScriptMiss|null
    {
        $path = self::localPath($url);
        if ($path === null) {
            return null;
        }
        $pageFolder = ManifestPaths::manifestDirectory($page);
        if (!str_starts_with($path, '/')) {
            $candidate = self::normalise($pageFolder . '/' . $path);
            if ($candidate === null) {
                return new HtmlScriptMiss('outside');
            }

            return isset($files[$candidate]) ? $candidate : new HtmlScriptMiss('none', [$candidate]);
        }
        $mapped = self::mapped($path, $webRoots);
        if ($mapped !== false) {
            if ($mapped === null) {
                return new HtmlScriptMiss('outside');
            }

            return isset($files[$mapped]) ? $mapped : new HtmlScriptMiss('mapped-missing', [$mapped]);
        }
        $relative = self::normalise($path);
        if ($relative === null) {
            return new HtmlScriptMiss('outside');
        }
        $candidates = self::heuristicCandidates($relative, $pageFolder);
        $matches = array_values(array_filter($candidates, static fn(string $candidate): bool => isset($files[$candidate])));
        if (count($matches) === 1) {
            return $matches[0];
        }
        sort($matches, SORT_STRING);

        return $matches === [] ? new HtmlScriptMiss('none', $candidates) : new HtmlScriptMiss('ambiguous', $matches);
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
        if (str_ends_with($path, '/') || !in_array($extension, ManifestPaths::ENTRY_POINT_EXTENSIONS, true)) {
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
        $roots = [];
        for ($folder = $pageFolder; ; $folder = ManifestPaths::manifestDirectory($folder)) {
            array_push($roots, $folder, ManifestPaths::joinPath($folder, 'public'), ManifestPaths::joinPath($folder, 'static'));
            if ($folder === '') {
                break;
            }
        }
        $slash = strpos($relative, '/');
        $readings = $slash === false ? [$relative] : [$relative, substr($relative, $slash + 1)];
        $candidates = [];
        foreach ($roots as $root) {
            foreach ($readings as $reading) {
                $candidates[ManifestPaths::joinPath($root, $reading)] = true;
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

<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

use Knossos\Filesystem\RelativePathRule;

/**
 * Reads a shell script for the programs it runs, which nothing imports.
 */
final class ShellManifest implements ManifestReader
{
    /** The programs a command runs through before the one it names: `php bin/tool`, `exec bin/serve`. */
    private const RUNNERS = [
        'bash', 'sh', 'zsh', 'dash', 'ksh', 'php', 'python', 'python3', 'node', 'ruby', 'perl', 'tsx', 'ts-node',
        'exec', 'nohup', 'env', 'time', 'command', 'then', 'do', 'else', 'if', 'while', '!', 'sudo',
        'source', '.', 'xargs',
    ];

    /**
     * The entry points a shell script runs, and every path it launches with
     * the first line naming it (`launches`), which become its `launches` edges.
     */
    public function read(string $relative, string $absolute, string $contents): array
    {
        $entryPoints = [];
        $launches = [];
        foreach (self::anchoredLines($contents, $relative) as $number => [$line, $anchors]) {
            $named = YamlPaths::entryPoints($line, $relative, $anchors);
            foreach ($named as $path) {
                $entryPoints[$path] = true;
            }
            foreach ([...$named, ...self::commandPaths($line, $relative, $anchors)] as $path) {
                $launches[$path] ??= $number;
            }
        }
        ksort($launches, SORT_STRING);

        return [
            'entry_points' => array_keys($entryPoints),
            'launches' => array_map(static fn(string $path, int $line): array => ['path' => $path, 'line' => $line], array_keys($launches), $launches),
        ];
    }

    /**
     * Each line of the script with its comment stripped, by line number, and
     * the directories the `cd` commands reaching it change into, as a YAML
     * file's paths are read.
     *
     * `cd server && npx tsx src/scripts/reset.ts` names a path relative to the
     * directory the line changed into, and a `cd` on a line of its own moves
     * the lines after it until its `case` branch or block ends. Each line is
     * read from the project root, the script's own directory and whatever
     * `cd` reaches it; a path no file answers to under any of them names
     * nothing.
     *
     * The body of a heredoc (`<<EOF`, `<<-EOF`, a quoted delimiter) is text
     * handed to the command on the line opening it, so its lines are left out
     * until the delimiter closes it.
     *
     * @return array<int, array{0: string, 1: list<string>}>
     */
    private static function anchoredLines(string $contents, string $configPath): array
    {
        $directory = ManifestPaths::manifestDirectory($configPath);
        $lines = [];
        $current = [];
        /** @var list<array{0: string, 1: bool}> $heredocs delimiters still open, each with whether leading tabs are stripped */
        $heredocs = [];
        foreach (explode("\n", $contents) as $index => $line) {
            if ($heredocs !== []) {
                [$delimiter, $tabs] = $heredocs[0];
                $closing = rtrim($line, "\r");
                if (($tabs ? ltrim($closing, "\t") : $closing) === $delimiter) {
                    array_shift($heredocs);
                }
                continue;
            }
            $line = preg_replace('/(?:^|\s)#.*$/', '', $line) ?? $line;
            $heredocs = self::heredocDelimiters($line);
            $inline = self::shellCdTargets($line, $directory);
            $standalone = preg_match('/^\s*cd\s/', $line) === 1 && preg_match('/&&|;|\|/', $line) !== 1;
            $lines[$index + 1] = [$line, [...$current, ...$inline]];
            if ($standalone) {
                $current = $inline;
            } elseif (preg_match('/;;|^\s*(?:(?:esac|fi|done)\b|\})/', $line) === 1) {
                $current = [];
            }
        }

        return $lines;
    }

    /**
     * The heredocs one line opens, in order: each delimiter, and whether its
     * `<<-` form lets the closing line be indented with tabs. A here-string
     * (`<<<`) opens none.
     *
     * @return list<array{0: string, 1: bool}>
     */
    private static function heredocDelimiters(string $line): array
    {
        preg_match_all('/(?<!<)<<(?!<)(-?)\s*([\'"]?)([A-Za-z_][A-Za-z0-9_]*)\2/', $line, $matches, PREG_SET_ORDER);

        return array_map(static fn(array $match): array => [$match[3], $match[1] === '-'], $matches);
    }

    /**
     * The paths one line runs as a command, whatever their extension: the
     * command word itself when it is a path (`./tools/check`), or the script
     * an interpreter or `exec` is handed (`php bin/tool`). A word holding a
     * variable, a glob or a home directory names nothing literal, so it is
     * left out. Each path is read from the script's own directory, the
     * project root and every `cd` anchor of the line.
     *
     * @param list<string> $anchors
     * @return list<string>
     */
    private static function commandPaths(string $line, string $configPath, array $anchors): array
    {
        $paths = [];
        foreach (preg_split('/&&|\|\||[;|()`]|\$\(/', $line) ?: [] as $command) {
            $words = preg_split('/\s+/', trim($command), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $word = null;
            $runner = false;
            foreach ($words as $candidate) {
                $candidate = trim($candidate, '\'"');
                if (in_array($candidate, self::RUNNERS, true)) {
                    $runner = true;
                    continue;
                }
                // `FOO=1 bin/serve` sets a variable for the command; `python3 -u x.py` passes a flag to the runner.
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $candidate) === 1 || ($runner && str_starts_with($candidate, '-'))) {
                    continue;
                }
                $word = $candidate;
                break;
            }
            if ($word === null || !str_contains($word, '/') || preg_match('/[$*?~\\\\]/', $word) === 1 || str_starts_with($word, '/')) {
                continue;
            }
            $word = RelativePathRule::withoutDotSlash($word);
            foreach (array_unique([ManifestPaths::manifestDirectory($configPath), '', ...$anchors]) as $anchor) {
                $path = ManifestPaths::withinProject(ManifestPaths::joinPath($anchor, $word));
                if ($path !== null && $path !== '' && !str_starts_with($path, 'node_modules/') && !str_starts_with($path, 'vendor/')) {
                    $paths[$path] = true;
                }
            }
        }

        return array_keys($paths);
    }

    /**
     * The project-relative directories the `cd` commands on one line change into.
     *
     * @return list<string>
     */
    private static function shellCdTargets(string $line, string $directory): array
    {
        preg_match_all('#\bcd\s+[\'"]?([A-Za-z0-9_][A-Za-z0-9_./-]*)#', $line, $matches);
        $anchors = [];
        foreach ($matches[1] as $target) {
            $target = rtrim(RelativePathRule::withoutDotSlash($target), '/');
            if ($target === '' || !RelativePathRule::isContained($target)) {
                continue;
            }
            $anchors[$target] = true;
            if ($directory !== '') {
                $anchors[$directory . '/' . $target] = true;
            }
        }

        return array_keys($anchors);
    }
}

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

    /** The `xargs` options that take their value as the next word. */
    private const XARGS_VALUE_OPTIONS = ['-n', '-P', '-I', '-L', '-s', '-d', '-E', '-a'];

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
     * The body of a heredoc (`<<EOF`, `<<-EOF`, a quoted or backslashed
     * delimiter) is text handed to the command on the line opening it, so its
     * lines are left out up to the line closing it. A heredoc that never
     * closes is read as no heredoc, so a misread `<<` hides nothing.
     *
     * @return array<int, array{0: string, 1: list<string>}>
     */
    private static function anchoredLines(string $contents, string $configPath): array
    {
        $directory = ManifestPaths::manifestDirectory($configPath);
        $raw = explode("\n", $contents);
        $lines = [];
        $current = [];
        for ($index = 0, $count = count($raw); $index < $count; ++$index) {
            $line = preg_replace('/(?:^|\s)#.*$/', '', $raw[$index]) ?? $raw[$index];
            $inline = self::shellCdTargets($line, $directory);
            $standalone = preg_match('/^\s*cd\s/', $line) === 1 && preg_match('/&&|;|\|/', $line) !== 1;
            $lines[$index + 1] = [$line, [...$current, ...$inline]];
            if ($standalone) {
                $current = $inline;
            } elseif (preg_match('/;;|^\s*(?:(?:esac|fi|done)\b|\})/', $line) === 1) {
                $current = [];
            }
            $index = self::heredocEnd($raw, $index, self::heredocDelimiters($line)) ?? $index;
        }

        return $lines;
    }

    /**
     * The index of the line closing the last of the heredocs opened on line
     * `$opener`, each closed in turn; null when there are none or one never closes.
     *
     * @param list<string> $raw
     * @param list<array{0: string, 1: bool}> $heredocs
     */
    private static function heredocEnd(array $raw, int $opener, array $heredocs): ?int
    {
        $at = $opener;
        foreach ($heredocs as [$delimiter, $tabs]) {
            do {
                if (++$at >= count($raw)) {
                    return null;
                }
                $closing = rtrim($raw[$at], "\r");
            } while (($tabs ? ltrim($closing, "\t") : $closing) !== $delimiter);
        }

        return $heredocs === [] ? null : $at;
    }

    /**
     * The heredocs one line opens, in order: each delimiter, and whether its
     * `<<-` form lets the closing line be indented with tabs. The delimiter
     * is the whole word after `<<`, unquoted. A here-string (`<<<`), a `<<`
     * inside quotes and a shift inside `$(( ))` or `(( ))` open none.
     *
     * @return list<array{0: string, 1: bool}>
     */
    private static function heredocDelimiters(string $line): array
    {
        $heredocs = [];
        $quote = null;
        $arithmetic = 0;
        for ($at = 0, $length = strlen($line); $at < $length; ++$at) {
            $char = $line[$at];
            if ($quote !== null) {
                if ($char === '\\' && $quote === '"') {
                    ++$at;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '\\') {
                ++$at;
            } elseif ($char === '\'' || $char === '"') {
                $quote = $char;
            } elseif ($char === '(' && ($line[$at + 1] ?? '') === '(') {
                ++$arithmetic;
                ++$at;
            } elseif ($char === ')' && $arithmetic > 0 && ($line[$at + 1] ?? '') === ')') {
                --$arithmetic;
                ++$at;
            } elseif ($char === '<' && $arithmetic === 0 && substr($line, $at, 3) === '<<<') {
                $at += 2;
            } elseif ($char === '<' && $arithmetic === 0 && ($line[$at + 1] ?? '') === '<'
                && preg_match('/\G<<(-?)[ \t]*(?:\'([^\']+)\'|"([^"]+)"|\\\\?([^\s;&|<>()\'"\\\\]+))(?=[\s;&|<>)]|$)/', $line, $match, 0, $at) === 1) {
                $heredocs[] = [$match[2] . ($match[3] ?? '') . ($match[4] ?? ''), $match[1] === '-'];
                $at += strlen($match[0]) - 1;
            }
        }

        return $heredocs;
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
            $value = false;
            foreach ($words as $candidate) {
                $candidate = trim($candidate, '\'"');
                if ($value) {
                    $value = false;
                    continue;
                }
                if (in_array($candidate, self::RUNNERS, true)) {
                    $runner = $candidate;
                    continue;
                }
                // `xargs -n 1 php bin/x`: the option's value is no command.
                if ($runner === 'xargs' && in_array($candidate, self::XARGS_VALUE_OPTIONS, true)) {
                    $value = true;
                    continue;
                }
                // `FOO=1 bin/serve` sets a variable for the command; `python3 -u x.py` passes a flag to the runner.
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $candidate) === 1 || ($runner !== false && str_starts_with($candidate, '-'))) {
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

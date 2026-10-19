<?php

declare(strict_types=1);

/*
 * The files each linter in tools/quality reads, derived from `git ls-files`.
 *
 *   php tools/lint-files.php --write [--repo=DIR] [--list=FILE]
 *   php tools/lint-files.php --check [--repo=DIR] [--list=FILE]
 *   php tools/lint-files.php --print=KIND [--list=FILE]
 *   php tools/lint-files.php --run=KIND [--list=FILE] -- COMMAND [ARG...]
 *   php tools/lint-files.php --each=KIND [--list=FILE] -- COMMAND [ARG...]
 *
 * The lists used to be written out by hand in tools/quality, composer.json,
 * package.json, .php-cs-fixer.dist.php and phpstan.neon, and every one of them
 * had fallen behind: shell scripts, PHP tools and JavaScript modules were added
 * that no linter ever read, and nothing said so. A file is now in a list
 * because git tracks it and it is that kind of file, by extension or by its
 * `#!` line, so a new script is linted from the commit that adds it.
 *
 * The quality image carries no .git, so the lists are committed in
 * tools/lint-files.txt (`--write`) and the linters read that file. `--check`
 * derives the lists again from a checkout and refuses when the committed file
 * differs, which is what keeps the committed copy honest.
 *
 * `--run` hands every file of KIND to one COMMAND; `--each` runs COMMAND once
 * per file. Both refuse an empty list and a listed file that is not in the
 * tree, because a linter given no files, or fewer than the list says, passes
 * without having read what it was meant to.
 *
 * KIND is `shell`, `php` or `js`. The list file lives in the tools/ directory
 * of the tree it describes, and its paths are relative to that tree's root.
 */

$root = dirname(__DIR__);

/** The kinds a list holds, in the order the file is written. */
const LINT_KINDS = ['shell', 'php', 'js'];

/**
 * Print a refusal and stop.
 */
function lintFilesRefuse(string $message, int $status = 1): never
{
    fwrite(STDERR, "lint-files: {$message}\n");
    exit($status);
}

/**
 * The kinds of linting a tracked file gets, from its path and its first line.
 *
 * tests/Fixtures holds input for the scanners, some of it invalid on purpose,
 * so it is linted by none of them. The one exception is tests/Fixtures/workers,
 * which holds a worker the suite runs as code. tools/claude-code-api holds the
 * declarations the Claude Code CLI wrote, which are its output, not ours.
 *
 * @return list<string>
 */
function lintKindsOf(string $path, string $firstLine): array
{
    if (str_starts_with($path, 'tests/Fixtures/') && !str_starts_with($path, 'tests/Fixtures/workers/')) {
        return [];
    }
    // Written by the Claude Code CLI (tools/claude-code-types), not by hand.
    if (str_starts_with($path, 'tools/claude-code-api/')) {
        return [];
    }
    if (str_ends_with($path, '.sh') || preg_match('~^#!\s*(?:/usr)?/bin/(?:env\s+)?(?:sh|bash|dash)\b~', $firstLine) === 1) {
        return ['shell'];
    }
    if (str_ends_with($path, '.php') || preg_match('~^#!\s*(?:/usr)?/bin/(?:env\s+)?php\b~', $firstLine) === 1) {
        return ['php'];
    }
    if (preg_match('/\.(?:js|mjs|cjs|ts|mts|cts|tsx)$/', $path) === 1) {
        return ['js'];
    }

    return [];
}

/**
 * Derive every list from the files git tracks in `$repo`.
 *
 * Symbolic links and submodules are left out: neither is a file a linter
 * reads. A tracked file missing from the working tree is left out too, since
 * there is nothing there to lint.
 *
 * @return array<string, list<string>>
 */
function deriveLintLists(string $repo): array
{
    $process = proc_open(
        ['git', '-c', 'safe.directory=' . $repo, '-C', $repo, 'ls-files', '--stage', '-z'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        lintFilesRefuse('cannot start git');
    }
    $listing = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        lintFilesRefuse("git ls-files failed in {$repo}: " . trim($errors));
    }
    $lists = array_fill_keys(LINT_KINDS, []);
    foreach (explode("\0", $listing) as $entry) {
        if ($entry === '' || !str_contains($entry, "\t")) {
            continue;
        }
        [$meta, $path] = explode("\t", $entry, 2);
        $mode = strtok($meta, ' ');
        if ($mode === '120000' || $mode === '160000' || !is_file($repo . '/' . $path)) {
            continue;
        }
        $handle = fopen($repo . '/' . $path, 'rb');
        $firstLine = $handle === false ? '' : (string) fgets($handle, 256);
        if ($handle !== false) {
            fclose($handle);
        }
        foreach (lintKindsOf($path, $firstLine) as $kind) {
            $lists[$kind][] = $path;
        }
    }
    foreach ($lists as $kind => $files) {
        sort($files, SORT_STRING);
        $lists[$kind] = $files;
    }

    return $lists;
}

/**
 * The committed list file's text for `$lists`.
 *
 * @param array<string, list<string>> $lists
 */
function renderLintLists(array $lists): string
{
    $text = "# The files each linter in tools/quality reads, derived from `git ls-files`\n"
        . "# by tools/lint-files.php. Regenerate it with `php tools/lint-files.php --write`;\n"
        . "# the static lane refuses a copy that differs from what git tracks.\n";
    foreach (LINT_KINDS as $kind) {
        foreach ($lists[$kind] ?? [] as $path) {
            $text .= $kind . "\t" . $path . "\n";
        }
    }

    return $text;
}

/**
 * The lists in a committed list file.
 *
 * @return array<string, list<string>>
 */
function readLintLists(string $file): array
{
    if (!is_file($file)) {
        lintFilesRefuse("{$file} does not exist; run `php tools/lint-files.php --write` in a checkout");
    }
    $lists = array_fill_keys(LINT_KINDS, []);
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $number => $line) {
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $fields = explode("\t", $line, 2);
        if (count($fields) !== 2 || !in_array($fields[0], LINT_KINDS, true) || $fields[1] === '') {
            lintFilesRefuse(sprintf('%s:%d is not "KIND<tab>PATH": %s', $file, $number + 1, $line));
        }
        $lists[$fields[0]][] = $fields[1];
    }

    return $lists;
}

/**
 * Run `$command` with stdio passed through, and return its exit status.
 *
 * @param non-empty-list<string> $command
 */
function runLinter(array $command): int
{
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);
    if (!is_resource($process)) {
        lintFilesRefuse('cannot start ' . $command[0]);
    }

    return proc_close($process);
}

$mode = null;
$kind = null;
$repo = $root;
$listFile = $root . '/tools/lint-files.txt';
$command = [];
$arguments = array_slice($argv, 1);
while ($arguments !== []) {
    $argument = array_shift($arguments);
    if ($argument === '--') {
        $command = $arguments;
        break;
    }
    if ($argument === '--write' || $argument === '--check') {
        $mode = substr($argument, 2);
    } elseif (preg_match('/^--(print|run|each)=(.+)$/', $argument, $match) === 1) {
        [$mode, $kind] = [$match[1], $match[2]];
    } elseif (str_starts_with($argument, '--repo=') && strlen($argument) > 7) {
        $repo = rtrim(substr($argument, 7), '/');
    } elseif (str_starts_with($argument, '--list=') && strlen($argument) > 7) {
        $listFile = substr($argument, 7);
    } else {
        $mode = null;
        break;
    }
}
$needsCommand = $mode === 'run' || $mode === 'each';
if ($mode === null || ($kind !== null && !in_array($kind, LINT_KINDS, true)) || $needsCommand !== ($command !== [])) {
    lintFilesRefuse(
        'usage: php tools/lint-files.php (--write | --check) [--repo=DIR] [--list=FILE]'
        . ' | --print=KIND [--list=FILE] | (--run=KIND | --each=KIND) [--list=FILE] -- COMMAND [ARG...]'
        . ' (KIND: ' . implode(', ', LINT_KINDS) . ')',
        64,
    );
}

if ($mode === 'write') {
    file_put_contents($listFile, renderLintLists(deriveLintLists($repo)));
    exit(0);
}
if ($mode === 'check') {
    $derived = renderLintLists(deriveLintLists($repo));
    $committed = is_file($listFile) ? (string) file_get_contents($listFile) : '';
    if ($derived === $committed) {
        printf("lint-files: %s matches what git tracks in %s\n", basename($listFile), $repo);
        exit(0);
    }
    $want = array_filter(explode("\n", $derived), static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#'));
    $have = array_filter(explode("\n", $committed), static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#'));
    fwrite(STDERR, "lint-files: {$listFile} is not what git tracks; run `php tools/lint-files.php --write` and commit it:\n");
    foreach (array_diff($want, $have) as $line) {
        fwrite(STDERR, "  missing  {$line}\n");
    }
    foreach (array_diff($have, $want) as $line) {
        fwrite(STDERR, "  stale    {$line}\n");
    }
    if (array_diff($want, $have) === [] && array_diff($have, $want) === []) {
        fwrite(STDERR, "  (the entries agree; the header, order or line endings differ)\n");
    }
    exit(1);
}

$files = readLintLists($listFile)[(string) $kind];
if ($mode === 'print') {
    echo $files === [] ? '' : implode("\n", $files) . "\n";
    exit(0);
}
if ($files === []) {
    lintFilesRefuse("no files of kind {$kind} in {$listFile}: the linter would pass without reading anything");
}
$listRoot = dirname($listFile, 2);
$missing = array_values(array_filter($files, static fn(string $file): bool => !is_file($listRoot . '/' . $file)));
if ($missing !== []) {
    lintFilesRefuse("{$listFile} lists files this tree does not have: " . implode(', ', $missing));
}
// The paths are relative to the tree the list describes, so the linter runs there.
if (!chdir($listRoot)) {
    lintFilesRefuse("cannot enter {$listRoot}");
}
if ($mode === 'run') {
    exit(runLinter([...$command, ...$files]) === 0 ? 0 : 1);
}
$failed = [];
foreach ($files as $file) {
    if (runLinter([...$command, $file]) !== 0) {
        $failed[] = $file;
    }
}
if ($failed !== []) {
    lintFilesRefuse(sprintf('%d of %d %s files failed: %s', count($failed), count($files), $kind, implode(', ', $failed)));
}

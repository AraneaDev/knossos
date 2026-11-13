<?php

declare(strict_types=1);

/*
 * The file length ratchet: no source or test file past the limit (600 lines),
 * except the ones file-length-baseline.json lists, each no longer than the
 * length it is listed at.
 *
 *   php tools/file-length-check.php [--root=DIR] [--baseline=FILE] [--write]
 *
 * --root is the git checkout checked, this one when it is not given (the
 * quality image has no .git, so CI passes the mounted checkout); --baseline
 * the list, the root's file-length-baseline.json when it is not given.
 * --write rewrites the list from the tree as it is, for after a split shrinks
 * a file. The files are the ones git tracks with a code extension, less test
 * fixtures and generated declaration files.
 *
 * Exit 1 names each file over its limit, and each listed file that is gone or
 * back within the limit, whose entry would otherwise let it grow again.
 */
require_once __DIR__ . '/lib/cli-arguments.php';

const FILE_LENGTH_EXTENSIONS = ['php', 'rs', 'ts', 'tsx', 'js', 'mjs', 'cjs', 'py'];

$options = cliOptions('usage: php tools/file-length-check.php [--root=DIR] [--baseline=FILE] [--write]', ['root', 'baseline'], ['write']);
$root = isset($options['root']) ? rtrim($options['root'], '/') : dirname(__DIR__);
$baselinePath = $options['baseline'] ?? $root . '/file-length-baseline.json';

$lengths = fileLengths($root);
if (isset($options['write'])) {
    $limit = is_file($baselinePath) ? readBaseline($baselinePath)['limit'] : 600;
    $over = array_filter($lengths, static fn(int $lines): bool => $lines > $limit);
    ksort($over, SORT_STRING);
    // Two-space indentation, as Prettier formats the repository's JSON.
    $json = json_encode(['limit' => $limit, 'files' => (object) $over], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $json = (string) preg_replace_callback('/^(?: {4})+/m', static fn(array $indent): string => str_repeat('  ', intdiv(strlen($indent[0]), 4)), $json);
    file_put_contents($baselinePath, $json . "\n");
    printf("File length baseline written: %d files over %d lines.\n", count($over), $limit);
    exit(0);
}

$baseline = readBaseline($baselinePath);
$errors = fileLengthErrors($lengths, $baseline['limit'], $baseline['files']);
if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . "\n");
    }
    fwrite(STDERR, sprintf("Split the file, or after a split run php tools/file-length-check.php --write.\n"));
    exit(1);
}
printf("File lengths passed: %d files, %d listed over %d lines, none longer than listed.\n", count($lengths), count($baseline['files']), $baseline['limit']);

/**
 * Lines per tracked code file, by path relative to `$root`.
 *
 * @return array<string, int>
 */
function fileLengths(string $root): array
{
    $listing = [];
    // The quality image carries no .git; CI hands the checkout over on a
    // mount another user owns, which git refuses without safe.directory.
    exec('git -c safe.directory=' . escapeshellarg($root) . ' -C ' . escapeshellarg($root) . ' ls-files -z 2>/dev/null', $listing, $status);
    if ($status !== 0) {
        fwrite(STDERR, "file-length-check: {$root} is not a git checkout.\n");
        exit(1);
    }
    $lengths = [];
    foreach (array_filter(explode("\0", implode("\n", $listing))) as $path) {
        if (!in_array(pathinfo($path, PATHINFO_EXTENSION), FILE_LENGTH_EXTENSIONS, true)
            || str_starts_with($path, 'tests/Fixtures/')
            || str_ends_with($path, '.d.ts')
            || !is_file($root . '/' . $path)) {
            continue;
        }
        $lengths[$path] = count(file($root . '/' . $path) ?: []);
    }

    return $lengths;
}

/**
 * The limit and the listed files with the length each may reach.
 *
 * @return array{limit: int, files: array<string, int>}
 */
function readBaseline(string $path): array
{
    $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    if (!is_array($decoded) || !is_int($decoded['limit'] ?? null) || $decoded['limit'] < 1 || !is_array($decoded['files'] ?? null)) {
        fwrite(STDERR, "file-length-check: {$path} needs a positive integer \"limit\" and a \"files\" object.\n");
        exit(1);
    }
    $files = [];
    foreach ($decoded['files'] as $file => $lines) {
        if (!is_string($file) || !is_int($lines)) {
            fwrite(STDERR, "file-length-check: {$path} lists {$file} without a whole number of lines.\n");
            exit(1);
        }
        $files[$file] = $lines;
    }

    return ['limit' => $decoded['limit'], 'files' => $files];
}

/**
 * What breaks the ratchet: a file past the limit or past its listed length,
 * and a listed file that is gone or within the limit again.
 *
 * @param array<string, int> $lengths
 * @param array<string, int> $listed
 * @return list<string>
 */
function fileLengthErrors(array $lengths, int $limit, array $listed): array
{
    $errors = [];
    foreach ($lengths as $path => $lines) {
        $cap = $listed[$path] ?? $limit;
        if ($lines > $cap) {
            $errors[] = isset($listed[$path])
                ? sprintf('%s has %d lines, more than the %d it is listed at.', $path, $lines, $cap)
                : sprintf('%s has %d lines, more than the limit of %d.', $path, $lines, $limit);
        }
    }
    foreach ($listed as $path => $cap) {
        if (!isset($lengths[$path])) {
            $errors[] = sprintf('%s is listed but is no longer a tracked code file.', $path);
        } elseif ($lengths[$path] <= $limit) {
            $errors[] = sprintf('%s is listed but has %d lines, within the limit of %d.', $path, $lengths[$path], $limit);
        }
    }

    return $errors;
}

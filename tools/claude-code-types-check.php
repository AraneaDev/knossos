<?php

declare(strict_types=1);

/**
 * Refuse Claude Code API declarations written by another version than the one the image pins.
 *
 *   php tools/claude-code-types-check.php [--root=DIR]
 *
 * The staged register.tsx type-check in tools/quality reads the declarations
 * in tools/claude-code-api, which tools/claude-code-types generates from the
 * Claude Code version the Dockerfile installs. A pin moved without them would
 * type-check the mod against an engine the gate does not run, so the pin, the
 * VERSION stamp and the version the declarations name in their first line
 * must agree. --root checks another tree laid out like this one. Exit 0 when
 * they agree, 1 when not, 64 when called wrong.
 */

$root = dirname(__DIR__);
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--root=') && strlen($argument) > 7) {
        $root = rtrim(substr($argument, 7), '/');
    } else {
        fwrite(STDERR, "usage: php tools/claude-code-types-check.php [--root=DIR]\n");
        exit(64);
    }
}

$regenerate = 'run tools/claude-code-types to regenerate them for the pinned version';
$errors = [];
$dockerfile = is_file($root . '/Dockerfile') ? (string) file_get_contents($root . '/Dockerfile') : '';
$pinned = preg_match('~@anthropic-ai/claude-code@([0-9][0-9.]*)~', $dockerfile, $match) === 1 ? $match[1] : null;
if ($pinned === null) {
    $errors[] = 'the Dockerfile pins no @anthropic-ai/claude-code version';
}
$stampFile = $root . '/tools/claude-code-api/VERSION';
$stamp = is_file($stampFile) ? trim((string) file_get_contents($stampFile)) : null;
if ($stamp === null || $stamp === '') {
    $errors[] = "tools/claude-code-api/VERSION is missing; {$regenerate}";
} elseif ($pinned !== null && $stamp !== $pinned) {
    $errors[] = "the declarations are from Claude Code {$stamp}, but the Dockerfile pins {$pinned}; {$regenerate}";
}
foreach (['claude-code', 'claude-code-tools'] as $module) {
    $file = $root . "/tools/claude-code-api/{$module}/index.d.ts";
    if (!is_file($file)) {
        $errors[] = "tools/claude-code-api/{$module}/index.d.ts is missing; {$regenerate}";
    }
}
$engine = $root . '/tools/claude-code-api/claude-code/index.d.ts';
if (is_file($engine) && $stamp !== null && $stamp !== '') {
    $handle = fopen($engine, 'rb');
    $first = $handle === false ? '' : rtrim((string) fgets($handle));
    if ($handle !== false) {
        fclose($handle);
    }
    if ($first !== "// Written by Claude Code {$stamp}.") {
        $errors[] = "tools/claude-code-api/claude-code/index.d.ts does not say it was written by Claude Code {$stamp} (it starts \"{$first}\"); {$regenerate}";
    }
}
if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "claude code types: {$error}\n");
    }
    exit(1);
}
printf("claude code types: written by Claude Code %s, the version the Dockerfile pins\n", $stamp);

<?php

declare(strict_types=1);

/**
 * Hold `npm audit` to a committed allowlist of dated exceptions.
 *
 *   php tools/npm-audit-allowlist.php [--allowlist=FILE] [--today=YYYY-MM-DD] REPORT...
 *
 * Each REPORT is the JSON `npm audit --json` wrote for one package tree. The
 * release lane used `npm audit --audit-level=high`, which has no way to accept
 * an advisory nobody can fix yet: one published with no patched release turns
 * every push to main red until upstream ships. The HIGH and CRITICAL
 * advisories accepted today are listed in tools/npm-audit-advisories.txt (or
 * FILE), one `ID<tab>package<tab>YYYY-MM-DD` per line. An advisory not on that
 * list fails the run, as `--audit-level=high` did, and so does a listed one
 * still found after its date. An entry no longer found in any report is
 * reported so the list can shrink, and does not fail.
 *
 * --today stands in for the date, so the expiry can be tested.
 * Exit 0 passes, 1 refuses, 64 means it was called wrong.
 */

$allowlist = __DIR__ . '/npm-audit-advisories.txt';
$today = date('Y-m-d');
$reports = [];
require_once __DIR__ . '/lib/cli-arguments.php';
// $argv is only a global where register_argc_argv is on (see tools/lib/cli-arguments.php).
$argv = cliArguments();
$usage = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--allowlist=') && strlen($argument) > 12) {
        $allowlist = substr($argument, 12);
    } elseif (preg_match('/^--today=(\d{4}-\d{2}-\d{2})$/', $argument, $match) === 1) {
        $today = $match[1];
    } elseif (!str_starts_with($argument, '--')) {
        $reports[] = $argument;
    } else {
        $usage = true;
    }
}
if ($usage || $reports === []) {
    fwrite(STDERR, "usage: php tools/npm-audit-allowlist.php [--allowlist=FILE] [--today=YYYY-MM-DD] REPORT...\n");
    exit(64);
}

/** Print why the advisories are refused and stop with a failing status. */
function npmAuditRefuse(string $message): never
{
    fwrite(STDERR, "npm audit: {$message}\n");
    exit(1);
}

if (!is_file($allowlist)) {
    npmAuditRefuse("{$allowlist} does not exist");
}
/** @var array<string, string> $allowed `ID<tab>package` => the last day it is accepted */
$allowed = [];
foreach (file($allowlist, FILE_IGNORE_NEW_LINES) ?: [] as $number => $line) {
    if (trim($line) === '' || str_starts_with($line, '#')) {
        continue;
    }
    $fields = explode("\t", $line);
    if (count($fields) !== 3 || $fields[0] === '' || $fields[1] === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $fields[2]) !== 1) {
        npmAuditRefuse(sprintf('%s:%d is not "ID<tab>package<tab>YYYY-MM-DD": %s', $allowlist, $number + 1, $line));
    }
    $allowed[$fields[0] . "\t" . $fields[1]] = $fields[2];
}

/** @var array<string, array{severity: string, title: string, reports: list<string>}> $found */
$found = [];
foreach ($reports as $report) {
    try {
        $decoded = json_decode(is_file($report) ? (string) file_get_contents($report) : '', true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        npmAuditRefuse("{$report} is not JSON: " . $exception->getMessage());
    }
    if (!is_array($decoded) || !is_array($decoded['vulnerabilities'] ?? null)) {
        $error = is_array($decoded) && is_array($decoded['error'] ?? null) ? ': ' . (string) ($decoded['error']['summary'] ?? '') : '';
        npmAuditRefuse("{$report} is not an npm audit report: it has no vulnerabilities{$error}");
    }
    foreach ($decoded['vulnerabilities'] as $vulnerability) {
        // A package flagged only because it depends on another lists names in
        // `via`; the advisory itself is the object, under the package it names.
        foreach (is_array($vulnerability['via'] ?? null) ? $vulnerability['via'] : [] as $via) {
            $severity = is_array($via) ? strtolower((string) ($via['severity'] ?? '')) : '';
            if ($severity !== 'high' && $severity !== 'critical') {
                continue;
            }
            $url = (string) ($via['url'] ?? '');
            $id = preg_match('#/(GHSA(?:-[0-9a-z]{4}){3})$#', $url, $match) === 1 ? $match[1] : (string) ($via['source'] ?? '?');
            $key = $id . "\t" . (string) ($via['name'] ?? '?');
            $found[$key] ??= ['severity' => strtoupper($severity), 'title' => (string) ($via['title'] ?? ''), 'reports' => []];
            if (!in_array($report, $found[$key]['reports'], true)) {
                $found[$key]['reports'][] = $report;
            }
        }
    }
}
ksort($found);

foreach (array_keys(array_diff_key($allowed, $found)) as $gone) {
    printf("npm audit: no longer found, remove from the allowlist: %s\n", str_replace("\t", ' ', $gone));
}
$refused = [];
foreach ($found as $key => $advisory) {
    $line = sprintf('  %s %s: %s (%s)', $advisory['severity'], str_replace("\t", ' in ', $key), $advisory['title'], implode(', ', $advisory['reports']));
    if (!array_key_exists($key, $allowed)) {
        $refused[] = $line;
    } elseif ($allowed[$key] < $today) {
        $refused[] = $line . " accepted until {$allowed[$key]}, which has passed";
    }
}
if ($refused !== []) {
    npmAuditRefuse(sprintf(
        "%d advisory(ies) not accepted by %s; fix them, or list them in a reviewed change that says why and until when:\n%s",
        count($refused),
        $allowlist,
        implode("\n", $refused),
    ));
}
printf("npm audit: %d HIGH or CRITICAL advisory(ies), all accepted by the allowlist\n", count($found));

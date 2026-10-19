<?php

declare(strict_types=1);

/**
 * Hold the quality image's Trivy findings to a committed allowlist.
 *
 *   php tools/trivy-allowlist.php [--allowlist=FILE] REPORT
 *
 * REPORT is Trivy's JSON for the quality image, already narrowed to fixed
 * HIGH and CRITICAL findings. That image is the gate's own toolchain, never
 * shipped, and carries findings nobody here can fix on the day they appear, so
 * its scan runs with `--exit-code 0` and used to fail on nothing at all. The
 * findings known today are listed in tools/quality-image-vulnerabilities.txt
 * (or FILE), one `ID<tab>package` per line; any finding not on that list fails
 * the run, so a new HIGH or CRITICAL is seen the day it lands. An entry no
 * longer found is reported so the list can shrink, and does not fail.
 * Exit 0 passes, 1 refuses, 64 means it was called wrong.
 */

$allowlist = __DIR__ . '/quality-image-vulnerabilities.txt';
$report = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--allowlist=') && strlen($argument) > 12) {
        $allowlist = substr($argument, 12);
    } elseif (!str_starts_with($argument, '--') && $report === null) {
        $report = $argument;
    } else {
        $report = null;
        break;
    }
}
if ($report === null) {
    fwrite(STDERR, "usage: php tools/trivy-allowlist.php [--allowlist=FILE] REPORT\n");
    exit(64);
}

/** Print why the findings are refused and stop with a failing status. */
function trivyRefuse(string $message): never
{
    fwrite(STDERR, "quality image vulnerabilities: {$message}\n");
    exit(1);
}

if (!is_file($allowlist)) {
    trivyRefuse("{$allowlist} does not exist");
}
$allowed = [];
foreach (file($allowlist, FILE_IGNORE_NEW_LINES) ?: [] as $number => $line) {
    if (trim($line) === '' || str_starts_with($line, '#')) {
        continue;
    }
    $fields = explode("\t", $line);
    if (count($fields) !== 2 || $fields[0] === '' || $fields[1] === '') {
        trivyRefuse(sprintf('%s:%d is not "ID<tab>package": %s', $allowlist, $number + 1, $line));
    }
    $allowed[$fields[0] . "\t" . $fields[1]] = true;
}

try {
    $decoded = json_decode(is_file($report) ? (string) file_get_contents($report) : '', true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    trivyRefuse("{$report} is not JSON: " . $exception->getMessage());
}
if (!is_array($decoded) || !array_key_exists('Results', $decoded)) {
    trivyRefuse("{$report} is not a Trivy report: it has no Results");
}
$found = [];
foreach (is_array($decoded['Results']) ? $decoded['Results'] : [] as $result) {
    foreach (is_array($result['Vulnerabilities'] ?? null) ? $result['Vulnerabilities'] : [] as $vulnerability) {
        $id = (string) ($vulnerability['VulnerabilityID'] ?? '');
        $package = (string) ($vulnerability['PkgName'] ?? '');
        $found[$id . "\t" . $package] = (string) ($vulnerability['Severity'] ?? '?');
    }
}
ksort($found);

$new = array_diff_key($found, $allowed);
foreach (array_keys(array_diff_key($allowed, $found)) as $gone) {
    printf("quality image vulnerabilities: no longer found, remove from the allowlist: %s\n", str_replace("\t", ' ', $gone));
}
if ($new !== []) {
    $lines = [];
    foreach ($new as $key => $severity) {
        $lines[] = sprintf('  %s %s', $severity, str_replace("\t", ' in ', $key));
    }
    trivyRefuse(sprintf(
        "%d finding(s) not on %s; fix them, or add them in a reviewed change that says why:\n%s",
        count($new),
        $allowlist,
        implode("\n", $lines),
    ));
}
printf("quality image vulnerabilities: %d finding(s), all on the allowlist\n", count($found));

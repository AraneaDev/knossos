<?php

declare(strict_types=1);

/**
 * Say which architecture budget the gate lane failed on.
 *
 *   php tools/gate-report.php GATE_JSON
 *
 * Reads what `knossos quality-gate --json` wrote and prints every check's
 * value against its limit, so a failing run names the budget it broke rather
 * than the gate's one line, "Architecture quality budgets failed.". Exit 0 when
 * the gate passed, every check passed and at least one ran; 1 when any check
 * failed or none ran, since a gate that checked nothing proves nothing; 2 when
 * the file is not a gate result.
 */
if ($argc !== 2) {
    fwrite(STDERR, "usage: php tools/gate-report.php GATE_JSON\n");
    exit(2);
}
try {
    $result = json_decode((string) @file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fwrite(STDERR, sprintf("gate: %s is not a gate result: %s.\n", $argv[1], $exception->getMessage()));
    exit(2);
}
$checks = is_array($result) ? ($result['data']['checks'] ?? null) : null;
if (!is_array($checks) || !is_bool($result['data']['passed'] ?? null)) {
    fwrite(STDERR, sprintf("gate: %s is not a gate result: it holds no data.passed and data.checks.\n", $argv[1]));
    exit(2);
}

$failed = [];
foreach ($checks as $check) {
    $metric = (string) ($check['metric'] ?? '?');
    $passed = ($check['passed'] ?? false) === true;
    $reason = isset($check['indeterminate_reason']) ? sprintf(' (indeterminate: %s)', (string) $check['indeterminate_reason']) : '';
    printf(
        "gate: %s %s, limit %s: %s%s\n",
        $metric,
        json_encode($check['actual'] ?? null),
        json_encode($check['limit'] ?? null),
        $passed ? 'pass' : 'FAIL',
        $reason,
    );
    if (!$passed) {
        $failed[] = $metric;
    }
}
if ($checks === []) {
    fwrite(STDERR, "gate: no budget was checked, so the gate measured nothing.\n");
    exit(1);
}
if ($failed !== []) {
    fwrite(STDERR, sprintf("gate: %d of %d budgets failed: %s.\n", count($failed), count($checks), implode(', ', $failed)));
    exit(1);
}
if ($result['data']['passed'] !== true) {
    fwrite(STDERR, "gate: the gate reported a failure that no check accounts for.\n");
    exit(1);
}
printf("gate: all %d budgets passed.\n", count($checks));

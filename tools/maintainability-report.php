<?php

declare(strict_types=1);

/*
 * The maintainability budgets: duplicate blocks across files, the most complex
 * and the longest PHP function, and the widest dependency fan-out.
 *
 *   php tools/maintainability-report.php [--root=DIR] [--budgets=FILE]
 *
 * --root is the tree measured, this checkout when it is not given; --budgets
 * the limits, the root's maintainability-budgets.json when it is not given. A limit that is missing or not a number at least 0 is refused with
 * exit 1 before anything is measured: compared as it was, a missing limit
 * failed with a budget printed as 0, and a quoted one was cast.
 */
require_once __DIR__ . '/lib/budget-numbers.php';
require_once __DIR__ . '/lib/dependency-fanout.php';
require_once __DIR__ . '/lib/php-function-metrics.php';

const MAINTAINABILITY_METRICS = ['cross_file_duplicate_blocks', 'max_php_function_complexity', 'max_php_function_lines', 'max_dependency_fanout'];

$root = dirname(__DIR__);
$budgetPath = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--root=') && $argument !== '--root=') {
        $root = rtrim(substr($argument, strlen('--root=')), '/');
    } elseif (str_starts_with($argument, '--budgets=') && $argument !== '--budgets=') {
        $budgetPath = substr($argument, strlen('--budgets='));
    } else {
        fwrite(STDERR, "usage: php tools/maintainability-report.php [--root=DIR] [--budgets=FILE]\n");
        exit(2);
    }
}
$budgetPath ??= $root . '/maintainability-budgets.json';
[$budgets, $budgetErrors] = readBudgetFile($budgetPath);
foreach ($budgets === null ? [] : MAINTAINABILITY_METRICS as $metric) {
    $error = budgetNumberError($metric, $budgets[$metric] ?? null, 0.0);
    if ($error !== null) {
        $budgetErrors[] = $error;
    }
}
if ($budgetErrors !== []) {
    foreach ($budgetErrors as $error) {
        fwrite(STDERR, sprintf("maintainability budgets %s: %s\n", $budgetPath, $error));
    }
    exit(1);
}
$reportPath = $root . '/coverage/quality/maintainability.json';
$patterns = [$root . '/src/*.php', $root . '/src/*/*.php', $root . '/src/*/*/*.php', $root . '/workers/php/src/*.php', $root . '/workers/php/src/*/*.php', $root . '/workers/typescript/src/*.js', $root . '/workers/python/bin/*.py', $root . '/workers/python/bin/knossos_python/*.py'];
$paths = [];
foreach ($patterns as $pattern) {
    foreach (glob($pattern) ?: [] as $path) {
        $paths[$path] = true;
    }
}
ksort($paths, SORT_STRING);
$files = [];
$blocks = [];
$functions = [];
foreach (array_keys($paths) as $path) {
    $relative = str_replace($root . '/', '', $path);
    $extension = pathinfo($path, PATHINFO_EXTENSION);
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        throw new RuntimeException('Unable to read ' . $relative);
    }
    $logical = array_values(array_filter(array_map(static fn(string $line): string => trim($line), $lines), static fn(string $line): bool => $line !== '' && !str_starts_with($line, '//') && !str_starts_with($line, '#') && !str_starts_with($line, '*')));
    $source = implode("\n", $logical);
    $decisions = match ($extension) {
        'php' => preg_match_all('/\b(?:if|elseif|for|foreach|while|case|catch|match)\b|&&|\|\||\?\?/', $source),
        'js' => preg_match_all('/\b(?:if|else if|for|while|case|catch)\b|&&|\|\||\?\?/', $source),
        'py' => preg_match_all('/\b(?:if|elif|for|while|case|except)\b|\band\b|\bor\b/', $source),
        default => 0,
    };
    $dependencies = dependencyFanout($extension, (string) file_get_contents($path));
    $files[$relative] = ['language' => $extension, 'lines' => count($lines), 'logical_lines' => count($logical), 'decision_points' => $decisions, 'dependency_fanout' => $dependencies];
    if ($extension === 'php') {
        $functions = array_merge($functions, phpFunctionMetrics((string) file_get_contents($path), $relative));
    }
    for ($offset = 0; $offset + 7 < count($logical); ++$offset) {
        $window = array_slice($logical, $offset, 8);
        $normalized = preg_replace('/\s+/', ' ', implode("\n", $window));
        if (!is_string($normalized) || strlen($normalized) < 160) {
            continue;
        }
        $blocks[hash('sha256', $normalized)][] = ['file' => $relative, 'logical_line' => $offset + 1];
    }
}
$duplicates = array_filter($blocks, static function (array $locations): bool {
    return count(array_unique(array_column($locations, 'file'))) > 1;
});
ksort($duplicates, SORT_STRING);
uasort($files, static fn(array $left, array $right): int => [$right['decision_points'], $right['logical_lines']] <=> [$left['decision_points'], $left['logical_lines']]);
usort($functions, static fn(array $left, array $right): int => [$right['complexity'], $right['lines']] <=> [$left['complexity'], $left['lines']]);
$maxPhpComplexity = max(array_column($functions, 'complexity'));
$maxPhpFunctionLines = max(array_column($functions, 'lines'));
$maxDependencyFanout = max(array_column($files, 'dependency_fanout'));
$report = [
    'schema_version' => 1,
    'summary' => [
        'files' => count($files),
        'logical_lines' => array_sum(array_column($files, 'logical_lines')),
        'decision_points' => array_sum(array_column($files, 'decision_points')),
        'cross_file_duplicate_blocks' => count($duplicates),
        'max_php_function_complexity' => $maxPhpComplexity,
        'max_php_function_lines' => $maxPhpFunctionLines,
        'max_dependency_fanout' => $maxDependencyFanout,
    ],
    'highest_complexity_files' => array_slice($files, 0, 20, true),
    'highest_complexity_php_functions' => array_slice($functions, 0, 30),
    'cross_file_duplicate_blocks' => array_slice($duplicates, 0, 50, true),
    'prerequisite_static_gates' => ['PHPStan unused/unreachable analysis', 'ESLint no-unused-vars', 'Ruff F and warn-unreachable through mypy'],
];
if (!is_dir(dirname($reportPath)) && !mkdir(dirname($reportPath), 0775, true) && !is_dir(dirname($reportPath))) {
    throw new RuntimeException('Unable to create quality report directory.');
}
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$failures = [];
foreach (MAINTAINABILITY_METRICS as $metric) {
    if ($report['summary'][$metric] > $budgets[$metric]) {
        $failures[] = sprintf('%s is %d; budget is %s.', $metric, $report['summary'][$metric], (string) $budgets[$metric]);
    }
}
printf("Maintainability report: %d files, %d logical lines, %d decision points, %d cross-file duplicate blocks; PHP max complexity %d and function lines %d.\n", $report['summary']['files'], $report['summary']['logical_lines'], $report['summary']['decision_points'], $report['summary']['cross_file_duplicate_blocks'], $maxPhpComplexity, $maxPhpFunctionLines);
if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

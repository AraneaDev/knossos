<?php

declare(strict_types=1);

/**
 * Hold the MCP Inspector's `tools/list` answer to the tool catalogue.
 *
 *   php tools/inspector-tools-check.php [--catalog=FILE] OUTPUT
 *
 * OUTPUT is what `inspector --cli ... --method tools/list` printed. The
 * release lane used to take the Inspector's exit status as the verdict, which
 * says only that the server answered: a server advertising one tool, or none,
 * passed. The expected names come from the generated reference
 * (docs/reference/mcp-tools.md unless --catalog names another), one `## \`name\``
 * heading per tool, which `tools/generate-reference.php --check` keeps equal to
 * the live catalogue. Exit 0 when the two name the same tools, 1 when they do
 * not or OUTPUT is not a tool list, 64 when called wrong.
 */

$catalog = dirname(__DIR__) . '/docs/reference/mcp-tools.md';
$output = null;
require_once __DIR__ . '/lib/cli-arguments.php';
// $argv is only a global where register_argc_argv is on (see tools/lib/cli-arguments.php).
$argv = cliArguments();
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--catalog=') && strlen($argument) > 10) {
        $catalog = substr($argument, 10);
    } elseif (!str_starts_with($argument, '--') && $output === null) {
        $output = $argument;
    } else {
        $output = null;
        break;
    }
}
if ($output === null) {
    fwrite(STDERR, "usage: php tools/inspector-tools-check.php [--catalog=FILE] OUTPUT\n");
    exit(64);
}

/** Print why the answer does not match and stop with a failing status. */
function inspectorRefuse(string $message): never
{
    fwrite(STDERR, "inspector tools/list: {$message}\n");
    exit(1);
}

preg_match_all('/^## `([a-z0-9_]+)`$/m', is_file($catalog) ? (string) file_get_contents($catalog) : '', $headings);
$expected = $headings[1];
if ($expected === []) {
    inspectorRefuse("{$catalog} names no tools");
}

try {
    $answer = json_decode(is_file($output) ? (string) file_get_contents($output) : '', true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    inspectorRefuse("{$output} is not JSON: " . $exception->getMessage());
}
if (!is_array($answer) || !is_array($answer['tools'] ?? null)) {
    inspectorRefuse("{$output} holds no `tools` list");
}
$advertised = [];
foreach ($answer['tools'] as $tool) {
    if (!is_array($tool) || !is_string($tool['name'] ?? null)) {
        inspectorRefuse("{$output} lists a tool without a name");
    }
    $advertised[] = $tool['name'];
}

$missing = array_values(array_diff($expected, $advertised));
$extra = array_values(array_diff($advertised, $expected));
$repeated = array_values(array_unique(array_diff_assoc($advertised, array_unique($advertised))));
if ($missing !== [] || $extra !== [] || $repeated !== []) {
    $reasons = [];
    if ($missing !== []) {
        $reasons[] = 'not advertised: ' . implode(', ', $missing);
    }
    if ($extra !== []) {
        $reasons[] = 'not in the catalogue: ' . implode(', ', $extra);
    }
    if ($repeated !== []) {
        $reasons[] = 'advertised twice: ' . implode(', ', $repeated);
    }
    inspectorRefuse(sprintf('the server advertised %d tools, the catalogue has %d; %s', count($advertised), count($expected), implode('; ', $reasons)));
}
printf("inspector tools/list: the server advertised all %d catalogued tools\n", count($expected));

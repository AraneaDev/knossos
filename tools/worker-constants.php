<?php

declare(strict_types=1);

/*
 * Writes the constants every worker keeps its own copy of from their one
 * source, schemas/worker-constants.json, or with --check refuses when a
 * committed copy differs from what it would write.
 *
 *   php tools/worker-constants.php [--check] [--root=DIR]
 *
 * The core reads what the workers produce as one protocol, so a launch
 * interpreter, an ignore rule, a symlink hop limit, a refusal or a part size
 * one worker has and another lacks is a disagreement nothing at run time
 * notices. Each copy sits between a `BEGIN GENERATED worker-constants:<key>`
 * and an `END GENERATED worker-constants:<key>` comment, written in the shape
 * that language's formatter leaves alone, so the formatter check and this one
 * never fight. Only the core's IgnoreMatcher reads the JSON itself: a worker
 * can be copied without the rest of the installation. --root names
 * the checkout, this one unless given; the tests point it at a stale copy to
 * see the check refuse.
 */
require_once __DIR__ . '/lib/cli-arguments.php';
$options = cliOptions('usage: php tools/worker-constants.php [--check] [--root=DIR]', ['root'], ['check'], 64);
$root = isset($options['root']) ? rtrim($options['root'], '/') : dirname(__DIR__);
$check = isset($options['check']);

const CONSTANTS_SOURCE = 'schemas/worker-constants.json';

/** Prettier's line width for the TypeScript worker, from .editorconfig's defaults. */
const JS_WIDTH = 80;

/** `ruff format`'s line length for the Python worker. */
const PYTHON_WIDTH = 120;

/** rustfmt's default max_width. */
const RUST_WIDTH = 100;

/** A PHP single-quoted string. */
function phpString(string $value): string
{
    return "'" . strtr($value, ['\\' => '\\\\', "'" => "\\'"]) . "'";
}

/** A double-quoted string as JavaScript, Python and Rust all read it. */
function quoted(string $value): string
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/** An integer with `_` between each group of three digits once it has five, as all four languages accept. */
function integer(int $value): string
{
    $digits = (string) $value;

    return strlen($digits) < 5 ? $digits : strrev(implode('_', str_split(strrev($digits), 3)));
}

/**
 * A statement that breaks after `=` when it does not fit, as Prettier and
 * rustfmt both break a long string initialiser.
 */
function assignment(string $head, string $value, string $tail, int $width): string
{
    $line = $head . ' ' . $value . $tail;

    return strlen($line) <= $width ? $line : $head . "\n    " . $value . $tail;
}

/**
 * A JavaScript array as Prettier prints it at $indent: on one line when it
 * fits after $lead, else one element per line. An array of arrays that each
 * hold more than one element always breaks.
 *
 * @param list<mixed> $items
 */
function jsArray(array $items, int $indent, int $lead): string
{
    $parts = array_map(static fn(mixed $item): string => is_array($item) ? jsArray(array_values($item), $indent + 4, 0) : quoted((string) $item), $items);
    $nested = $items !== [] && count($items) > 1 && array_filter($items, static fn(mixed $item): bool => !is_array($item) || count($item) < 2) === [];
    $line = '[' . implode(', ', $parts) . ']';
    if (!$nested && $indent + $lead + strlen($line) + 1 <= JS_WIDTH) {
        return $line;
    }
    $pad = str_repeat(' ', $indent + 4);

    return "[\n" . implode('', array_map(static fn(string $part): string => $pad . $part . ",\n", $parts)) . str_repeat(' ', $indent) . ']';
}

/**
 * A Python list as `ruff format` prints it at $indent: on one line when it
 * fits after $lead, else one element per line with a trailing comma.
 *
 * @param list<mixed> $items
 */
function pythonList(array $items, int $indent, int $lead, string $open = '[', string $close = ']'): string
{
    $parts = array_map(static fn(mixed $item): string => is_array($item) ? pythonList(array_values($item), $indent + 4, 0) : quoted((string) $item), $items);
    $line = $open . implode(', ', $parts) . $close;
    if ($indent + $lead + strlen($line) + 1 <= PYTHON_WIDTH) {
        return $line;
    }
    $pad = str_repeat(' ', $indent + 4);

    return $open . "\n" . implode('', array_map(static fn(string $part): string => $pad . $part . ",\n", $parts)) . str_repeat(' ', $indent) . $close;
}

/**
 * A Rust array of short strings as rustfmt prints it: on one line when the
 * statement fits, else packed as many to a line as fit.
 *
 * @param list<string> $items
 */
function rustArray(string $head, array $items): string
{
    $parts = array_map(quoted(...), $items);
    $line = $head . ' [' . implode(', ', $parts) . '];';
    if (strlen($line) <= RUST_WIDTH) {
        return $line;
    }
    $lines = [];
    $current = '   ';
    foreach ($parts as $part) {
        if (strlen($current) + 1 + strlen($part) + 1 > RUST_WIDTH) {
            $lines[] = $current;
            $current = '   ';
        }
        $current .= ' ' . $part . ',';
    }
    $lines[] = $current;

    return $head . " [\n" . implode("\n", $lines) . "\n];";
}

/**
 * Whether $value has the shape $key's copies are rendered from: a list of
 * names, the rule lists of the built-in exclusions, a positive integer or a
 * sentence.
 */
function wellFormed(string $key, mixed $value): bool
{
    $names = static fn(mixed $list): bool => is_array($list) && array_is_list($list) && array_filter($list, static fn(mixed $item): bool => !is_string($item)) === [];

    return match ($key) {
        'launch_interpreters' => $names($value) && $value !== [],
        'built_in_exclusions' => is_array($value)
            && array_keys($value) === ['segments', 'anchored_segments', 'anchor_roots', 'prefixes', 'sequences', 'suffixes', 'path_prefixes', 'patterns']
            && array_filter($value, static fn(mixed $list, string $rule): bool => $rule === 'sequences'
                ? !(is_array($list) && array_is_list($list) && array_filter($list, static fn(mixed $pair): bool => !$names($pair) || count($pair) !== 2) === [])
                : !$names($list), ARRAY_FILTER_USE_BOTH) === [],
        'max_symlink_hops', 'input_hashes_part_bytes' => is_int($value) && $value > 0,
        'invalid_limits' => is_string($value) && $value !== '',
        default => false,
    };
}

/**
 * Per file, per key: the comment that opens a marker, and the statement the
 * markers hold.
 *
 * @return array<string, array{0: string, 1: array<string, callable(mixed): string>}>
 */
function targets(): array
{
    return [
        'workers/php/src/FactCollector.php' => ['//', [
            'launch_interpreters' => static fn(array $value): string => '    private const INTERPRETERS = [' . implode(', ', array_map(phpString(...), $value)) . '];',
        ]],
        'workers/php/src/WorkerServer.php' => ['//', [
            'invalid_limits' => static fn(string $value): string => '    public const INVALID_LIMITS = ' . phpString($value) . ';',
            'input_hashes_part_bytes' => static fn(int $value): string => '    private const INPUT_HASHES_PART_BYTES = ' . integer($value) . ';',
        ]],
        'tools/scanner-conformance' => ['//', [
            'invalid_limits' => static fn(string $value): string => 'const SCAN_LIMITS_INVALID = ' . phpString($value) . ';',
        ]],
        'workers/typescript/src/launch-facts.js' => ['//', [
            'launch_interpreters' => static fn(array $value): string => 'const INTERPRETERS = new Set(' . jsArray($value, 0, strlen('const INTERPRETERS = new Set(') + 1) . ');',
        ]],
        'workers/typescript/src/exclusions.js' => ['//', [
            'built_in_exclusions' => static function (array $value): string {
                $lines = [];
                foreach ($value as $key => $items) {
                    $lines[] = '    ' . $key . ': ' . jsArray($items, 4, strlen($key) + 2) . ',';
                }

                return "export const BUILT_IN_EXCLUSIONS = Object.freeze({\n" . implode("\n", $lines) . "\n});";
            },
        ]],
        'workers/typescript/src/input-hashes-parts.js' => ['//', [
            'input_hashes_part_bytes' => static fn(int $value): string => 'export const INPUT_HASHES_PART_BYTES = ' . integer($value) . ';',
        ]],
        'workers/typescript/src/project-paths.js' => ['//', [
            'max_symlink_hops' => static fn(int $value): string => 'const MAX_SYMLINK_HOPS = ' . integer($value) . ';',
        ]],
        'workers/typescript/src/request-validation.js' => ['//', [
            'invalid_limits' => static fn(string $value): string => assignment('export const INVALID_LIMITS =', quoted($value), ';', JS_WIDTH),
        ]],
        'workers/python/bin/knossos_python/launches.py' => ['#', [
            'launch_interpreters' => static fn(array $value): string => 'INTERPRETERS = ' . pythonList($value, 0, strlen('INTERPRETERS = '), '{', '}'),
        ]],
        'workers/python/bin/knossos_python/exclusions.py' => ['#', [
            'built_in_exclusions' => static function (array $value): string {
                $lines = [];
                foreach ($value as $key => $items) {
                    $lines[] = '    ' . quoted($key) . ': ' . pythonList($items, 4, strlen(quoted($key)) + 2) . ',';
                }

                return "BUILT_IN_RULES: dict[str, Any] = {\n" . implode("\n", $lines) . "\n}";
            },
        ]],
        'workers/python/bin/knossos_python/safe_io.py' => ['#', [
            'max_symlink_hops' => static fn(int $value): string => 'MAX_SYMLINK_HOPS = ' . integer($value),
        ]],
        'workers/python/bin/worker.py' => ['#', [
            'invalid_limits' => static fn(string $value): string => 'INVALID_LIMITS = ' . quoted($value),
            'input_hashes_part_bytes' => static fn(int $value): string => 'INPUT_HASHES_PART_BYTES = ' . integer($value),
        ]],
        'workers/rust/src/visit/launches.rs' => ['//', [
            'launch_interpreters' => static fn(array $value): string => rustArray(sprintf('const INTERPRETERS: [&str; %d] =', count($value)), $value),
        ]],
        'workers/rust/src/server.rs' => ['//', [
            'input_hashes_part_bytes' => static fn(int $value): string => 'const INPUT_HASHES_PART_BYTES: usize = ' . integer($value) . ';',
        ]],
        'workers/rust/src/params.rs' => ['//', [
            'invalid_limits' => static fn(string $value): string => assignment('pub(crate) const INVALID_LIMITS: &str =', quoted($value), ';', RUST_WIDTH),
        ]],
    ];
}

$source = @file_get_contents($root . '/' . CONSTANTS_SOURCE);
$constants = is_string($source) ? json_decode($source, true) : null;
if (!is_array($constants)) {
    fwrite(STDERR, CONSTANTS_SOURCE . " is missing or is not a JSON object.\n");
    exit(1);
}
// Every value is checked before any copy is touched, so a malformed source
// is refused by name and never half written.
foreach (array_unique(array_merge(...array_map(static fn(array $target): array => array_keys($target[1]), array_values(targets())))) as $key) {
    if (!array_key_exists($key, $constants)) {
        fwrite(STDERR, CONSTANTS_SOURCE . " has no {$key}.\n");
        exit(1);
    }
    if (!wellFormed($key, $constants[$key])) {
        fwrite(STDERR, sprintf("%s: %s must be %s.\n", CONSTANTS_SOURCE, $key, match ($key) {
            'launch_interpreters' => 'a list of names, at least one',
            'built_in_exclusions' => 'an object of the eight IgnoreMatcher::workerRules() lists, in their order',
            'max_symlink_hops', 'input_hashes_part_bytes' => 'a positive integer',
            default => 'a sentence',
        }));
        exit(1);
    }
}
$stale = [];
foreach (targets() as $path => [$comment, $keys]) {
    $target = $root . '/' . $path;
    $contents = @file_get_contents($target);
    if (!is_string($contents)) {
        $stale[] = $path . ' (missing)';
        continue;
    }
    $written = $contents;
    foreach ($keys as $key => $render) {
        $marker = preg_quote($comment, '/') . ' (BEGIN|END) GENERATED worker-constants:' . preg_quote($key, '/');
        $pattern = '/^([ \t]*)' . str_replace('(BEGIN|END)', 'BEGIN', $marker) . '\n.*?^[ \t]*' . str_replace('(BEGIN|END)', 'END', $marker) . '$/ms';
        $count = 0;
        $written = (string) preg_replace_callback(
            $pattern,
            static fn(array $match): string => $match[1] . "{$comment} BEGIN GENERATED worker-constants:{$key}\n" . $render($constants[$key]) . "\n" . $match[1] . "{$comment} END GENERATED worker-constants:{$key}",
            $written,
            -1,
            $count,
        );
        if ($count !== 1) {
            $stale[] = sprintf('%s (%d marker pairs for %s, not 1)', $path, $count, $key);
        }
    }
    if ($written === $contents) {
        continue;
    }
    if ($check) {
        $stale[] = $path;
        continue;
    }
    file_put_contents($target, $written);
}
$stale = array_values(array_unique($stale));
if ($stale !== []) {
    fwrite(STDERR, 'Worker constants are stale: ' . implode(', ', $stale) . '; run php tools/worker-constants.php' . PHP_EOL);
    exit(1);
}
echo $check ? "Worker constants are current.\n" : "Wrote the worker constants.\n";

<?php

declare(strict_types=1);

/*
 * Holds every public method of the interfaces under src/, and the named worker
 * entry points, to a descriptive doc comment.
 *
 *   php tools/api-documentation-check.php [--root=DIR]
 *
 * --root checks another tree laid out like this one; the tests plant an
 * undocumented contract in one to see the check refuse.
 */
$root = dirname(__DIR__);
require_once __DIR__ . '/lib/cli-arguments.php';
$options = cliOptions('usage: php tools/api-documentation-check.php [--root=DIR]', ['root'], [], 64);
$root = isset($options['root']) ? rtrim($options['root'], '/') : $root;
// Shared with tools/docstring-report.php on purpose: both gates answer "is this
// documented", and they used to disagree.
require __DIR__ . '/lib/docblocks.php';
$failures = [];
$checked = [];
// Recursive, not glob('/src/*/*.php'): that pattern matches exactly one
// directory deep, so interfaces nested deeper (Mcp/Protocol/ProtocolProfile,
// Scanner/Worker/*Interface) were silently skipped while the gate still
// reported a contract count that read as complete.
$sourceFiles = new RegexIterator(
    new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)),
    '/\\.php$/',
);
foreach ($sourceFiles as $file) {
    $path = $file->getPathname();
    $source = (string) file_get_contents($path);
    // Tokenised, so the interface is found by declaration rather than by the word
    // appearing in prose ("every interface and enum"), which used to pull a plain
    // class into the gate and name its contracts after the following word.
    $interface = null;
    foreach (declaredTypes($source) as $type) {
        if ($type['kind'] === 'interface') {
            $interface = $type['name'];
            break;
        }
    }
    if ($interface === null) {
        continue;
    }
    foreach (declaredFunctions($source) as $function) {
        if ($function['visibility'] !== 'public') {
            continue;
        }
        $method = $function['name'];
        // documented is computed from the docblock *immediately* preceding the
        // declaration. The previous regex could start at an earlier docblock and
        // swallow everything between, so an annotation-only method borrowed the
        // interface's class summary and passed.
        if (!$function['documented']) {
            $failures[] = $interface . '::' . $method . ' requires a descriptive docblock summary';
        }
        $checked[] = 'php:' . $interface . '::' . $method;
    }
}

// An interface-free src/ would pass with nothing checked, as the
// one-directory glob this replaced once nearly did.
if ($checked === []) {
    $failures[] = 'no public interface method was found under ' . $root . '/src';
}

// The worker's documented exports are found by declaration across its src/
// modules rather than in one pinned file, so moving a function between modules
// keeps it checked. Each module is joined on its own line; a declaration
// pattern below names one symbol, which the worker exports from one module.
$javascriptModules = glob($root . '/workers/typescript/src/*.js') ?: [];
sort($javascriptModules, SORT_STRING);
$javascript = implode("\n", array_map(static fn(string $module): string => (string) file_get_contents($module), $javascriptModules));
// `(?:(?!\*\/)[\s\S])*?` keeps each capture inside ONE docblock, for the same
// reason the PHP branch above stopped at the immediately preceding one: a bare
// `.*?` starts at the first `/**` in the file, so an undocumented declaration
// would borrow the class summary and pass.
// `scan(` is a method name, not a file-unique declaration, so its matcher runs
// against the TypeScriptScanner class body alone. Searched over the whole file
// it matched a documented `scan()` in any other class, and satisfied both
// TypeScriptScanner.scan checks while the real method was missing or carried no
// summary — the gate reporting a contract it had not looked at. The body runs
// from the declaration to the first top-level `}`, which is where the
// repository's formatter puts the end of a top-level class; an empty string
// when the class is gone entirely, so that fails the checks rather than
// skipping them.
$scannerClass = preg_match('/^export class TypeScriptScanner\b[^\n]*\{\n([\s\S]*?)\n\}$/m', $javascript, $classBody) === 1
    ? $classBody[1]
    : '';
$scanPattern = '/\/\*\*((?:(?!\*\/)[\s\S])*?)\*\/\s*scan\s*\(/';
$configPattern = '/\/\*\*((?:(?!\*\/)[\s\S])*?)\*\/\s*export function discoverConfigFiles\s*\(/';
foreach ([
    'TypeScriptScanner' => [$javascript, '/\/\*\*((?:(?!\*\/)[\s\S])*?)\*\/\s*export class TypeScriptScanner/'],
    'TypeScriptScanner.scan' => [$scannerClass, $scanPattern],
    'discoverConfigFiles' => [$javascript, $configPattern],
] as $symbol => [$subject, $pattern]) {
    if (preg_match($pattern, $subject, $documentation) !== 1 || !hasSummary($documentation[1])) {
        $failures[] = $symbol . ' requires a descriptive JSDoc summary';
    }
    $checked[] = 'javascript:' . $symbol;
}
foreach ([
    'TypeScriptScanner.scan' => [$scannerClass, $scanPattern],
    'discoverConfigFiles' => [$javascript, $configPattern],
] as $symbol => [$subject, $pattern]) {
    if (preg_match($pattern, $subject, $documentation) !== 1 || !str_contains($documentation[1], '@param') || !str_contains($documentation[1], '@returns')) {
        $failures[] = $symbol . ' requires JSDoc parameter and return contracts';
    }
}

// The worker script keeps the protocol (`scan`, `handle`); the collector it
// drives lives in the package beside it.
$python = (string) file_get_contents($root . '/workers/python/bin/worker.py') . (string) file_get_contents($root . '/workers/python/bin/knossos_python/collector.py');
foreach (['PythonAstFactCollector' => 'class', 'scan' => 'def', 'handle' => 'def'] as $symbol => $kind) {
    $pattern = '/^' . $kind . '\s+' . preg_quote($symbol, '/') . '\b[^\n]*:\n\s+"""[^"\n]+"""/m';
    if (preg_match($pattern, $python) !== 1) {
        $failures[] = $symbol . ' requires an immediate Python docstring summary';
    }
    $checked[] = 'python:' . $symbol;
}

// The wire DTOs the module doc calls out ("Serde mirrors of the DTOs under
// src/Scanner/Protocol"). Each is a single `///` line directly above a derive
// attribute directly above `pub struct Name`, so the pattern tolerates exactly
// one attribute line between the doc comment and the declaration.
$rust = (string) file_get_contents($root . '/workers/rust/src/protocol.rs');
foreach (['Manifest', 'Evidence', 'Node', 'Edge', 'Diagnostic', 'Contribution'] as $symbol) {
    $pattern = '/\/\/\/[^\n]+\n#\[[^\n]*]\n\s*pub struct ' . preg_quote($symbol, '/') . '\b/';
    if (preg_match($pattern, $rust) !== 1) {
        $failures[] = $symbol . ' requires an immediate Rust doc comment summary';
    }
    $checked[] = 'rust:' . $symbol;
}

$report = ['schema_version' => 1, 'documented_contracts' => count($checked) - count($failures), 'checked_contracts' => count($checked), 'failures' => $failures, 'contracts' => $checked];
writeJsonReport($root . '/coverage/quality/api-documentation.json', $report, 'API documentation report');
if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
printf("API documentation passed: %d PHP/JavaScript/Python/Rust contracts.\n", count($checked));
/** Whether a docblock says anything beyond annotations. Mirrored in tools/docstring-report.php, deliberately: two gates disagreeing about what counts as documented would make both untrustworthy. */

function hasSummary(string $documentation): bool
{
    foreach (preg_split('/\R/', $documentation) ?: [] as $line) {
        $line = trim($line, " \t*");
        if ($line !== '' && !str_starts_with($line, '@')) {
            return true;
        }
    }
    return false;
}

<?php

declare(strict_types=1);

require __DIR__ . '/lib/git-ignore.php';

$root = dirname(__DIR__);
require_once __DIR__ . '/lib/cli-arguments.php';
// $argv is only a global where register_argc_argv is on (see tools/lib/cli-arguments.php).
$argv = cliArguments();
foreach ($argv as $argument) {
    // A throwaway tree, so the checker itself can be tested against fixtures.
    if (str_starts_with($argument, '--root=')) {
        $root = rtrim(substr($argument, 7), '/');
    }
}
$checkExternal = in_array('--external', $argv, true);

/**
 * Hosts whose URLs are never fetched by --external.
 *
 * These serve status badges and the pages behind them. A badge is decoration
 * whose liveness is the badge service's problem, not documentation a reader
 * navigates to, so its availability must not decide whether this repository's
 * quality gate passes. mcpobservatory.com reset the connection to a GitHub
 * runner once and failed the whole gate on a green tree; img.shields.io serves
 * eight more badges in README.md and is one outage away from doing the same.
 *
 * This skips the fetch only. Every URL is still collected and counted, and the
 * link syntax around it is still checked.
 */
const UNFETCHED_HOSTS = ['img.shields.io', 'mcpobservatory.com'];
/**
 * The longest a single URL may be waited on across its retries, in seconds, and
 * how many retries it gets.
 */
const RATE_LIMIT_MAX_WAIT = 30;
const RATE_LIMIT_MAX_RETRIES = 2;
/**
 * The directories whose Markdown is checked. Each must exist: a root missing
 * from the tree fails the check by name, so an image that leaves one out (the
 * quality image once left out plugins/) cannot pass without checking it.
 */
const DOCUMENTATION_ROOTS = ['docs', 'skills', 'plugins'];
/**
 * Where a contributor page restates a budget: the page, the budget file, and
 * for each statement a pattern whose capture groups, in order, hold the number
 * every dotted key in the matching entry of the key list holds.
 */
const RESTATED_BUDGETS = [
    'docs/contribute/quality.md' => ['maintainability-budgets.json', [
        ['/no more than (\d+) normalized cross-file duplicate blocks/', [['cross_file_duplicate_blocks']]],
        ['/complexity no higher than (\d+) and function length no\s+higher than (\d+) lines/', [['max_php_function_complexity'], ['max_php_function_lines']]],
        ['/dependency fanout no higher than (\d+)/', [['max_dependency_fanout']]],
        ['/docstring coverage of ([\d.]+)% for `src`, `workers-php`, `tools` and overall/', [['min_docstring_coverage.src', 'min_docstring_coverage.workers-php', 'min_docstring_coverage.tools', 'min_docstring_coverage.overall']]],
        ['/type-docblock coverage of ([\d.]+)%/', [['min_type_docblock_coverage']]],
    ]],
    'docs/contribute/coverage.md' => ['coverage-budgets.json', [
        ['/^\| PHP core and PHP scanner\s*\|\s*([\d.]+)%\s*\|/m', [['php.aggregate_lines']]],
        ['/^\| TypeScript\/JavaScript\s*\|\s*([\d.]+)%\s*\|\s*([\d.]+)%\s*\|\s*([\d.]+)% functions, ([\d.]+)% statements/m', [['typescript.lines'], ['typescript.branches'], ['typescript.functions'], ['typescript.statements']]],
        ['/^\| Python scanner\s*\|\s*([\d.]+)%\s*\|/m', [['python.lines']]],
        ['/^\| Rust scanner\s*\|\s*([\d.]+)%\s*\|/m', [['rust.lines']]],
        ['/^\| Claude Code mod\s*\|\s*([\d.]+)%\s*\|\s*([\d.]+)%\s*\|\s*([\d.]+)% functions, ([\d.]+)% statements/m', [['mod.lines'], ['mod.branches'], ['mod.functions'], ['mod.statements']]],
        ['/The budget is (\d+), the count/', [['skipped_tests']]],
    ]],
];
$failures = [];
$paths = array_values(array_filter([$root . '/README.md', $root . '/CONTRIBUTING.md'], 'is_file'));
foreach (DOCUMENTATION_ROOTS as $documentationRoot) {
    if (!is_dir($root . '/' . $documentationRoot)) {
        $failures[] = 'missing documentation root ' . $documentationRoot . '/';
        continue;
    }
    $paths = array_merge($paths, documentationFiles($root, $documentationRoot));
}
$headingSlugs = [];
$external = [];
foreach ($paths as $path) {
    $contents = (string) file_get_contents($path);
    preg_match_all('/```(?:sh|shell|bash)\n(.*?)```/s', $contents, $shellBlocks);
    foreach ($shellBlocks[1] as $shellBlock) {
        preg_match_all('#^\s*(?:php\s+)?((?:tools|bin)/[A-Za-z0-9._/-]+)#m', $shellBlock, $commands);
        foreach ($commands[1] as $commandPath) {
            $commandPath = rtrim($commandPath, '.,;:');
            if (!file_exists($root . '/' . $commandPath)) {
                $failures[] = relative($root, $path) . ': missing local command ' . $commandPath;
            }
        }
    }
    // `](target)` matches links and images alike, and both halves of the nested
    // `[![alt](image)](href)` badge form. Fenced code is not prose.
    $prose = implode("\n", linesOutsideFences($contents));
    preg_match_all('/]\(([^) ]+)(?:\s+"[^"]*")?\)/', $prose, $matches);
    foreach ($matches[1] as $target) {
        $target = trim($target, '<>');
        if (str_starts_with($target, 'https://')) {
            $external[$target] = true;
            continue;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) === 1) {
            $failures[] = relative($root, $path) . ': unsupported or insecure link ' . $target;
            continue;
        }
        $parts = explode('#', $target, 2);
        $file = rawurldecode($parts[0]);
        $fragment = isset($parts[1]) ? rawurldecode($parts[1]) : '';
        $resolved = $file === '' ? $path : dirname($path) . '/' . $file;
        if ($file !== '' && !file_exists($resolved)) {
            $failures[] = relative($root, $path) . ': missing link target ' . $target;
            continue;
        }
        if ($fragment === '' || !is_file($resolved) || strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) !== 'md') {
            continue;
        }
        $headingSlugs[$resolved] ??= headingSlugs((string) file_get_contents($resolved));
        // `#L12` and `#L12-L20` point at a line, which GitHub resolves itself.
        if (preg_match('/^L\d+(?:-L\d+)?$/', $fragment) !== 1 && !isset($headingSlugs[$resolved][strtolower($fragment)])) {
            $failures[] = relative($root, $path) . ': missing anchor ' . $target;
        }
    }
}
$failures = [...$failures, ...restatedBudgetDrift($root)];
$fetched = array_values(array_filter(array_keys($external), static function (string $url): bool {
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));

    return !in_array($host, UNFETCHED_HOSTS, true);
}));
$warnings = [];
if ($checkExternal) {
    foreach ($fetched as $url) {
        $outcome = checkExternalUrl($url);
        if ($outcome['status'] === 'unavailable') {
            $failures[] = 'unable to start external link checker';
            break;
        }
        if ($outcome['status'] === 'failed') {
            $failures[] = 'external link failed: ' . $url . ' (' . $outcome['detail'] . ')';
        } elseif ($outcome['status'] === 'rate-limited') {
            $warnings[] = 'warning: external link rate-limited and not checked: ' . $url . ' (' . $outcome['detail'] . ')';
        }
    }
}
foreach ($warnings as $warning) {
    fwrite(STDERR, $warning . PHP_EOL);
}
if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
printf(
    "Documentation links passed: %d files, %d external%s%s.\n",
    count($paths),
    count($external),
    $checkExternal ? ' checked' : ' syntax-checked',
    $checkExternal && count($fetched) !== count($external)
        ? sprintf(' (%d badge-host URLs not fetched)', count($external) - count($fetched))
        : '',
);

/**
 * Every Markdown file under docs/ that this repository actually carries.
 *
 * Git-ignored files are skipped. `/docs/superpowers/` is ignored on purpose —
 * ".gitignore" calls it "Local-only superpowers specs and plans" — so a
 * developer's private planning notes used to be link-checked as though they
 * were published documentation, and one stale link in a scratch file failed the
 * whole suite for everyone who happened to have that file on disk. The check
 * exists to keep SHIPPED docs honest; it has no claim on a working copy.
 *
 * Untracked-but-not-ignored files are still checked: a doc added in the working
 * tree and not yet committed is on its way into the repository, and skipping it
 * would let a broken link land.
 *
 * Run once per documentation root (`docs`, `skills`, `plugins`), so a link in a
 * skill or plugin copy is held to the same standard as one in the manual.
 *
 * @return list<string>
 */
function documentationFiles(string $root, string $directory): array
{
    $directory = $root . '/' . $directory;
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'md') {
            continue;
        }
        $path = str_replace('\\', '/', $file->getPathname());
        if (str_contains($path, '/node_modules/')) {
            continue;
        }
        $files[] = $path;
    }
    sort($files, SORT_STRING);
    $ignored = gitIgnoredPaths($root, $files);

    return array_values(array_filter($files, static fn(string $path): bool => !isset($ignored[$path])));
}

/**
 * The anchors a Markdown page offers, by GitHub's rules, as a set keyed by slug.
 *
 * Only ATX headings outside fenced code count. The slug is the heading text
 * lower-cased, with everything but letters, digits, underscores, spaces and
 * hyphens removed and spaces turned into hyphens. A repeated slug gets `-1`,
 * `-2` and so on.
 *
 * @return array<string, true>
 */
function headingSlugs(string $contents): array
{
    $slugs = [];
    $seen = [];
    foreach (linesOutsideFences($contents) as $line) {
        if (preg_match('/^ {0,3}#{1,6}[ \t]+(.*?)(?:[ \t]+#+)?[ \t]*$/', $line, $heading) !== 1) {
            continue;
        }
        $text = (string) preg_replace('/!?\[([^]]*)]\([^)]*\)/', '$1', $heading[1]);
        $text = strtolower(str_replace('`', '', $text));
        $slug = str_replace(' ', '-', (string) preg_replace('/[^\p{L}\p{N}_ -]/u', '', $text));
        $count = $seen[$slug] ?? 0;
        $seen[$slug] = $count + 1;
        $slugs[$count === 0 ? $slug : $slug . '-' . $count] = true;
    }

    return $slugs;
}

/** A path shown relative to the repository root, so failures name what a reader can find. */
function relative(string $root, string $path): string
{
    return str_replace($root . '/', '', $path);
}

/**
 * The lines of a Markdown page that are not fenced code, by the CommonMark rules.
 *
 * An opener is up to three spaces of indent and a run of three or more backticks
 * or tildes; a backtick opener's info string may not hold a backtick, which is
 * what keeps an inline "```code``` is a thing" in prose. The closer uses the same
 * character, is at least as long as the opener and has no info string. A fence
 * that never closes runs to the end of the file. The fence lines are dropped too.
 *
 * @return list<string>
 */
function linesOutsideFences(string $contents): array
{
    $kept = [];
    $char = null;
    $length = 0;
    foreach (preg_split('/\R/', $contents) ?: [] as $line) {
        if ($char === null) {
            if (preg_match('/^ {0,3}(`{3,}|~{3,})(.*)$/', $line, $open) === 1 && !($open[1][0] === '`' && str_contains($open[2], '`'))) {
                $char = $open[1][0];
                $length = strlen($open[1]);
                continue;
            }
            $kept[] = $line;
            continue;
        }
        if (preg_match('/^ {0,3}(`{3,}|~{3,})[ \t]*$/', $line, $close) === 1 && $close[1][0] === $char && strlen($close[1]) >= $length) {
            $char = null;
        }
    }

    return $kept;
}

/**
 * Fetch one external URL's headers and say whether the link is alive.
 *
 * A 429, or a 503 that sends Retry-After, means "try later", so it is retried
 * (at most RATE_LIMIT_MAX_RETRIES times, honouring Retry-After, never waiting
 * more than RATE_LIMIT_MAX_WAIT seconds in total). If it persists the URL is
 * reported as `rate-limited`, which the caller warns about instead of failing:
 * GitHub answers a shared CI runner with 429 on a page that is perfectly fine.
 * A 404, a 410, any other 4xx/5xx, a DNS failure or a TLS failure is `failed`.
 *
 * The curl binary can be replaced with KNOSSOS_CURL, so tests never touch the
 * network.
 *
 * @return array{status: 'ok'|'failed'|'rate-limited'|'unavailable', detail: string}
 */
function checkExternalUrl(string $url): array
{
    $curl = getenv('KNOSSOS_CURL');
    $curl = is_string($curl) && $curl !== '' ? $curl : 'curl';
    $waited = 0;
    for ($attempt = 0;; ++$attempt) {
        // No --fail: it turns every HTTP error into exit 22 and hides which one.
        // The headers go to stdout so Retry-After is readable, the code follows.
        $process = proc_open(
            [$curl, '--silent', '--show-error', '--location', '--head', '--max-time', '20', '--dump-header', '-', '--output', '/dev/null', '--write-out', "\n%{http_code}", $url],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            return ['status' => 'unavailable', 'detail' => ''];
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $error = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $status = (int) substr(strrchr("\n" . $stdout, "\n"), 1);
        if ($exit !== 0 || $status === 0) {
            return ['status' => 'failed', 'detail' => $error !== '' ? $error : 'curl exit ' . $exit];
        }
        if ($status < 400) {
            return ['status' => 'ok', 'detail' => ''];
        }
        $retryAfter = null;
        // --location prints one header block per hop; only the last one counts.
        $blocks = preg_split('/\r?\n\r?\n/', trim((string) substr($stdout, 0, (int) strrpos($stdout, "\n"))));
        $lastBlock = is_array($blocks) ? (string) end($blocks) : '';
        if (preg_match('/^retry-after:\s*(\d+)\s*$/mi', $lastBlock, $header) === 1) {
            $retryAfter = (int) $header[1];
        }
        if ($status !== 429 && !($status === 503 && $retryAfter !== null)) {
            return ['status' => 'failed', 'detail' => 'HTTP ' . $status . ($error !== '' ? ': ' . $error : '')];
        }
        $delay = $retryAfter ?? (1 << $attempt);
        if ($attempt >= RATE_LIMIT_MAX_RETRIES || $waited + $delay > RATE_LIMIT_MAX_WAIT) {
            return ['status' => 'rate-limited', 'detail' => 'HTTP ' . $status . ' after ' . ($attempt + 1) . ' attempt(s)'];
        }
        sleep($delay);
        $waited += $delay;
    }
}

/**
 * Every number a contributor page states for a budget that the budget file
 * does not hold, and every statement the page no longer makes. The PHP
 * component floors are compared as a whole table, both ways. A tree that lacks
 * the page or the file (a test fixture) has nothing to compare.
 *
 * @return list<string>
 */
function restatedBudgetDrift(string $root): array
{
    $failures = [];
    foreach (RESTATED_BUDGETS as $page => [$file, $statements]) {
        if (!is_file($root . '/' . $page) || !is_file($root . '/' . $file)) {
            continue;
        }
        $contents = (string) file_get_contents($root . '/' . $page);
        $budgets = json_decode((string) file_get_contents($root . '/' . $file), true);
        if (!is_array($budgets)) {
            $failures[] = $file . ': not a JSON object';
            continue;
        }
        foreach ($statements as [$pattern, $keys]) {
            if (preg_match($pattern, $contents, $match) !== 1) {
                $failures[] = sprintf('%s: no longer states %s; update the page or RESTATED_BUDGETS in tools/documentation-check.php', $page, implode(', ', array_merge(...$keys)));
                continue;
            }
            foreach ($keys as $group => $sameKeys) {
                foreach ($sameKeys as $key) {
                    $value = budgetValueAt($budgets, $key);
                    if (!is_int($value) && !is_float($value) || abs((float) $match[$group + 1] - $value) > 1e-9) {
                        $failures[] = sprintf('%s: states %s for %s, %s holds %s', $page, $match[$group + 1], $key, $file, json_encode($value));
                    }
                }
            }
        }
        if ($page === 'docs/contribute/coverage.md') {
            $failures = [...$failures, ...componentFloorDrift($page, $contents, $file, $budgets)];
        }
    }

    return $failures;
}

/**
 * The PHP component floor table on the coverage page against `php.components`:
 * the same components, each with the same floor.
 *
 * @param array<mixed> $budgets
 * @return list<string>
 */
function componentFloorDrift(string $page, string $contents, string $file, array $budgets): array
{
    preg_match_all('/^\| `([a-z-]+)`\s*\|\s*([\d.]+)%\s*\|$/m', $contents, $rows, PREG_SET_ORDER);
    $stated = [];
    foreach ($rows as $row) {
        $stated[$row[1]] = (float) $row[2];
    }
    $held = budgetValueAt($budgets, 'php.components');
    $held = is_array($held) ? $held : [];
    $failures = [];
    foreach ($held as $component => $floor) {
        if (!array_key_exists($component, $stated)) {
            $failures[] = sprintf('%s: has no row for the component floor %s in %s', $page, $component, $file);
        } elseif (!is_int($floor) && !is_float($floor) || abs($stated[$component] - $floor) > 1e-9) {
            $failures[] = sprintf('%s: states %s%% for the component %s, %s holds %s', $page, $stated[$component], $component, $file, json_encode($floor));
        }
    }
    foreach (array_diff_key($stated, $held) as $component => $floor) {
        $failures[] = sprintf('%s: states a floor for the component %s, which %s does not have', $page, $component, $file);
    }

    return $failures;
}

/**
 * The value at a dotted key such as `typescript.lines`, or null when any step is missing.
 *
 * @param array<mixed> $budgets
 */
function budgetValueAt(array $budgets, string $key): mixed
{
    $value = $budgets;
    foreach (explode('.', $key) as $step) {
        if (!is_array($value) || !array_key_exists($step, $value)) {
            return null;
        }
        $value = $value[$step];
    }

    return $value;
}

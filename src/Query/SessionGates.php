<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Configuration\ProjectConfiguration;
use Knossos\Configuration\ProjectConfigurationLoader;
use Throwable;

/**
 * The gates a session start should mention: the ones the project declares, or
 * failing that the scripts it already has that could become gates.
 *
 * Read-only like the rest of the session brief. The script names come from
 * `composer.json` and `package.json` at the project root, because the session
 * brief has no discovery units to ask; a file that is missing, oversized or
 * not valid JSON simply contributes no scripts. Script names are filtered to a
 * short, single-line alphabet and lifecycle hooks (`pre-*`, `post-*`, and npm's `pre<x>`/`post<x>`) are not
 * offered as gates. Declared gates need no filtering here: the loader already
 * refuses a gate name outside `[a-z0-9-]` and a command with a control character.
 */
final readonly class SessionGates
{
    /** How many script names the fallback sentence lists. */
    public const MAX_FOUND = 6;

    private const MAX_MANIFEST_BYTES = 1_000_000;

    /** A script name that is safe to put in front of a model: short, one line, no spaces. */
    private const SCRIPT_NAME = '/^[A-Za-z0-9:_.\-@\/]{1,40}$/D';

    /**
     * The declared gates, or when there are none the script names that could become gates.
     *
     * @param array<array-key, mixed> $composerScripts the decoded `scripts` of composer.json
     * @param array<array-key, mixed> $packageScripts the decoded `scripts` of package.json
     * @return array{declared: list<array{name: string, command: string}>, found: list<string>}
     */
    public static function of(ProjectConfiguration $configuration, array $composerScripts, array $packageScripts): array
    {
        $declared = array_map(
            static fn($gate): array => ['name' => $gate->name, 'command' => $gate->command],
            $configuration->gates,
        );
        if ($declared !== []) {
            return ['declared' => $declared, 'found' => []];
        }
        $names = array_values(array_unique([...self::offered($composerScripts, false), ...self::offered($packageScripts, true)]));
        sort($names, SORT_STRING);

        return ['declared' => [], 'found' => array_slice($names, 0, self::MAX_FOUND)];
    }

    /**
     * The script names of one manifest worth offering as gates: a safe name that is not a lifecycle hook.
     *
     * A hyphenated `pre-`/`post-` name is a hook in either manifest. npm also runs
     * `pre<x>` and `post<x>` around a script `<x>` with no hyphen, so in a
     * package.json such a name is dropped when `<x>` is a script of the same
     * manifest; `prepare`, with no script called `pare`, stays.
     *
     * @param array<array-key, mixed> $scripts
     * @return list<string>
     */
    private static function offered(array $scripts, bool $npm): array
    {
        $all = array_map('strval', array_keys($scripts));
        $offered = [];
        foreach ($all as $name) {
            if (preg_match(self::SCRIPT_NAME, $name) !== 1 || str_starts_with($name, 'pre-') || str_starts_with($name, 'post-')) {
                continue;
            }
            if ($npm && self::isNpmHook($name, $all)) {
                continue;
            }
            $offered[] = $name;
        }

        return $offered;
    }

    /**
     * Whether `$name` is `pre<x>` or `post<x>` for a script `<x>` in `$all`.
     *
     * @param list<string> $all
     */
    private static function isNpmHook(string $name, array $all): bool
    {
        foreach (['pre', 'post'] as $prefix) {
            if (str_starts_with($name, $prefix) && strlen($name) > strlen($prefix) && in_array(substr($name, strlen($prefix)), $all, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The gates for the project at this root, or none when its configuration
     * cannot be read: a config problem is the scan's to report, not the
     * session hook's.
     *
     * @param \Closure(string, int): (string|false)|null $read reads at most the given
     *   number of bytes of a file, or says it cannot; a seam so the "cannot be read"
     *   branch can be driven where file permissions do not apply (a root user).
     * @return array{declared: list<array{name: string, command: string}>, found: list<string>}
     */
    public static function forRoot(string $root, ?\Closure $read = null): array
    {
        try {
            $configuration = ProjectConfigurationLoader::load($root, [$root]);
        } catch (Throwable) {
            return self::none();
        }

        return self::of(
            $configuration,
            self::scripts($root . '/composer.json', $read),
            self::scripts($root . '/package.json', $read),
        );
    }

    /**
     * The nothing-to-say value: no declared gates and no scripts found.
     *
     * @return array{declared: list<array{name: string, command: string}>, found: list<string>}
     */
    public static function none(): array
    {
        return ['declared' => [], 'found' => []];
    }

    /**
     * The `scripts` object of one manifest, or none when it cannot be read safely.
     *
     * A symlink is not followed, the read stops one byte past the size cap even
     * if the file grew after the check, and a file that vanished or cannot be
     * read gives no names without a warning. A list-valued `scripts` has no
     * names, only positions.
     *
     * @param \Closure(string, int): (string|false)|null $read
     * @return array<array-key, mixed>
     */
    private static function scripts(string $path, ?\Closure $read): array
    {
        if (is_link($path) || !is_file($path)) {
            return [];
        }
        $limit = self::MAX_MANIFEST_BYTES + 1;
        $contents = $read !== null ? $read($path, $limit) : @file_get_contents($path, false, null, 0, $limit);
        if (!is_string($contents) || strlen($contents) > self::MAX_MANIFEST_BYTES) {
            return [];
        }
        $data = json_decode($contents, true);
        $scripts = is_array($data) ? ($data['scripts'] ?? null) : null;

        return is_array($scripts) && !array_is_list($scripts) ? $scripts : [];
    }
}

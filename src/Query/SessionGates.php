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
 * not valid JSON simply contributes no scripts.
 */
final readonly class SessionGates
{
    /** How many script names the fallback sentence lists. */
    public const MAX_FOUND = 6;

    private const MAX_MANIFEST_BYTES = 1_000_000;

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
        $names = array_values(array_unique(array_map('strval', [...array_keys($composerScripts), ...array_keys($packageScripts)])));
        sort($names, SORT_STRING);

        return ['declared' => [], 'found' => array_slice($names, 0, self::MAX_FOUND)];
    }

    /**
     * The gates for the project at this root, or none when its configuration
     * cannot be read: a config problem is the scan's to report, not the
     * session hook's.
     *
     * @return array{declared: list<array{name: string, command: string}>, found: list<string>}
     */
    public static function forRoot(string $root): array
    {
        try {
            $configuration = ProjectConfigurationLoader::load($root, [$root]);
        } catch (Throwable) {
            return ['declared' => [], 'found' => []];
        }

        return self::of(
            $configuration,
            self::scripts($root . '/composer.json'),
            self::scripts($root . '/package.json'),
        );
    }

    /**
     * The `scripts` object of one manifest, or none when it cannot be read safely.
     *
     * @return array<array-key, mixed>
     */
    private static function scripts(string $path): array
    {
        $size = is_file($path) ? filesize($path) : false;
        if (!is_int($size) || $size > self::MAX_MANIFEST_BYTES) {
            return [];
        }
        $contents = file_get_contents($path);
        $data = is_string($contents) ? json_decode($contents, true) : null;
        $scripts = is_array($data) ? ($data['scripts'] ?? null) : null;

        return is_array($scripts) ? $scripts : [];
    }
}

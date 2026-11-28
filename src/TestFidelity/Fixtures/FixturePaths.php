<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Fixtures;

/**
 * Where fixture files are kept: Nemesis's lists, frozen as
 * `src/fixtures/staleFixtures.ts` has them (Decision 6). A JSON or YAML file
 * is a fixture when a directory above it holds fixtures or tests, and it is
 * neither a configuration file by name nor under a tooling directory.
 */
final class FixturePaths
{
    /** The fixture extensions (`staleFixtures.ts:20`). */
    public const EXTENSIONS = ['json', 'yaml', 'yml'];

    /** Directory names that mark data written to be read back by tests (`staleFixtures.ts:28-43`). */
    public const FIXTURE_DIRS = ['fixtures', 'fixture', '__fixtures__', 'testdata', 'test-data', '__snapshots__', 'snapshots', 'cassettes', 'stubs', 'mocks', '__mocks__', 'factories', 'seeds', 'samples'];

    /** Directories that hold tests, whose data files are fixtures too (`staleFixtures.ts:46`). */
    public const TEST_DIRS = ['test', 'tests', 'spec', 'specs', '__tests__'];

    /** Files that are configuration wherever they sit (`staleFixtures.ts:53-87`). */
    public const NEVER_FIXTURE_NAMES = [
        'package.json', 'package-lock.json', 'jsconfig.json', 'composer.json', 'composer.lock', 'bun.lock', 'bun.lockb',
        'deno.json', 'deno.jsonc', 'biome.json', 'renovate.json', 'manifest.json', 'mcp.json', 'settings.json',
        'settings.local.json', 'launch.json', 'extensions.json', 'nest-cli.json', 'angular.json', 'nx.json', 'turbo.json',
        'lerna.json', 'now.json', 'vercel.json', 'netlify.json', 'app.json', 'firebase.json', 'serverless.yml',
        'serverless.yaml', 'pubspec.yaml', 'pubspec.lock', 'codecov.yml', 'codecov.yaml',
    ];

    /** File-name patterns that are configuration wherever they sit (`staleFixtures.ts:89-99`). */
    public const NEVER_FIXTURE_PATTERNS = [
        '/^tsconfig(\..+)?\.json$/i',
        '/\.config\.(json|ya?ml)$/i',
        '/^\.eslintrc(\..+)?$/i',
        '/^\.prettierrc(\..+)?$/i',
        '/^docker-compose(\..+)?\.ya?ml$/i',
        '/^\.?pre-commit-config\.ya?ml$/i',
        '/-lock\.(json|ya?ml)$/i',
        '/^openapi(\..+)?\.(json|ya?ml)$/i',
        '/^swagger(\..+)?\.(json|ya?ml)$/i',
    ];

    /** Directories whose contents are tooling configuration, never fixtures (`staleFixtures.ts:102-111`). */
    public const CONFIG_DIRS = ['.github', '.gitlab', '.circleci', '.vscode', '.idea', '.cursor', '.devcontainer', '.husky'];

    private function __construct() {}

    /** Whether a file's extension is a fixture's, as written (`.JSON` is not one, as in Nemesis). */
    public static function hasFixtureExtension(string $relativePath): bool
    {
        return in_array(pathinfo($relativePath, PATHINFO_EXTENSION), self::EXTENSIONS, true);
    }

    /** Whether a project-relative JSON or YAML file sits where fixtures are kept (`staleFixtures.ts:114-123`). */
    public static function isFixturePath(string $relativePath): bool
    {
        if (!self::hasFixtureExtension($relativePath)) {
            return false;
        }
        $parts = explode('/', $relativePath);
        $base = strtolower((string) array_pop($parts));
        if (in_array($base, self::NEVER_FIXTURE_NAMES, true)) {
            return false;
        }
        foreach (self::NEVER_FIXTURE_PATTERNS as $pattern) {
            if (preg_match($pattern, $base) === 1) {
                return false;
            }
        }
        $segments = array_map(strtolower(...), $parts);
        if (array_intersect($segments, self::CONFIG_DIRS) !== []) {
            return false;
        }

        return array_intersect($segments, [...self::FIXTURE_DIRS, ...self::TEST_DIRS]) !== [];
    }
}

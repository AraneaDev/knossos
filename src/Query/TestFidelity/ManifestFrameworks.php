<?php

declare(strict_types=1);

namespace Knossos\Query\TestFidelity;

use Knossos\TestFidelity\Fixtures\FixtureFiles;

/**
 * The mock frameworks a project's manifest names as dev dependencies, the
 * third source of {@see \Knossos\TestFidelity\Synthesis\FrameworkChoice}:
 * the nearest manifest of the language at or above the symbol's file, read
 * as data with the fixture reader's bounds and containment.
 *
 * - TypeScript: `package.json` `devDependencies`, vitest then jest.
 * - PHP: `composer.json` `require-dev`, `mockery/mockery`, then
 *   `phpspec/prophecy` (or its PHPUnit bridge), then `phpunit/phpunit`.
 * - Python: `pyproject.toml`, else a `requirements*.txt`, naming `pytest-mock`.
 * - Rust: `Cargo.toml` `[dev-dependencies]` naming `mockall`.
 */
final class ManifestFrameworks
{
    /** The manifests read per language, nearest directory first. */
    private const MANIFESTS = [
        'typescript' => ['package.json'],
        'php' => ['composer.json'],
        'python' => ['pyproject.toml', 'requirements-dev.txt', 'requirements_dev.txt', 'requirements-test.txt', 'requirements.txt'],
        'rust' => ['Cargo.toml'],
    ];

    private function __construct() {}

    /**
     * The template frameworks the nearest manifest of `$language` above `$file` names, in preference order.
     *
     * @return list<string>
     */
    public static function near(string $root, string $file, string $language): array
    {
        $directory = dirname($file);
        while (true) {
            foreach (self::MANIFESTS[$language] ?? [] as $manifest) {
                $path = ($directory === '.' || $directory === '' ? '' : $directory . '/') . $manifest;
                $contents = FixtureFiles::read($root, $path);
                if ($contents !== null) {
                    return self::named($language, $contents);
                }
            }
            if ($directory === '.' || $directory === '' || $directory === '/') {
                return [];
            }
            $directory = dirname($directory);
        }
    }

    /**
     * The frameworks one manifest's contents name.
     *
     * @return list<string>
     */
    public static function named(string $language, string $contents): array
    {
        if ($language === 'typescript' || $language === 'php') {
            $decoded = json_decode($contents, true);
            $section = is_array($decoded) ? ($decoded[$language === 'php' ? 'require-dev' : 'devDependencies'] ?? null) : null;
            $packages = is_array($section) ? array_map(static fn(int|string $name): string => strtolower((string) $name), array_keys($section)) : [];
            $wanted = $language === 'php'
                ? ['mockery' => ['mockery/mockery'], 'prophecy' => ['phpspec/prophecy', 'phpspec/prophecy-phpunit'], 'phpunit' => ['phpunit/phpunit']]
                : ['vitest' => ['vitest'], 'jest' => ['jest', '@jest/globals']];
            $found = [];
            foreach ($wanted as $framework => $names) {
                if (array_intersect($names, $packages) !== []) {
                    $found[] = $framework;
                }
            }

            return $found;
        }
        if ($language === 'python') {
            return preg_match('/(?<![\w-])pytest[-_]mock(?![\w-])/i', $contents) === 1 ? ['pytest-mock'] : [];
        }

        return self::cargoDevDependency($contents, 'mockall') ? ['mockall'] : [];
    }

    /** Whether a `Cargo.toml` names `$crate` in a `[dev-dependencies]` table, of any target. */
    private static function cargoDevDependency(string $contents, string $crate): bool
    {
        $inDev = false;
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);
            if (preg_match('/^\[([^\]]+)\]$/', $line, $header) === 1) {
                $table = trim($header[1]);
                if (preg_match('/(?:^|\.)dev-dependencies\.' . preg_quote($crate, '/') . '$/', $table) === 1) {
                    return true;
                }
                $inDev = preg_match('/(?:^|\.)dev-dependencies$/', $table) === 1;
            } elseif ($inDev && preg_match('/^' . preg_quote($crate, '/') . '\s*=/', $line) === 1) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace KnossosPhpScanner\TestFacts;

/**
 * Where a class a test names lives: the project file composer's PSR-4 maps
 * place it in, when that file exists and is product code rather than a test
 * module or a dependency, and whether it is no code under test at all,
 * being PHP's own or a test framework's.
 *
 * Reads the nearest `composer.json` at or above the test file, up to the
 * scanned root. Parses only: no autoloader is run, and no class is loaded.
 */
final class ProjectClasses
{
    /** Namespaces of the test frameworks: their classes are helpers, never the code under test. */
    private const FRAMEWORKS = ['phpunit\\', 'mockery\\', 'prophecy\\', 'pest\\', 'sebastianbergmann\\'];

    /** @var array<string, self> by the directory of the composer.json read, or the root when there is none */
    private static array $cache = [];

    /** @var array<string, string|false> by lower-case class name, the module each lands in */
    private array $modules = [];

    /**
     * @param string $root the scanned root, absolute
     * @param list<array{string, string}> $prefixes namespace prefix and root-relative directory, longest prefix first
     */
    private function __construct(private readonly string $root, private readonly array $prefixes) {}

    /** Drop every composer.json and class location read so far, so the next request reads the tree as it is now. */
    public static function forget(): void
    {
        self::$cache = [];
    }

    /** The project's classes as seen from a test file: by the nearest composer.json between it and the root. */
    public static function of(string $root, string $relativePath): self
    {
        $root = rtrim($root, '/');
        $directory = dirname($relativePath);
        for (;;) {
            $base = $directory === '.' ? $root : $root . '/' . $directory;
            if (is_file($base . '/composer.json') || $directory === '.') {
                return self::$cache[$base] ??= new self($root, self::prefixes($base, $directory === '.' ? '' : $directory . '/'));
            }
            $directory = dirname($directory);
        }
    }

    /**
     * The canonical name of the project module a class is declared in, its
     * root-relative path; null when no PSR-4 map places it in a product file.
     */
    public function moduleOf(string $class): ?string
    {
        $key = strtolower(ltrim($class, '\\'));
        if (!isset($this->modules[$key])) {
            $this->modules[$key] = self::find($this->root, $this->prefixes, ltrim($class, '\\')) ?? false;
        }
        $module = $this->modules[$key];

        return $module === false ? null : $module;
    }

    /** Whether a class is a test module of the project, so a call into it runs no product code. */
    public function isTestClass(string $class): bool
    {
        $file = self::find($this->root, $this->prefixes, ltrim($class, '\\'), true);

        return $file !== null && TestModulePath::isTest($file);
    }

    /** Whether a class is PHP's own or a test framework's: never the code under test. */
    public static function isOutsideTheProject(string $class): bool
    {
        $class = ltrim($class, '\\');
        $lower = strtolower($class);
        if ($lower === 'mockery' || $lower === 'closure') {
            return true;
        }
        foreach (self::FRAMEWORKS as $namespace) {
            if (str_starts_with($lower, $namespace)) {
                return true;
            }
        }
        if (!class_exists($class, false) && !interface_exists($class, false) && !enum_exists($class, false)) {
            return false;
        }

        return (new \ReflectionClass($class))->isInternal();
    }

    /** Whether a function is one of PHP's own, which runs no project code. */
    public static function isInternalFunction(string $function): bool
    {
        $function = ltrim($function, '\\');

        return function_exists($function) && (new \ReflectionFunction($function))->isInternal();
    }

    /**
     * The file a class's name maps to, root-relative, when it exists: a
     * product file, or with `$anyFile` a test file too.
     *
     * @param list<array{string, string}> $prefixes
     */
    private static function find(string $root, array $prefixes, string $class, bool $anyFile = false): ?string
    {
        foreach ($prefixes as [$prefix, $directory]) {
            if ($prefix !== '' && !str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = self::normalise($directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php');
            if ($relative === null || !is_file($root . '/' . $relative)) {
                continue;
            }
            if ($anyFile || (!TestModulePath::isTest($relative) && !str_starts_with($relative, 'vendor/') && !str_contains($relative, '/vendor/'))) {
                return $relative;
            }
        }

        return null;
    }

    /** A root-relative path without `.` and `..` segments; null when it climbs out of the root. */
    private static function normalise(string $path): ?string
    {
        $kept = [];
        foreach (array_filter(explode('/', $path), static fn(string $part): bool => $part !== '' && $part !== '.') as $part) {
            if ($part !== '..') {
                $kept[] = $part;
            } elseif (array_pop($kept) === null) {
                return null;
            }
        }

        return implode('/', $kept);
    }

    /**
     * The PSR-4 prefixes of a composer.json, `autoload` and `autoload-dev`,
     * longest first, each directory relative to the root.
     *
     * @return list<array{string, string}>
     */
    private static function prefixes(string $base, string $relativeBase): array
    {
        $decoded = is_file($base . '/composer.json') ? json_decode((string) file_get_contents($base . '/composer.json'), true) : null;
        $prefixes = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            $map = is_array($decoded) && is_array($decoded[$section]['psr-4'] ?? null) ? $decoded[$section]['psr-4'] : [];
            foreach ($map as $prefix => $directories) {
                foreach ((array) $directories as $directory) {
                    if (is_string($prefix) && is_string($directory)) {
                        $prefixes[] = [$prefix, $relativeBase . rtrim($directory, '/') . ($directory === '' ? '' : '/')];
                    }
                }
            }
        }
        usort($prefixes, static fn(array $a, array $b): int => strlen($b[0]) <=> strlen($a[0]));

        return $prefixes;
    }
}

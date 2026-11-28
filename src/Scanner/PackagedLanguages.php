<?php

declare(strict_types=1);

namespace Knossos\Scanner;

/**
 * The languages Knossos ships a worker for: each worker's scanner id, the
 * prefix of the local ids it emits, and the language's name.
 *
 * One table, read by the bundle importer (which labels a node by its owner's
 * scanner) and by the test-fidelity reports (which name a node's language),
 * so the two cannot disagree. Kept below the analysis layers, as a bundle may
 * not depend on a query.
 */
final class PackagedLanguages
{
    /** Scanner id => the local-id prefix its nodes carry and the language's name. */
    private const WORKERS = [
        'knossos.php' => ['prefix' => 'php', 'language' => 'php'],
        'knossos.python' => ['prefix' => 'py', 'language' => 'python'],
        'knossos.rust' => ['prefix' => 'rs', 'language' => 'rust'],
        'knossos.typescript' => ['prefix' => 'ts', 'language' => 'typescript'],
    ];

    private function __construct() {}

    /**
     * Every packaged language's name, in name order.
     *
     * @return list<string>
     */
    public static function languages(): array
    {
        return array_values(array_column(self::WORKERS, 'language'));
    }

    /** The local-id prefix a packaged scanner's nodes carry; null for any other scanner. */
    public static function prefixOf(string $scanner): ?string
    {
        return self::WORKERS[$scanner]['prefix'] ?? null;
    }

    /** The language a node's language label names: the packaged language whose prefix it is, else the label itself. */
    public static function languageOf(string $label): string
    {
        foreach (self::WORKERS as $worker) {
            if ($worker['prefix'] === $label) {
                return $worker['language'];
            }
        }

        return $label;
    }

    /** The scanner id of a packaged language's worker; the language itself for any other. */
    public static function scannerOf(string $language): string
    {
        foreach (self::WORKERS as $scanner => $worker) {
            if ($worker['language'] === $language) {
                return $scanner;
            }
        }

        return $language;
    }
}

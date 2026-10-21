<?php

declare(strict_types=1);

namespace Knossos\Query;

use JsonException;
use Knossos\Store\SharedNodeKinds;
use Knossos\Store\SnapshotPayload;
use PDO;
use PDOException;
use PhpToken;
use ReflectionClass;

/**
 * The metrics and fact counts of retained snapshots, computed once and kept.
 *
 * A retained snapshot's archive never changes, so what was computed from it
 * holds for as long as the archive does: `snapshot_metrics` keeps it, and the
 * archive's deletion deletes it. A row answers only while the archive's
 * `captured_at` and `byte_size` and the {@see self::fingerprint()} of the code
 * that computed it all still match; anything else is a miss, recomputed by the
 * caller. Never the active snapshot: its facts are the live tables.
 *
 * Writing is best effort and never waits: a reader (the dashboard) must not
 * stall behind a scan that holds the write lock, so a store that would block
 * or fail is dropped and the next call computes again.
 */
final readonly class SnapshotMetricsCache
{
    /**
     * The classes whose code decides the stored figures: resolving the
     * snapshot and reading its archive, computing the metrics, and the trend
     * that asks for them and stores what comes back. The fingerprint covers
     * these and every class they reach in {@see self::WALKED}, see
     * {@see self::sources()}.
     */
    private const SOURCES = [
        QualityGateQueryService::class, SnapshotMetrics::class, SnapshotResolver::class, AbstractArchitectureQueryService::class,
        ReportableComponent::class, SnapshotPayload::class, SnapshotGraphReader::class, ErasedTypeEdge::class, RenameMatching::class,
        SharedNodeKinds::class, OneTypeCycles::class,
    ];

    /** The namespaces whose classes the walk from {@see self::SOURCES} follows. */
    private const WALKED = ['Knossos\\Query\\', 'Knossos\\Store\\'];

    /** The code the stored figures must come from to answer. */
    private string $fingerprint;

    /**
     * @param PDO $pdo a migrated graph database
     * @param string|null $fingerprint the code the figures come from; defaults to {@see self::fingerprint()}
     */
    public function __construct(private PDO $pdo, ?string $fingerprint = null)
    {
        $this->fingerprint = $fingerprint ?? self::fingerprint();
    }

    /**
     * A hash of the source files that compute the stored figures, so a change
     * to how a metric is computed never serves figures the old code computed.
     */
    public static function fingerprint(): string
    {
        $hashes = '';
        foreach (self::sources() as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            $hashes .= $file === false ? $class : (hash_file('sha256', $file) ?: $class);
        }
        return hash('sha256', $hashes);
    }

    /**
     * {@see self::SOURCES} and every class, interface, trait or enum in
     * {@see self::WALKED} their code names, transitively, sorted: a helper one
     * of them starts calling changes the figures as much as an edit to the
     * caller does. Walked once per process.
     *
     * @return list<class-string>
     */
    public static function sources(): array
    {
        static $sources = null;
        if ($sources !== null) {
            return $sources;
        }
        $seen = [];
        $queue = self::SOURCES;
        while ($queue !== []) {
            $class = (new ReflectionClass(array_shift($queue)))->getName();
            if (isset($seen[$class])) {
                continue;
            }
            $seen[$class] = true;
            $file = (new ReflectionClass($class))->getFileName();
            foreach ($file === false ? [] : self::namedClasses($file) as $named) {
                if (!isset($seen[$named]) && self::walked($named)) {
                    $queue[] = $named;
                }
            }
        }
        ksort($seen);

        return $sources = array_keys($seen);
    }

    /** Whether `$name` is a class-like in {@see self::WALKED} that loads. */
    private static function walked(string $name): bool
    {
        foreach (self::WALKED as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name);
            }
        }

        return false;
    }

    /**
     * The fully qualified names `$file`'s code could mean by each capitalised
     * name in it: imports, qualified names, and bare names resolved through
     * the imports or else the file's own namespace. Comments name nothing.
     *
     * @return list<string>
     */
    private static function namedClasses(string $file): array
    {
        $tokens = PhpToken::tokenize((string) file_get_contents($file));
        $namespace = '';
        $imports = [];
        $names = [];
        $depth = 0;
        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token->is(T_NAMESPACE)) {
                $namespace = self::statement($tokens, $i, true);
            } elseif ($token->is(T_USE) && $depth === 0) {
                $imports += self::imports(self::statement($tokens, $i, false));
            } elseif ($token->is(T_NAME_FULLY_QUALIFIED)) {
                $names[] = ltrim($token->text, '\\');
            } elseif ($token->is([T_NAME_QUALIFIED, T_STRING]) && ctype_upper($token->text[0])) {
                $first = strtok($token->text, '\\');
                $names[] = isset($imports[$first])
                    ? $imports[$first] . substr($token->text, strlen($first))
                    : $namespace . '\\' . $token->text;
            } elseif ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
            }
        }

        return array_values(array_unique([...$names, ...array_values($imports)]));
    }

    /**
     * The text of the statement after the keyword at `$i`, spaced only
     * between words, up to its `;` (or, for a `$block` namespace, its `{`),
     * moving `$i` there.
     *
     * @param list<PhpToken> $tokens
     */
    private static function statement(array $tokens, int &$i, bool $block): string
    {
        $text = '';
        for ($i++; isset($tokens[$i]) && $tokens[$i]->text !== ';' && !($block && $tokens[$i]->text === '{'); $i++) {
            if (!$tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                $text .= $tokens[$i]->text . ' ';
            }
        }

        return trim((string) preg_replace('/\s*([\\\\{},])\s*/', '$1', $text));
    }

    /**
     * The aliases a top-level `use` statement imports, mapped to the names
     * they stand for; a function or constant import maps nothing.
     *
     * @return array<string, string>
     */
    private static function imports(string $statement): array
    {
        if (preg_match('/^(function|const) /', $statement) === 1) {
            return [];
        }
        $prefix = '';
        if (preg_match('/^(.*)\\\\\{(.*)\}$/', $statement, $group) === 1) {
            [$prefix, $statement] = [$group[1] . '\\', $group[2]];
        }
        $imports = [];
        foreach (array_filter(explode(',', $statement)) as $part) {
            $pieces = explode(' as ', $part);
            $name = ltrim($prefix . $pieces[0], '\\');
            $imports[$pieces[1] ?? substr((string) strrchr('\\' . $name, '\\'), 1)] = $name;
        }

        return $imports;
    }

    /**
     * The stored figures for the archive of `$scanId` captured at `$capturedAt`
     * with `$byteSize` bytes, or null when none match it.
     *
     * @return array{counts: array<string, int>, metrics: array<string, int>}|null
     */
    public function get(string $scanId, string $capturedAt, int $byteSize): ?array
    {
        $statement = $this->pdo->prepare('SELECT fingerprint, captured_at, byte_size, payload_json FROM snapshot_metrics WHERE scan_id = :scan');
        $statement->execute(['scan' => $scanId]);
        $row = $statement->fetch();
        if (!is_array($row) || $row['fingerprint'] !== $this->fingerprint || $row['captured_at'] !== $capturedAt || (int) $row['byte_size'] !== $byteSize) {
            return null;
        }
        try {
            $entry = json_decode((string) $row['payload_json'], true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        return is_array($entry) && is_array($entry['counts'] ?? null) && is_array($entry['metrics'] ?? null)
            ? ['counts' => $entry['counts'], 'metrics' => $entry['metrics']]
            : null;
    }

    /**
     * Stores the figures computed from that archive, replacing any older row,
     * when the database takes the write at once; otherwise stores nothing.
     *
     * @param array{counts: array<string, int>, metrics: array<string, int>} $entry
     */
    public function put(string $scanId, string $capturedAt, int $byteSize, array $entry): void
    {
        $timeout = (int) $this->pdo->query('PRAGMA busy_timeout')->fetchColumn();
        $this->pdo->exec('PRAGMA busy_timeout = 0');
        try {
            $this->pdo->prepare(
                'INSERT OR REPLACE INTO snapshot_metrics(scan_id, fingerprint, captured_at, byte_size, payload_json) VALUES (:scan, :fingerprint, :captured, :size, :payload)',
            )->execute([
                'scan' => $scanId,
                'fingerprint' => $this->fingerprint,
                'captured' => $capturedAt,
                'size' => $byteSize,
                'payload' => json_encode($entry, JSON_THROW_ON_ERROR),
            ]);
        } catch (PDOException) {
            // Locked, read-only, or the archive went in between: the next call computes again.
        } finally {
            $this->pdo->exec('PRAGMA busy_timeout = ' . $timeout);
        }
    }
}

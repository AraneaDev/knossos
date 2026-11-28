<?php

declare(strict_types=1);

namespace Knossos\Query\TestFidelity;

use Closure;
use Knossos\Query\AbstractArchitectureQueryService;
use Knossos\Query\SignatureLine;
use Knossos\Result\ResultEnvelope;
use Knossos\TestFidelity\ActiveTestFindings;
use Knossos\TestFidelity\Facts\StubbedMember;
use Knossos\TestFidelity\FindingConfidence;
use PDO;

/**
 * `verify_test_doubles`: every test double of one class, interface or trait,
 * each with its stored findings and whether it is still valid. The port of
 * Nemesis `verify_symbol` (`src/mcp/server.ts:84-115`; Decision 8).
 *
 * It reads the reverse `doubles` edges and the stored scan-tier findings of
 * each double, and computes nothing new. A double is valid when it carries
 * no definite finding: a warning does not prove it wrong. What the project
 * suppresses is not held.
 *
 * @phpstan-import-type Item from ScanTierItems
 */
final readonly class DoublesOfSymbol extends AbstractArchitectureQueryService
{
    /** The kinds a double stands in for. */
    public const KINDS = ['class', 'interface', 'trait'];

    /** How many doubles one answer lists at most; past them it says it was cut. */
    private const MAX_DOUBLES = 500;

    public function __construct(PDO $pdo, ?Closure $clock, private SymbolResolution $resolution)
    {
        parent::__construct($pdo, $clock);
    }

    /** The doubles of the one type `$symbol` names, in file and line order. */
    public function verify(string $projectId, string $symbol): ResultEnvelope
    {
        $project = $this->project($projectId);
        $node = $this->resolution->resolve($projectId, $symbol, null, self::KINDS)['node'];
        $statement = $this->pdo->prepare(
            'SELECT d.id, d.display_name, d.start_line, d.attributes_json, f.relative_path FROM edges e JOIN nodes d ON d.id = e.source_id LEFT JOIN files f ON f.id = d.file_id '
            . "WHERE e.project_id = :project AND e.kind = 'doubles' AND e.target_id = :target AND d.kind = 'test_double' "
            . 'GROUP BY d.id ORDER BY f.relative_path, d.start_line, d.id LIMIT :cap',
        );
        $statement->bindValue(':project', $projectId);
        $statement->bindValue(':target', $node['id']);
        $statement->bindValue(':cap', self::MAX_DOUBLES + 1, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $truncated = count($rows) > self::MAX_DOUBLES;
        $findings = $this->findingsOf((string) $project['root_realpath'], $projectId, array_column($rows, 'id'));
        $doubles = [];
        foreach (array_slice($rows, 0, self::MAX_DOUBLES) as $row) {
            $attributes = json_decode((string) $row['attributes_json'], true);
            $attributes = is_array($attributes) ? $attributes : [];
            $items = $findings[(string) $row['id']] ?? [];
            $doubles[] = [
                'double' => (string) $row['display_name'],
                'file' => $row['relative_path'] === null ? null : (string) $row['relative_path'],
                'line' => $row['start_line'] === null ? null : (int) $row['start_line'],
                'framework' => is_string($attributes['framework'] ?? null) ? $attributes['framework'] : null,
                'members' => StubbedMember::namesIn($attributes),
                'valid' => !in_array(FindingConfidence::Definite->value, array_column($items, 'confidence'), true),
                'findings' => $items,
            ];
        }
        $invalid = count(array_filter($doubles, static fn(array $double): bool => !$double['valid']));

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('%d double%s of %s; %d invalid.', count($doubles), count($doubles) === 1 ? '' : 's', $node['canonical_name'], $invalid),
            [
                'symbol' => $symbol,
                'resolved' => ['kind' => $node['kind'], 'canonical_name' => $node['canonical_name'], 'language' => $node['language'], 'file' => $node['file'], 'line' => $node['line']],
                'signature' => SignatureLine::of($node['attributes']['signature'] ?? null, $node['display_name']),
                'doubles' => $doubles,
                'summary' => ['doubles' => count($doubles), 'invalid' => $invalid],
            ],
            [],
            $truncated ? [sprintf('More than %d doubles; the first %d are listed.', self::MAX_DOUBLES, self::MAX_DOUBLES)] : [],
            $truncated,
        );
    }

    /**
     * The stored findings of each of `$doubles`, as scan-tier items in the
     * stored order; the store reads only theirs (G6-08c).
     *
     * @param list<mixed> $doubles the doubles' node ids
     * @return array<string, list<Item>>
     */
    private function findingsOf(string $root, string $projectId, array $doubles): array
    {
        $byDouble = [];
        $wanted = ['doubles' => array_values(array_map('strval', $doubles))];
        foreach ((new ActiveTestFindings($this->pdo, $projectId, ActiveTestFindings::suppressionsOf($root)))->find($wanted) as $finding) {
            $byDouble[$finding['double_id']][] = ScanTierItems::of([$finding])[0];
        }

        return $byDouble;
    }
}

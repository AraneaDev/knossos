<?php

declare(strict_types=1);

namespace Knossos\Query\CreationBrief;

use Knossos\Boundary\BoundaryReferences;
use PDO;

/**
 * The declared boundary a path lands in and the boundaries its rules forbid it to use.
 *
 * Only a boundary the project declared with a path prefix can be named for a
 * file that does not exist yet; an inferred one (a package, a namespace) is
 * not, as in the read note. Declared boundaries nest (`src/` inside a project,
 * `src/Query/` inside that), and a file is bound by the rules of every one
 * that contains it, so the boundary named is the most specific (the longest
 * prefix, then the name) and what it may not use is the union over all of them.
 */
final readonly class Placement
{
    public function __construct(private PDO $pdo) {}

    /**
     * The boundary of `$path` and what it may not use.
     *
     * @param list<array<string, mixed>> $policies the project's declared policies
     * @return array{boundary: ?string, may_not_use: list<string>}
     */
    public function of(string $projectId, string $path, array $policies): array
    {
        $statement = $this->pdo->prepare('SELECT id, name, source, matcher_json FROM boundaries WHERE project_id = ? ORDER BY id');
        $statement->execute([$projectId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        /** @var array<string, true> $containing every declared boundary that holds the path */
        $containing = [];
        $best = null;
        foreach ($rows as $row) {
            $matcher = json_decode((string) $row['matcher_json'], true);
            if ($row['source'] !== 'explicit' || !is_array($matcher) || ($matcher['type'] ?? null) !== 'path_prefix' || !is_string($matcher['value'] ?? null) || !str_starts_with($path, $matcher['value'])) {
                continue;
            }
            $containing[(string) $row['name']] = true;
            $key = [-strlen($matcher['value']), (string) $row['name']];
            if ($best === null || $key < $best['key']) {
                $best = ['key' => $key, 'name' => (string) $row['name']];
            }
        }
        if ($best === null) {
            return ['boundary' => null, 'may_not_use' => []];
        }
        $references = BoundaryReferences::fromRows($projectId, $rows);
        $denied = [];
        foreach ($policies as $policy) {
            $from = $policy['from_boundary'] ?? null;
            $fromName = is_string($from) ? $references->nameOf($from) : null;
            if ($fromName === null || !isset($containing[$fromName])) {
                continue;
            }
            foreach (is_array($policy['deny_targets'] ?? null) ? $policy['deny_targets'] : [] as $target) {
                $name = is_string($target) ? $references->nameOf($target) : null;
                if ($name !== null) {
                    $denied[$name] = true;
                }
            }
        }
        $names = array_map('strval', array_keys($denied));
        sort($names, SORT_STRING);

        return ['boundary' => $best['name'], 'may_not_use' => $names];
    }
}

<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

use PDO;

/**
 * The dashboard's `test_findings` section: how many definite findings and
 * warnings the active scan holds, where the definite ones stand in for
 * production code, and the findings themselves, definite first.
 *
 * Bounded so a project with a great many findings cannot slow the pane: the
 * counts are counted, `list` holds the first {@see self::LIST_LIMIT} and
 * `list_truncated` says when it was cut, and the production grouping reads at
 * most {@see self::GROUPED_LIMIT} definite findings (`by_production_file_truncated`).
 * Only definite stale-mock findings ({@see StaleCategories}) are grouped: a
 * warning, or a finding that is not about a stale double, is listed with its
 * confidence and never counted as a double that no longer matches its target.
 * `definite` is every definite finding; `stale` the ones in a stale category.
 */
final readonly class DashboardTestFindings
{
    public const LIST_LIMIT = 200;
    public const GROUPED_LIMIT = 1000;

    /** Reasons listed per production file. */
    private const REASONS = 3;

    /** What each stale category is called in a reason. */
    private const SHORT = [
        'GHOST_MEMBER' => 'ghost',
        'ARITY_MISMATCH' => 'arity',
        'RETURN_DRIFT' => 'return',
    ];

    private function __construct() {}

    /**
     * The section for the project at `$root`: its counts, production grouping and findings list.
     *
     * @return array{definite: int, stale: int, warning: int, by_production_file: array<string, array{doubles: int, target: string, reasons: list<string>, keys: list<string>}>, by_production_file_truncated: bool, list: list<array<string, mixed>>, list_truncated: bool, suppressions_invalid: ?string}
     */
    public static function of(PDO $pdo, string $projectId, string $root): array
    {
        $suppressions = ActiveTestFindings::suppressionsOf($root);
        $findings = new ActiveTestFindings($pdo, $projectId, $suppressions);
        $counts = $findings->countsByConfidenceAndCategory();
        $byCategory = $counts[FindingConfidence::Definite->value] ?? [];
        $definite = array_sum($byCategory);
        $stale = array_sum(array_intersect_key($byCategory, array_flip(StaleCategories::ALL)));
        $warning = array_sum($counts[FindingConfidence::Warning->value] ?? []);
        $definiteRows = $findings->find(['confidence' => FindingConfidence::Definite->value], self::GROUPED_LIMIT);
        $listed = array_slice($definiteRows, 0, self::LIST_LIMIT);
        if (count($listed) < self::LIST_LIMIT) {
            $listed = [...$listed, ...$findings->find(['confidence' => FindingConfidence::Warning->value], self::LIST_LIMIT - count($listed))];
        }

        return [
            'definite' => $definite,
            'stale' => $stale,
            'warning' => $warning,
            'by_production_file' => self::byProductionFile($definiteRows),
            'by_production_file_truncated' => $definite > self::GROUPED_LIMIT,
            'list' => array_map(static fn(array $finding): array => [
                'key' => $finding['key'],
                'category' => $finding['category'],
                'sub_rule' => $finding['sub_rule'],
                'confidence' => $finding['confidence'],
                'message' => $finding['message'],
                'file' => $finding['file'],
                'line' => $finding['line'],
                'target_file' => $finding['target_file'],
            ], $listed),
            'list_truncated' => $definite + $warning > self::LIST_LIMIT,
            'suppressions_invalid' => $suppressions->invalid(),
        ];
    }

    /**
     * The section of a project with no findings, or no scan.
     *
     * @return array{definite: int, stale: int, warning: int, by_production_file: array<string, never>, by_production_file_truncated: bool, list: list<never>, list_truncated: bool, suppressions_invalid: null}
     */
    public static function none(): array
    {
        return ['definite' => 0, 'stale' => 0, 'warning' => 0, 'by_production_file' => [], 'by_production_file_truncated' => false, 'list' => [], 'list_truncated' => false, 'suppressions_invalid' => null];
    }

    /**
     * The definite stale-mock findings grouped by the file of the production code their double replaces.
     *
     * @param list<array<string, mixed>> $findings
     * @return array<string, array{doubles: int, target: string, reasons: list<string>, keys: list<string>}>
     */
    private static function byProductionFile(array $findings): array
    {
        $files = [];
        foreach ($findings as $finding) {
            if ($finding['confidence'] !== FindingConfidence::Definite->value || !StaleCategories::isStale($finding['category']) || $finding['target_file'] === null) {
                continue;
            }
            $file = $finding['target_file'];
            $files[$file]['doubles'][$finding['double_id']] = true;
            $files[$file]['targets'][self::owner($finding)] = ($files[$file]['targets'][self::owner($finding)] ?? 0) + 1;
            $files[$file]['reasons'][self::reason($finding)] = true;
            $files[$file]['keys'][$finding['key']] = true;
        }
        ksort($files, SORT_STRING);
        $grouped = [];
        foreach ($files as $file => $group) {
            $targets = $group['targets'];
            // The most common owner; the first alphabetically when they tie.
            uksort($targets, static fn(string $a, string $b): int => [$targets[$b], $a] <=> [$targets[$a], $b]);
            $reasons = array_map(strval(...), array_keys($group['reasons']));
            sort($reasons, SORT_STRING);
            $keys = array_map(strval(...), array_keys($group['keys']));
            sort($keys, SORT_STRING);
            $grouped[(string) $file] = [
                'doubles' => count($group['doubles']),
                'target' => (string) array_key_first($targets),
                'reasons' => array_slice($reasons, 0, self::REASONS),
                'keys' => $keys,
            ];
        }

        return $grouped;
    }

    /**
     * The short name of the class, interface or module that owns what a finding's double replaces:
     * `PaymentGateway` for `src/payment.ts#PaymentGateway::charge`.
     *
     * @param array<string, mixed> $finding
     */
    private static function owner(array $finding): string
    {
        $canonical = (string) $finding['target_canonical'];
        if (str_contains($canonical, '::')) {
            $type = explode('::', $canonical)[0];

            return substr($type, (int) strrpos($type, '#') + (str_contains($type, '#') ? 1 : 0));
        }

        return (string) $finding['target_name'];
    }

    /**
     * `<member>(): <category>`: the member a double stubs, which is the
     * member the worker recorded on the finding, else the last segment of the target's canonical
     * name when it is a member, else the one the message names; the category alone when none is known.
     *
     * @param array<string, mixed> $finding
     */
    private static function reason(array $finding): string
    {
        $short = self::SHORT[(string) $finding['category']] ?? strtolower((string) $finding['category']);
        $canonical = (string) $finding['target_canonical'];
        $member = $finding['attributes']['member'] ?? null;
        if (!is_string($member) || $member === '') {
            $member = str_contains($canonical, '::') ? substr($canonical, (int) strrpos($canonical, '::') + 2) : null;
        }
        if ($member === null || $member === '') {
            $member = preg_match('/ stubs (\S+?),? which /', (string) $finding['message'], $matches) === 1 ? $matches[1] : null;
        }

        return $member === null ? $short : "{$member}(): {$short}";
    }
}

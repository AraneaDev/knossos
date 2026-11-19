<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

/**
 * Which finding categories mean a stale mock: a double that no longer matches
 * the code it replaces. Every other definite finding is a plain test finding.
 * The one place the list lives; the Claude Code mod reads the `stale` and
 * `total_stale` figures the dashboard and the turn brief carry, and names
 * no category.
 */
final class StaleCategories
{
    /** @var list<string> */
    public const ALL = [
        FindingCategory::GhostMember->value,
        FindingCategory::ArityMismatch->value,
        FindingCategory::ReturnDrift->value,
    ];

    private function __construct() {}

    public static function isStale(string $category): bool
    {
        return in_array($category, self::ALL, true);
    }
}

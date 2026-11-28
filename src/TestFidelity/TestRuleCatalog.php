<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

use Knossos\TestFidelity\Fixtures\FixtureDrift;
use Knossos\TestFidelity\OnRequest\OverMocking;
use Knossos\TestFidelity\Rules\{ArityMismatchRule, EnumCaseCheck, GhostMemberRule, MocksSubjectRule, ReturnDriftRule, TautologyRule, VisibilityBreachRule};

/**
 * Every test-fidelity rule, scan tier and on-request tier: its id, category,
 * tier, the confidences it reports at, its sub-rules and what it finds.
 *
 * The one list `list_test_rules` prints and `audit_tests` validates its
 * `category` and `confidence` against, in the tool's schema and in the
 * service (G1-20). It names the rules by their `ID` constants; no rule reads
 * it (preflight F29). The on-request rules of later phase-6 tasks are listed
 * by their Decision 1 ids until their classes exist.
 *
 * @phpstan-type Rule array{id: string, category: string, tier: string, confidences: list<string>, sub_rules: list<string>, description: string}
 */
final class TestRuleCatalog
{
    /** The tier of the findings a scan judges and stores. */
    public const SCAN = 'scan';

    /** The tier of the findings computed only when asked. */
    public const ON_REQUEST = 'on_request';

    private function __construct() {}

    /**
     * Every rule: the scan tier in {@see FindingCategory} order, then the on-request tier in {@see OnRequestCategory} order.
     *
     * @return list<Rule>
     */
    public static function all(): array
    {
        $either = [FindingConfidence::Definite, FindingConfidence::Warning];
        $warning = [FindingConfidence::Warning];

        return [
            self::rule(GhostMemberRule::ID, FindingCategory::GhostMember, $either, ['missing_member', 'missing_export', 'missing_module'], 'A double stubs a member, export or module its target does not have.'),
            self::rule(ArityMismatchRule::ID, FindingCategory::ArityMismatch, $either, ['call_arity', 'signature', EnumCaseCheck::SUB_RULE], 'A double\'s member takes a different number of arguments than the member it replaces, or a matcher lists a literal that is none of an enum parameter\'s cases.'),
            self::rule(ReturnDriftRule::ID, FindingCategory::ReturnDrift, $either, ['return_type'], 'A double pins a value the replaced member\'s declared return type does not admit.'),
            self::rule(VisibilityBreachRule::ID, FindingCategory::VisibilityBreach, $either, ['visibility'], 'A double replaces a member that is not visible to it.'),
            self::rule(TautologyRule::ID, FindingCategory::Tautology, $either, ['self_comparison', 'mock_echo', 'constant', 'zero_reach', 'unconfigured_spy', 'mock_only', 'vacuous_negation'], 'An assertion that cannot fail.'),
            self::rule(MocksSubjectRule::ID, FindingCategory::MocksSubject, $warning, ['mock_of_self'], 'A test replaces the module it is testing.'),
            self::rule(OverMocking::ID, OnRequestCategory::OverMocking, $warning, [OverMocking::SUB_RULE], 'At least 7 in 10 of a test file\'s dependencies are doubles, and fewer than 2 of its assertions read production code (Momus MOCK-001).'),
            self::rule('test.fidelity.stale_since.v1', OnRequestCategory::StaleSince, $warning, ['stale_mock'], 'A double\'s target changed since a git ref while the double\'s own file did not (scope git-diff; Momus DRIFT-006).'),
            self::rule(FixtureDrift::ID, OnRequestCategory::FixtureDrift, $warning, FixtureDrift::SUB_RULES, 'A JSON or YAML fixture no longer matches the type whose shape it holds (Nemesis stale fixtures).'),
        ];
    }

    /**
     * The categories, in catalogue order; only one tier's when `$tier` is given.
     *
     * @return list<string>
     */
    public static function categories(?string $tier = null): array
    {
        $rules = array_filter(self::all(), static fn(array $rule): bool => $tier === null || $rule['tier'] === $tier);

        return array_values(array_unique(array_column($rules, 'category')));
    }

    /**
     * The confidences a finding may have.
     *
     * @return list<string>
     */
    public static function confidences(): array
    {
        return array_map(static fn(FindingConfidence $case): string => $case->value, FindingConfidence::cases());
    }

    /** The tier a category's findings belong to; null for a category no rule reports. */
    public static function tierOf(string $category): ?string
    {
        foreach (self::all() as $rule) {
            if ($rule['category'] === $category) {
                return $rule['tier'];
            }
        }

        return null;
    }

    /**
     * One rule's row; its tier is its category's.
     *
     * @param list<FindingConfidence> $confidences
     * @param list<string> $subRules
     * @return Rule
     */
    private static function rule(string $id, FindingCategory|OnRequestCategory $category, array $confidences, array $subRules, string $description): array
    {
        return [
            'id' => $id,
            'category' => $category->value,
            'tier' => $category instanceof FindingCategory ? self::SCAN : self::ON_REQUEST,
            'confidences' => array_map(static fn(FindingConfidence $case): string => $case->value, $confidences),
            'sub_rules' => $subRules,
            'description' => $description,
        ];
    }
}

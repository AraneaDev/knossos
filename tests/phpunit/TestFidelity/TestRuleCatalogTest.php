<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Scan\ScanAnalysisPipeline;
use Knossos\TestFidelity\FindingCategory;
use Knossos\TestFidelity\OnRequestCategory;
use Knossos\TestFidelity\TestRuleCatalog;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** The one list of test-fidelity rules that `list_test_rules`, `audit_tests` and its schema read. */
#[Group('test-fidelity')]
final class TestRuleCatalogTest extends TestCase
{
    public function testEveryRegisteredRuleIsCatalogued(): void
    {
        $scan = array_column(array_filter(TestRuleCatalog::all(), static fn(array $rule): bool => $rule['tier'] === 'scan'), 'id');
        sort($scan, SORT_STRING);

        self::assertSame((new ScanAnalysisPipeline())->testFidelityRuleIds(), $scan);
    }

    public function testCategoriesAreTheTwoEnums(): void
    {
        $scan = array_map(static fn(FindingCategory $case): string => $case->value, FindingCategory::cases());
        $onRequest = array_map(static fn(OnRequestCategory $case): string => $case->value, OnRequestCategory::cases());

        self::assertSame([...$scan, ...$onRequest], TestRuleCatalog::categories());
        self::assertSame($scan, TestRuleCatalog::categories('scan'));
        self::assertSame($onRequest, TestRuleCatalog::categories('on_request'));
        self::assertSame(['OVER_MOCKING', 'STALE_SINCE', 'FIXTURE_DRIFT'], $onRequest);
        self::assertSame(['definite', 'warning'], TestRuleCatalog::confidences());
    }

    public function testEachRuleSaysItsTierConfidencesAndSubRules(): void
    {
        $rules = array_column(TestRuleCatalog::all(), null, 'id');

        self::assertSame([
            'test.fidelity.ghost_member.v1', 'test.fidelity.arity_mismatch.v1', 'test.fidelity.return_drift.v1', 'test.fidelity.visibility_breach.v1',
            'test.fidelity.tautology.v1', 'test.fidelity.mocks_subject.v1', 'test.fidelity.over_mocking.v1', 'test.fidelity.stale_since.v1', 'test.fidelity.fixture_drift.v1',
        ], array_keys($rules));
        foreach ($rules as $id => $rule) {
            self::assertSame(['id', 'category', 'tier', 'confidences', 'sub_rules', 'description'], array_keys($rule));
            self::assertSame('test.fidelity.' . strtolower($rule['category']) . '.v1', $id);
            self::assertNotSame('', $rule['description']);
        }
        self::assertSame(['on_request', ['warning'], ['saturation']], [$rules['test.fidelity.over_mocking.v1']['tier'], $rules['test.fidelity.over_mocking.v1']['confidences'], $rules['test.fidelity.over_mocking.v1']['sub_rules']]);
        self::assertSame(['stale_mock'], $rules['test.fidelity.stale_since.v1']['sub_rules']);
        self::assertSame(['not_a_case', 'kind_mismatch', 'missing_required', 'unknown_key'], $rules['test.fidelity.fixture_drift.v1']['sub_rules']);
        // No on-request rule is definite (Decision 2).
        foreach (TestRuleCatalog::all() as $rule) {
            if ($rule['tier'] === 'on_request') {
                self::assertSame(['warning'], $rule['confidences'], $rule['id']);
            }
        }
        self::assertSame(['definite'], $rules['test.fidelity.ghost_member.v1']['confidences']);
        self::assertSame(['missing_member', 'missing_export', 'missing_module'], $rules['test.fidelity.ghost_member.v1']['sub_rules']);
        self::assertSame(['warning'], $rules['test.fidelity.mocks_subject.v1']['confidences']);
        self::assertSame(['self_comparison', 'mock_echo', 'constant', 'zero_reach', 'unconfigured_spy', 'mock_only'], $rules['test.fidelity.tautology.v1']['sub_rules']);
    }

    public function testTheTierOfACategory(): void
    {
        self::assertSame('scan', TestRuleCatalog::tierOf('GHOST_MEMBER'));
        self::assertSame('on_request', TestRuleCatalog::tierOf('OVER_MOCKING'));
        self::assertNull(TestRuleCatalog::tierOf('NOT_A_CATEGORY'));
    }
}

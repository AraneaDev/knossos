<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Vendor code and builtins are never members of a boundary, so they used to
 * count as `@unassigned` like project code nobody placed: an allow-list policy
 * then failed on every call into a library. They are `@external` now, and
 * `@unassigned` is left for the project's own unplaced code.
 */
final class ExternalPolicyTargetTest extends KnossosTestCase
{
    #[Group('query')]
    public function testAnAllowListCanAdmitVendorCallsWithoutAdmittingUnplacedCode(): void
    {
        [$queries, $ids, $backend] = $this->fixture();

        $result = $queries->services()->policyQueries->checkArchitecture($ids['project'], [
            ['id' => 'backend-allow', 'from_boundary' => $backend, 'allow_targets' => [$backend, '@external'], 'edge_kinds' => ['calls']],
        ]);

        self::assertSame(['App\\Worker'], $this->violatedTargets($result->data['violations']));
        self::assertSame(['@unassigned'], $result->data['violations'][0]['reasons'][0]['targets']);
    }

    #[Group('query')]
    public function testDenyingExternalCatchesOnlyVendorCalls(): void
    {
        [$queries, $ids, $backend] = $this->fixture();

        $external = $queries->services()->policyQueries->checkArchitecture($ids['project'], [
            ['id' => 'no-vendor', 'from_boundary' => $backend, 'deny_targets' => ['@external'], 'edge_kinds' => ['calls']],
        ]);
        $unassigned = $queries->services()->policyQueries->checkArchitecture($ids['project'], [
            ['id' => 'no-unplaced', 'from_boundary' => $backend, 'deny_targets' => ['@unassigned'], 'edge_kinds' => ['calls']],
        ]);

        self::assertSame(['Vendor\\Http\\Client'], $this->violatedTargets($external->data['violations']));
        self::assertSame(['@external'], $external->data['violations'][0]['reasons'][0]['targets']);
        self::assertSame(['App\\Worker'], $this->violatedTargets($unassigned->data['violations']));
    }

    /** An npm package the TypeScript worker imports is a node of kind `package` marked external, and vendor code all the same. */
    #[Group('query')]
    public function testAnExternalPackageImportIsExternalToo(): void
    {
        [$queries, $ids, $backend, $repository] = $this->fixture(complete: false);
        $react = StableId::symbol($ids['project'], 'typescript', 'package', 'react');
        $repository->saveNode($react, $ids['project'], 'typescript', 'package', 'react', 'react', null, $ids['file'], 1, 1, 'ast', 'certain', ['external' => true], 'typescript:file:src/app.ts', $ids['scan']);
        $repository->saveEdge(StableId::edge($ids['project'], 'imports', $ids['checkout'], $react, 'react'), $ids['project'], 'imports', $ids['checkout'], $react, $ids['file'], 2, 2, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $result = $queries->services()->policyQueries->checkArchitecture($ids['project'], [
            ['id' => 'backend-allow', 'from_boundary' => $backend, 'allow_targets' => [$backend, '@external'], 'edge_kinds' => ['calls', 'imports']],
        ]);

        self::assertSame(['App\\Worker'], $this->violatedTargets($result->data['violations']));
    }

    /**
     * Checkout (in Backend) calls Invoice (Backend too), App\Worker (in no boundary) and a vendor class.
     *
     * @return array{ArchitectureQueryService, array<string, string>, string, \Knossos\Store\GraphRepository}
     */
    private function fixture(bool $complete = true): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $worker = StableId::symbol($ids['project'], 'php', 'class', 'App\\Worker');
        $vendor = StableId::symbol($ids['project'], 'php', 'external_class', 'Vendor\\Http\\Client');
        $repository->saveNode($worker, $ids['project'], 'php', 'class', 'App\\Worker', 'Worker', null, $ids['file'], 40, 50, 'ast', 'certain', [], 'php:file:src/Worker.php', $ids['scan']);
        $repository->saveNode($vendor, $ids['project'], 'php', 'external_class', 'Vendor\\Http\\Client', 'Client', null, null, null, null, 'ast', 'certain', [], 'knossos.external', $ids['scan']);
        foreach (['worker' => $worker, 'vendor' => $vendor] as $key => $target) {
            $repository->saveEdge(StableId::edge($ids['project'], 'calls', $ids['checkout'], $target, $key), $ids['project'], 'calls', $ids['checkout'], $target, $ids['file'], 15, 15, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        }
        $backend = StableId::boundary($ids['project'], 'Backend', 'explicit');
        $repository->saveBoundary($backend, $ids['project'], 'Backend', ['path_prefix' => 'src'], 'explicit', $ids['scan']);
        $repository->saveBoundaryMembership($backend, $ids['project'], $ids['checkout'], $ids['scan']);
        $repository->saveBoundaryMembership($backend, $ids['project'], $ids['invoice'], $ids['scan']);
        if ($complete) {
            $repository->completeScan($ids['project'], $ids['scan']);
        }

        return [ArchitectureQueryService::forDatabase($pdo), $ids, $backend, $repository];
    }

    /**
     * @param list<array<string, mixed>> $violations
     * @return list<string>
     */
    private function violatedTargets(array $violations): array
    {
        return array_values(array_map(static fn(array $violation): string => (string) $violation['target']['canonical_name'], $violations));
    }
}

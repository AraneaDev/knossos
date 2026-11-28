<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Configuration\ProjectConfiguration;
use Knossos\Discovery\DiscoveryResult;
use Knossos\Scan\ScanAnalysisPipeline;
use Knossos\Scan\ScanPlan;
use Knossos\Scan\ScanPreparation;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Worker\ContributionDecoder;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;
use Knossos\TestFidelity\TestFinding;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * The scan pipeline judges a test file's reused contribution, decoded from
 * its cached payload as the contribution cache does, against the production
 * contribution as it is now. A TypeScript test is in practice parsed again
 * when its target changes (its read set reaches the target), so this pins the
 * reuse path where it is decided: findings are computed over every
 * contribution, cached ones included.
 */
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class ReusedContributionTest extends KnossosTestCase
{
    use ScansTypeScript;

    private const TEST_FILE = 'knossos.typescript:file:tests/user.test.ts';

    private const PRODUCTION = 'knossos.typescript:file:src/user.ts';

    /** @return array<string, string> */
    private static function files(string $member): array
    {
        return [
            'tsconfig.json' => '{"compilerOptions":{"strict":true,"module":"esnext","target":"es2022","moduleResolution":"bundler"}}',
            'src/user.ts' => "export class UserService {\n    " . $member . "(id: string): string { return id; }\n}\n",
            'tests/user.test.ts' => "import { it, vi } from 'vitest';\nimport { UserService } from '../src/user';\nit('spies', () => {\n    vi.spyOn(new UserService(), 'getProfile');\n});\n",
        ];
    }

    /** @return array<string, ScanContribution> by owner key */
    private function scanned(string $member): array
    {
        $byOwner = [];
        foreach ($this->scanTypeScript(self::files($member)) as $contribution) {
            $byOwner[$contribution->ownerKey] = $contribution;
        }

        return $byOwner;
    }

    /** A contribution as the cache hands it back: stored as JSON, decoded on reuse. */
    private static function reused(ScanContribution $contribution): ScanContribution
    {
        return ContributionDecoder::decode(json_decode((string) json_encode($contribution, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<ScanContribution> $contributions
     * @return list<string>
     */
    private static function findings(array $contributions): array
    {
        $plan = new ScanPlan(
            preparation: new ScanPreparation(
                configuration: new ProjectConfiguration(),
                discovery: new DiscoveryResult(rootRealpath: '/tmp/reused', files: [], units: [], diagnostics: [], inputHash: '', configurationHash: ''),
                maxFiles: 0,
                maxFileBytes: 0,
                explicitBoundaries: [],
                requestedMode: 'incremental',
                snapshotRetention: 0,
                executionPolicy: new WorkerExecutionPolicy(),
                laravel: false,
                symfony: false,
                configurationHashes: ['php' => '', 'typescript' => '', 'python' => '', 'rust' => ''],
                configurationMilliseconds: 0.0,
                discoveryMilliseconds: 0.0,
                planningMilliseconds: 0.0,
            ),
            projectId: 'plan-reused',
            effectiveMode: 'incremental',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );

        return array_map(
            static fn(TestFinding $finding): string => sprintf('%s/%s@%s:%d', $finding->category->value, $finding->subRule, $finding->evidence->relativePath, $finding->evidence->startLine),
            (new ScanAnalysisPipeline())->analyze($plan, $contributions)->testFindings,
        );
    }

    public function testAReusedTestContributionIsJudgedAgainstItsChangedTargetInBothDirections(): void
    {
        $before = $this->scanned('getProfile');
        $renamed = $this->scanned('fetchProfile');

        // Reused test file, production as it was: nothing to find.
        assertSame([], self::findings([self::reused($before[self::TEST_FILE]), $before[self::PRODUCTION]]));
        // Same reused test file, production renamed: the double is now a ghost.
        assertSame(['GHOST_MEMBER/missing_member@tests/user.test.ts:4'], self::findings([self::reused($before[self::TEST_FILE]), $renamed[self::PRODUCTION]]));
        // And back: the test file reused from the renamed scan, production restored.
        assertSame([], self::findings([self::reused($renamed[self::TEST_FILE]), $before[self::PRODUCTION]]));
    }
}

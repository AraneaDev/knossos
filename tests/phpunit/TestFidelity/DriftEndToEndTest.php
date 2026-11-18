<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\TestFidelity\Rules\{ArityMismatchRule, GhostMemberRule, ReturnDriftRule, VisibilityBreachRule};
use Knossos\TestFidelity\TestFidelityAnalysis;
use Knossos\TestFidelity\TestFinding;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\ScanLimits;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * The drift rules over what the real TypeScript worker emits: names that exist
 * without a node of their own (constants, aliases, accessors, parameter
 * properties) are not ghosts, and a name that is gone is.
 */
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class DriftEndToEndTest extends KnossosTestCase
{
    private const FILES = [
        'tsconfig.json' => '{"compilerOptions":{"strict":true,"module":"esnext","target":"es2022","moduleResolution":"bundler"}}',
        'src/config.ts' => "export const API_URL = 'x';\nexport function helper(): void {}\nexport { helper as h };\n",
        'src/cart.ts' => "export class Cart {\n    constructor(private repo: string) {}\n    get total(): number { return 1; }\n    totalCents(): number { return 1; }\n}\n",
        'tests/healthy.test.ts' => <<<'TS'
            import { it, vi } from 'vitest';
            import { Cart } from '../src/cart';
            vi.mock('../src/config', () => ({ API_URL: 'y', h: vi.fn() }));
            it('stubs what exists', () => {
                const cart = new Cart('r');
                vi.spyOn(cart, 'total', 'get');
                vi.spyOn(cart, 'repo');
                vi.spyOn(cart, 'totalCents');
            });
            TS,
        'tests/ghost.test.ts' => <<<'TS'
            import { it, vi } from 'vitest';
            import { Cart } from '../src/cart';
            vi.mock('../src/config', () => ({ API_UR: 'y' }));
            it('stubs what is gone', () => {
                const cart = new Cart('r');
                vi.spyOn(cart, 'totalCent');
            });
            TS,
    ];

    /** @return list<TestFinding> */
    private function findings(): array
    {
        $root = sys_get_temp_dir() . '/knossos-drift-e2e-' . bin2hex(random_bytes(6));
        foreach (self::FILES as $path => $contents) {
            if (!is_dir(dirname($root . '/' . $path))) {
                mkdir(dirname($root . '/' . $path), 0o777, true);
            }
            file_put_contents($root . '/' . $path, $contents);
        }
        $client = $this->typescriptWorkerClient();
        try {
            /** @var list<ScanContribution> $contributions */
            $contributions = [...$client->scan([
                'limits' => ScanLimits::DEFAULT,
                'root' => $root,
                'files' => array_values(array_filter(array_keys(self::FILES), static fn(string $path): bool => str_ends_with($path, '.ts'))),
                'config_files' => ['tsconfig.json'],
            ])];
        } finally {
            $client->shutdown();
            self::removeTree($root);
        }

        return (new TestFidelityAnalysis(new GhostMemberRule(), new ArityMismatchRule(), new ReturnDriftRule(), new VisibilityBreachRule()))->analyze($contributions);
    }

    private static function removeTree(string $path): void
    {
        foreach (is_dir($path) ? array_diff(scandir($path) ?: [], ['.', '..']) : [] as $entry) {
            is_dir($path . '/' . $entry) ? self::removeTree($path . '/' . $entry) : unlink($path . '/' . $entry);
        }
        is_dir($path) ? rmdir($path) : null;
    }

    public function testHealthyStubsOfNamesWithoutNodesGiveNoFinding(): void
    {
        $healthy = array_filter($this->findings(), static fn(TestFinding $finding): bool => $finding->evidence->relativePath === 'tests/healthy.test.ts');

        assertSame([], array_map(static fn(TestFinding $finding): string => $finding->message, array_values($healthy)));
    }

    public function testAPlantedGhostIsFoundAndNothingElse(): void
    {
        $messages = array_map(static fn(TestFinding $finding): string => $finding->evidence->relativePath . ' ' . $finding->subRule . ' ' . $finding->message, $this->findings());

        assertSame([
            'tests/ghost.test.ts missing_export vi.mock ../src/config stubs API_UR, which config.ts no longer has; did you mean API_URL?',
            'tests/ghost.test.ts missing_member vi.spyOn Cart.totalCent stubs totalCent, which Cart no longer has; did you mean totalCents?',
        ], $messages);
    }
}

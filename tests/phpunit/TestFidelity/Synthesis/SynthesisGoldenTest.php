<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Synthesis;

use Knossos\Mcp\ToolService;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * Every golden template, one exact string per case of
 * `tests/Fixtures/test-fidelity/synthesis-expected/cases.json`: Momus's
 * test targets (`packages/cli/test/synthesize.test.ts`,
 * `test/integration/mcp.test.ts`) with the Task 7 departures applied (the
 * vitest function-type generic, interfaces and fully qualified names in
 * PHP, `create_autospec` in Python, real Rust receivers), and the Review
 * Focus 3 cases. One scan of the four-language fixture serves them all;
 * {@see TemplatesLintTest} compiles the same files.
 */
#[Group('mcp')]
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
#[Group('php-scanner')]
#[Group('python-scanner')]
#[Group('rust-scanner')]
final class SynthesisGoldenTest extends KnossosTestCase
{
    public const EXPECTED = '/tests/Fixtures/test-fidelity/synthesis-expected/';

    /**
     * Each case's tool arguments, by case name.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function cases(): array
    {
        $cases = json_decode((string) file_get_contents(self::repositoryRoot() . self::EXPECTED . 'cases.json'), true, 512, JSON_THROW_ON_ERROR);

        return is_array($cases) ? $cases : [];
    }

    public function testEveryGoldenTemplate(): void
    {
        [$tools, $projectId, $root] = $this->buildToolServiceWithScan('test-fidelity/synthesis');
        try {
            self::assertGreaterThan(30, count(self::cases()));
            foreach (self::cases() as $case => $arguments) {
                $data = $tools->call('synthesize_mock', ['project_id' => $projectId, ...$arguments])->data;
                assertSame((string) file_get_contents(self::repositoryRoot() . self::EXPECTED . $case . '.txt'), $data['template'], $case);
            }
            $this->assertMembers($tools, $projectId);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** The per-member parts of the result beside the template: signature, rendered lines, unknowns. */
    private function assertMembers(ToolService $tools, string $projectId): void
    {
        $data = $tools->call('synthesize_mock', ['project_id' => $projectId, 'symbol' => 'P'])->data;
        assertSame([
            'name' => 'configure',
            'signature' => 'configure(name: string, timeoutMs?, label?, flag?, items?: string[], env?: Record<string, string>): void',
            'rendered' => "  // configure(name: string, timeoutMs?, label?, flag?, items?: string[], env?: Record<string, string>): void\n"
                . '  configure: vi.fn<(name: string, timeoutMs?: unknown, label?: unknown, flag?: unknown, items?: string[], env?: Record<string, string>) => void>().mockReturnValue(undefined),',
            'unknown' => ['param timeoutMs: untyped', 'param label: untyped', 'param flag: untyped'],
        ], $data['members'][0]);
        assertSame(['returns: untyped (the inferred type, unstubbed)'], $data['members'][1]['unknown']);
        $union = $tools->call('synthesize_mock', ['project_id' => $projectId, 'symbol' => 'Union'])->data;
        assertSame(['value of Language', 'value of Severity'], $union['members'][2]['unknown']);
        $box = $tools->call('synthesize_mock', ['project_id' => $projectId, 'symbol' => 'Box'])->data;
        assertSame([['type parameter T'], ['type parameter T']], array_column($box['members'], 'unknown'));
        assertSame('identity', $box['omitted'][0]['name']);
        $store = $tools->call('synthesize_mock', ['project_id' => $projectId, 'symbol' => 'synth::focus::Store'])->data;
        assertSame(['type parameter T: name it with expect_convert::<…>()', 'value of T'], $store['members'][7]['unknown']);
        // A generic trait's parameters, an associated item's placeholder, a borrowed return: each said.
        $keyed = $tools->call('synthesize_mock', ['project_id' => $projectId, 'symbol' => 'Keyed'])->data;
        assertSame([['type parameter T of Keyed: bind it, MockKeyed::<…>::new()'], ['type parameter T of Keyed: bind it, MockKeyed::<…>::new()']], array_column($keyed['members'], 'unknown'));
        self::assertContains('Keyed is generic: MockKeyed::new() binds none of its parameters; name them where it is made.', $keyed['notes']);
        $assoc = $tools->call('synthesize_mock', ['project_id' => $projectId, 'symbol' => 'WithAssoc'])->data;
        assertSame([['type Output', ['type Output: () is a placeholder; choose the type a test needs']], ['const LIMIT: usize', ['const LIMIT: a placeholder value']]], array_map(static fn(array $member): array => [$member['signature'], $member['unknown']], array_slice($assoc['members'], 0, 2)));
        $borrow = $tools->call('synthesize_mock', ['project_id' => $projectId, 'symbol' => 'Borrow'])->data;
        assertSame(['returns a reference: return_const takes the owned value it lends'], $borrow['members'][0]['unknown']);
    }
}

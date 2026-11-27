<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use InvalidArgumentException;
use Knossos\Mcp\ToolService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * `verify_test_doubles`, the port of Nemesis `verify_symbol`
 * (`src/mcp/server.ts:84-115`; Decision 8): every double of one type, read
 * from the reverse `doubles` edges, each with its stored findings.
 *
 * `typescript/nemesis` doubles `UserService` five times in
 * `tests/UserService.spec.ts`: a ghost (definite), a return drift (warning),
 * a visibility breach (warning), an arity mismatch (definite) and a clean one.
 */
#[Group('mcp')]
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class VerifyTestDoublesToolTest extends KnossosTestCase
{
    private const SPEC = 'tests/UserService.spec.ts';

    public function testListsEachDoubleWithItsFindings(): void
    {
        $this->withNemesis(function (ToolService $tools, string $projectId): void {
            $definitions = array_column($tools->definitions(), null, 'name');
            self::assertArrayHasKey('verify_test_doubles', $definitions);
            $tool = $definitions['verify_test_doubles'];
            self::assertSame('Verify test doubles', $tool['title']);
            self::assertSame(['project_id', 'symbol'], $tool['inputSchema']['required']);
            self::assertFalse($tool['inputSchema']['additionalProperties']);
            self::assertSame(['string', 1], [$tool['inputSchema']['properties']['symbol']['type'], $tool['inputSchema']['properties']['symbol']['minLength']]);

            $envelope = $tools->call('verify_test_doubles', ['project_id' => $projectId, 'symbol' => 'UserService']);

            $data = $envelope->data;
            self::assertSame('UserService', $data['symbol']);
            self::assertSame(['kind' => 'class', 'canonical_name' => 'src/UserService.ts#UserService', 'language' => 'typescript', 'file' => 'src/UserService.ts', 'line' => 7], $data['resolved']);
            self::assertNull($data['signature']);
            self::assertSame([
                [self::SPEC, 7, 'vi.spyOn UserService.getProfil', 'vitest', ['getProfil'], false, ['GHOST_MEMBER definite']],
                [self::SPEC, 17, 'vi.spyOn UserService.getProfile', 'vitest', ['getProfile'], true, ['RETURN_DRIFT warning']],
                [self::SPEC, 23, 'vi.spyOn UserService.purgeCache', 'vitest', ['purgeCache'], true, ['VISIBILITY_BREACH warning']],
                [self::SPEC, 29, 'vi.spyOn UserService.updateProfile', 'vitest', ['updateProfile'], false, ['ARITY_MISMATCH definite']],
                [self::SPEC, 37, 'vi.spyOn UserService.getProfile', 'vitest', ['getProfile'], true, []],
            ], array_map(static fn(array $double): array => [
                $double['file'], $double['line'], $double['double'], $double['framework'], $double['members'], $double['valid'],
                array_map(static fn(array $item): string => $item['category'] . ' ' . $item['confidence'], $double['findings']),
            ], $data['doubles']));
            // Each finding is the item audit_tests lists for it.
            $ghost = $data['doubles'][0]['findings'][0];
            $audited = $tools->call('audit_tests', ['project_id' => $projectId, 'category' => 'GHOST_MEMBER', 'path_prefix' => self::SPEC])->data['items'];
            self::assertSame($audited, [$ghost]);
            self::assertSame('5 doubles of src/UserService.ts#UserService; 2 invalid.', $envelope->summary);
            // Deterministic, and by the canonical name too.
            self::assertSame(json_encode($data['doubles']), json_encode($tools->call('verify_test_doubles', ['project_id' => $projectId, 'symbol' => 'src/UserService.ts#UserService'])->data['doubles']));
        });
    }

    public function testValidCountsOnlyDefinites(): void
    {
        $this->withNemesis(function (ToolService $tools, string $projectId): void {
            $data = $tools->call('verify_test_doubles', ['project_id' => $projectId, 'symbol' => 'UserService'])->data;

            self::assertSame(['doubles' => 5, 'invalid' => 2], $data['summary']);
            foreach ($data['doubles'] as $double) {
                $definite = in_array('definite', array_column($double['findings'], 'confidence'), true);
                self::assertSame(!$definite, $double['valid'], $double['double']);
            }
            // Two doubles carry only warnings, and stay valid.
            self::assertCount(2, array_filter($data['doubles'], static fn(array $double): bool => $double['valid'] && $double['findings'] !== []));
        });
    }

    public function testAmbiguousNameIsAnErrorListingCandidates(): void
    {
        $this->withNemesis(function (ToolService $tools, string $projectId, string $root): void {
            mkdir($root . '/src/legacy', 0o777, true);
            file_put_contents($root . '/src/legacy/UserService.ts', "export class UserService {}\n");
            // A class a test file declares is no candidate: only test code would double it.
            file_put_contents($root . '/tests/fakes.spec.ts', "export class UserService {}\n");
            $tools->call('scan_project', ['path' => $root]);

            try {
                $tools->call('verify_test_doubles', ['project_id' => $projectId, 'symbol' => 'UserService']);
                self::fail('An ambiguous name was resolved.');
            } catch (InvalidArgumentException $error) {
                self::assertSame('"UserService" names 2 classes, interfaces or traits; name one by its canonical name: src/UserService.ts#UserService (src/UserService.ts:7), src/legacy/UserService.ts#UserService (src/legacy/UserService.ts:1).', $error->getMessage());
            }
            // The canonical name settles it.
            self::assertSame(5, $tools->call('verify_test_doubles', ['project_id' => $projectId, 'symbol' => 'src/UserService.ts#UserService'])->data['summary']['doubles']);
            self::assertSame(['doubles' => 0, 'invalid' => 0], $tools->call('verify_test_doubles', ['project_id' => $projectId, 'symbol' => 'src/legacy/UserService.ts#UserService'])->data['summary']);
        });
    }

    public function testUnknownNameSuggests(): void
    {
        $this->withNemesis(function (ToolService $tools, string $projectId, string $root): void {
            // A class only test code declares: never a candidate, so never suggested either.
            file_put_contents($root . '/tests/fakes.spec.ts', "export class FakeLedger {}\n");
            $tools->call('scan_project', ['path' => $root]);
            foreach ([
                // Neither by its name nor by its canonical name is a test class offered back.
                'FakeLedger' => 'No class, interface or trait is named "FakeLedger".',
                'tests/fakes.spec.ts#FakeLedger' => 'No class, interface or trait is named "tests/fakes.spec.ts#FakeLedger".',
                // A prefix: find_component's candidates of a doubled kind, a substring match of its canonical name included.
                'UserServ' => 'No class, interface or trait is named "UserServ"; did you mean: src/UserService.ts#UserService, src/UserService.ts#UserProfile?',
                // A method is not a type to double, and the answer says what it is (G6-08g).
                'getProfile' => '"getProfile" names a method, which is no class, interface or trait.',
                // A typo find_component does not match is offered its near name.
                'UserServce' => 'No class, interface or trait is named "UserServce"; did you mean: src/UserService.ts#UserService?',
                'Nowhere' => 'No class, interface or trait is named "Nowhere".',
            ] as $symbol => $message) {
                try {
                    $tools->call('verify_test_doubles', ['project_id' => $projectId, 'symbol' => $symbol]);
                    self::fail($symbol . ' was resolved.');
                } catch (InvalidArgumentException $error) {
                    self::assertSame($message, $error->getMessage(), $symbol);
                }
            }
        });
    }

    /**
     * Run `$test` against a scanned copy of `typescript/nemesis`, removed afterwards.
     *
     * @param \Closure(ToolService, string, string): void $test
     */
    private function withNemesis(\Closure $test): void
    {
        [$tools, $projectId, $root] = $this->buildToolServiceWithScan('test-fidelity/typescript/nemesis');
        try {
            $test($tools, $projectId, $root);
        } finally {
            $this->removeTempTree($root);
        }
    }
}

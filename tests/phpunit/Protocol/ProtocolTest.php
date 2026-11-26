<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Protocol;

use InvalidArgumentException;
use Knossos\Application;
use Knossos\Mcp\HttpSessionStore;
use Knossos\Result\ResultEnvelope;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Diagnostic;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\Protocol;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\SchemaSubset;
use PHPUnit\Framework\Attributes\Group;

final class ProtocolTest extends KnossosTestCase
{
    #[Group('protocol')]
    public function testApplicationExposesAVersion(): void
    {
        // release-please rewrites this constant on every release, so pin the shape
        // rather than the literal and keep it in step with the manifest.
        assertSame(1, preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', Application::VERSION));
        assertSame(
            trim((string) file_get_contents(self::repositoryRoot() . '/version.txt')),
            Application::VERSION,
        );
    }

    #[Group('protocol')]
    public function testManifestRoundTripsThroughJson(): void
    {
        $manifest = ScannerManifest::fromArray([
            'id' => 'knossos.typescript',
            'version' => '0.1.0',
            'protocol_version' => Protocol::VERSION,
            'output_schema_version' => Protocol::OUTPUT_SCHEMA_VERSION,
            'languages' => ['typescript'],
            'file_extensions' => ['ts', 'tsx'],
            'capabilities' => ['project_program', 'cancel'],
        ]);

        $decoded = json_decode(json_encode($manifest, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        assertSame('knossos.typescript', $decoded['id']);
        assertSame(['ts', 'tsx'], $decoded['file_extensions']);
    }

    #[Group('protocol')]
    public function testManifestRejectsMalformedLists(): void
    {
        assertThrows(
            fn(): ScannerManifest => ScannerManifest::fromArray([
                'id' => 'broken',
                'version' => '1',
                'protocol_version' => '1.0',
                'output_schema_version' => '1.0',
                'languages' => 'typescript',
                'file_extensions' => [],
                'capabilities' => [],
            ]),
            InvalidArgumentException::class,
        );
    }

    #[Group('protocol')]
    public function testFactsSerializeWithEvidenceAndConfidence(): void
    {
        $evidence = new Evidence('src/Checkout.ts', 3, 7);
        $node = new NodeFact(
            'class:Checkout',
            'class',
            'src/Checkout.Checkout',
            'Checkout',
            Origin::Ast,
            Confidence::Certain,
            $evidence,
        );
        $edge = new EdgeFact(
            'implements',
            'class:Checkout',
            'symbol:Payable',
            Origin::Ast,
            Confidence::Certain,
            $evidence,
        );
        $diagnostic = new Diagnostic('warning', 'TS_DYNAMIC_CALL', 'Call target is dynamic.', $evidence);
        $contribution = new ScanContribution('knossos.typescript:file:src/Checkout.ts', [$node], [$edge], [$diagnostic]);

        $decoded = json_decode(json_encode($contribution, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        assertSame('certain', $decoded['nodes'][0]['confidence']);
        assertSame('src/Checkout.ts', $decoded['edges'][0]['evidence']['path']);
        assertSame('TS_DYNAMIC_CALL', $decoded['diagnostics'][0]['code']);
    }

    #[Group('protocol')]
    public function testEvidenceRejectsUnsafePathsAndLineRanges(): void
    {
        assertThrows(fn(): Evidence => new Evidence('../secret', 1, 1), InvalidArgumentException::class);
        assertThrows(fn(): Evidence => new Evidence('/etc/passwd', 1, 1), InvalidArgumentException::class);
        assertThrows(fn(): Evidence => new Evidence('C:/secret.txt', 1, 1), InvalidArgumentException::class);
        assertThrows(fn(): Evidence => new Evidence('src\\File.php', 1, 1), InvalidArgumentException::class);
        assertThrows(fn(): Evidence => new Evidence('src/File.php', 5, 4), InvalidArgumentException::class);
        assertSame('src/Foo..php', (new Evidence('src/Foo..php', 1, 1))->relativePath);
    }

    #[Group('protocol')]
    public function testResultenvelopeOmitsEnrichmentKeysUntilSet(): void
    {
        $envelope = new ResultEnvelope('p1', 's1', 'sum', ['k' => 'v']);
        $json = $envelope->jsonSerialize();
        assertSame('sum', $json['summary']);
        assertSame(false, array_key_exists('staleness', $json));
        assertSame(false, array_key_exists('next_steps', $json));
        assertSame(false, array_key_exists('meta', $json));
    }

    #[Group('protocol')]
    public function testResultenvelopeWithAttachesEnrichment(): void
    {
        $base = new ResultEnvelope('p1', 's1', 'sum', ['k' => 'v'], [['file' => 'a.php']]);
        $enriched = $base->with(
            staleness: ['state' => 'fresh', 'scanned_at' => '2026-07-19T00:00:00Z', 'age_seconds' => 10],
            nextSteps: [['tool' => 'inspect_component', 'args' => ['component' => 'X'], 'why' => 'drill in']],
            meta: ['result_bytes' => 123, 'verbosity' => 'compact'],
        );
        $json = $enriched->jsonSerialize();
        assertSame('fresh', $json['staleness']['state']);
        assertSame('inspect_component', $json['next_steps'][0]['tool']);
        assertSame(123, $json['meta']['result_bytes']);
        // original is unchanged (readonly clone)
        assertSame(false, array_key_exists('staleness', $base->jsonSerialize()));
    }

    #[Group('protocol')]
    public function testStalenessprobeReportsMissingForUnknownOrUnscannedProjects(): void
    {
        $pdo = $this->freshTestDatabase();
        $probe = new \Knossos\Query\StalenessProbe($pdo, fn(): int => 1_000_000);
        assertSame(null, $probe->probe(''));
        assertSame(null, $probe->probe('catalog'));
        $missing = $probe->probe('project_does_not_exist');
        assertSame('missing', $missing['state']);
        assertContains('scan_project', $missing['guidance']);
    }

    #[Group('protocol')]
    public function testNextstepplannerSuggestsInspectingTheTopCandidateOnAmbiguousFind(): void
    {
        $envelope = new ResultEnvelope('p1', 's1', 'Found 2 candidates.', [
            'candidates' => [
                ['name' => 'App\\Checkout', 'score' => 0.9],
                ['name' => 'App\\CheckoutController', 'score' => 0.7],
            ],
        ]);
        $steps = (new \Knossos\Mcp\NextStepPlanner())->plan('find_component', $envelope);
        assertSame('inspect_component', $steps[0]['tool']);
        assertSame('App\\Checkout', $steps[0]['args']['component']);
    }

    #[Group('protocol')]
    public function testNextstepplannerSuggestsImpactAnalysisAfterInspect(): void
    {
        $envelope = new ResultEnvelope('p1', 's1', 'Dossier.', ['component' => 'App\\Checkout']);
        $steps = (new \Knossos\Mcp\NextStepPlanner())->plan('inspect_component', $envelope);
        assertSame('impact_analysis', $steps[0]['tool']);
        assertSame('App\\Checkout', $steps[0]['args']['symbol']);
    }

    #[Group('protocol')]
    public function testNextstepplannerCapsAtThreeAndDefaultsToEmpty(): void
    {
        $empty = (new \Knossos\Mcp\NextStepPlanner())->plan('architecture_summary', new ResultEnvelope('p', 's', 'x', []));
        assertSame([], $empty);
    }

    #[Group('protocol')]
    public function testNextstepplannerSuggestsACycleCheckForHubComponents(): void
    {
        $planner = new \Knossos\Mcp\NextStepPlanner();

        // A hub flagged at the top level and one flagged inside the dossier must both
        // add the cycle check on top of the usual impact-analysis suggestion.
        foreach ([
            ['component' => 'App\\Checkout', 'is_hub' => true],
            ['component' => ['canonical_name' => 'App\\Checkout', 'is_hub' => true]],
        ] as $data) {
            $steps = $planner->plan('inspect_component', new ResultEnvelope('p1', 's1', 'Dossier.', $data));
            assertSame(2, count($steps));
            assertSame('impact_analysis', $steps[0]['tool']);
            assertSame('dependency_cycles', $steps[1]['tool']);
        }

        // Without the flag the cycle check must not be offered.
        $plain = $planner->plan('inspect_component', new ResultEnvelope('p1', 's1', 'Dossier.', ['component' => 'App\\Checkout']));
        assertSame(1, count($plain));
    }

    #[Group('protocol')]
    public function testNextstepplannerSuggestsNothingWhenAResultCarriesNoUsableName(): void
    {
        $planner = new \Knossos\Mcp\NextStepPlanner();
        // Every case below is well-formed enough to reach the suggestion branch but
        // carries no name the follow-up tool could be called with, so the planner has
        // to stay silent rather than emit a call it knows will fail.
        $cases = [
            // Ambiguous find whose top candidate is an unnameable array.
            ['find_component', ['candidates' => [['score' => 0.9], ['score' => 0.7]]]],
            // Dossier with no component at all, and one naming a non-string scalar.
            ['inspect_component', []],
            ['inspect_component', ['component' => 42]],
            // Impact with a target but nothing depending on it.
            ['impact_analysis', ['target' => 'App\\Checkout']],
            // Impact whose top dependant cannot be named.
            ['impact_analysis', ['target' => 'App\\Checkout', 'impacted' => [['score' => 1]]]],
            // Health with no hotspots, and with a hotspot that cannot be named.
            ['architecture_health', []],
            ['architecture_health', ['hotspots' => [['score' => 1]]]],
            // An empty string is not a usable name either.
            ['inspect_component', ['component' => '']],
        ];
        foreach ($cases as [$tool, $data]) {
            assertSame([], $planner->plan($tool, new ResultEnvelope('p1', 's1', 'x', $data)));
        }

        // A single candidate is unambiguous, so there is nothing to disambiguate.
        assertSame([], $planner->plan('find_component', new ResultEnvelope('p1', 's1', 'x', ['candidates' => [['name' => 'App\\Only']]])));
    }

    #[Group('protocol')]
    public function testHttpsessionstoreTreatsMalformedSessionIdsAsUnknown(): void
    {
        $pdo = $this->freshTestDatabase();
        $store = new HttpSessionStore($pdo, ttlSeconds: 60, maxSessions: 4);
        $real = $store->create();

        // Ids that cannot be a 64-char lowercase hex digest are rejected before any
        // lookup, so a caller cannot probe the table with arbitrary input.
        foreach (['', 'not-a-session', str_repeat('z', 64), strtoupper($real)] as $bogus) {
            assertSame(HttpSessionStore::UNKNOWN_OR_EXPIRED, $store->markInitialized($bogus));
            assertSame(false, $store->exists($bogus));
            assertSame(false, $store->initialized($bogus));
        }

        // The genuine id still works, so the guard rejects only malformed input.
        assertSame(true, $store->exists($real));
        assertSame(HttpSessionStore::INITIALIZED, $store->markInitialized($real));
        assertSame(HttpSessionStore::ALREADY_INITIALIZED, $store->markInitialized($real));
    }

    #[Group('protocol')]
    public function testResultenricherCompactsEvidenceAndReportsMetaByDefault(): void
    {
        $pdo = $this->freshTestDatabase();
        $enricher = new \Knossos\Mcp\ResultEnricher(
            new \Knossos\Query\StalenessProbe($pdo, fn(): int => 1_000_000),
            new \Knossos\Mcp\NextStepPlanner(),
        );
        $evidence = array_map(fn(int $i): array => ['file' => "f{$i}.php", 'line' => $i], range(1, 10));
        $raw = new ResultEnvelope('project_missing', 's1', 'Dossier.', ['component' => 'App\\X'], $evidence);
        $out = $enricher->enrich($raw, 'inspect_component', 'compact')->jsonSerialize();

        assertSame(3, count($out['evidence']));               // compacted to top 3
        assertSame('compact', $out['meta']['verbosity']);
        assertSame(10, $out['meta']['evidence_total']);
        assertSame(3, $out['meta']['evidence_shown']);
        assertSame(true, is_int($out['meta']['result_bytes']));
        assertSame('missing', $out['staleness']['state']);     // unknown project -> missing
        assertSame('impact_analysis', $out['next_steps'][0]['tool']);
    }

    #[Group('protocol')]
    public function testResultenricherKeepsAllEvidenceInFullVerbosity(): void
    {
        $pdo = $this->freshTestDatabase();
        $enricher = new \Knossos\Mcp\ResultEnricher(
            new \Knossos\Query\StalenessProbe($pdo, fn(): int => 1_000_000),
            new \Knossos\Mcp\NextStepPlanner(),
        );
        $evidence = array_map(fn(int $i): array => ['file' => "f{$i}.php"], range(1, 10));
        $raw = new ResultEnvelope('project_missing', 's1', 'x', [], $evidence);
        $out = $enricher->enrich($raw, 'architecture_summary', 'full')->jsonSerialize();
        assertSame(10, count($out['evidence']));
        assertSame('full', $out['meta']['verbosity']);
        assertSame(false, array_key_exists('next_steps', $out)); // summary has no suggestions
    }

    /**
     * A raw wire node of one kind, as the contribution schema reads it.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private static function wireNode(string $kind, array $attributes): array
    {
        return [
            'local_id' => 'n1',
            'kind' => $kind,
            'canonical_name' => 'src/a.test.ts#' . $kind,
            'display_name' => $kind,
            'origin' => 'ast',
            'confidence' => 'certain',
            'evidence' => ['path' => 'src/a.test.ts', 'start_line' => 1, 'end_line' => 2],
            'attributes' => $attributes,
        ];
    }

    /**
     * @return list<string>
     */
    private function nodeSchemaErrors(array $node): array
    {
        $schema = json_decode((string) file_get_contents(self::repositoryRoot() . '/schemas/scanner/v1/contribution.schema.json'), true, 512, JSON_THROW_ON_ERROR);

        return SchemaSubset::errors(['$ref' => '#/$defs/node'], $node, $schema);
    }

    #[Group('protocol')]
    public function testContributionSchemaAcceptsTestFacts(): void
    {
        $double = self::wireNode('test_double', [
            'framework' => 'vitest',
            'pattern' => 'vi.spyOn',
            'target' => ['kind' => 'instance-member', 'member' => 'totalForX'],
            'automock' => false,
            'invocations' => 1,
            'test_case' => 'src/a.test.ts#test:3',
            'members' => [[
                'name' => 'totalForX',
                'line' => 8,
                'api' => 'spyOn',
                'params' => [['name' => 'x', 'type' => 'number']],
                'returns' => [['api' => 'mockReturnValue', 'line' => 9, 'once' => false, 'assignable' => 'unknown', 'typed' => true]],
            ]],
        ]);
        $case = self::wireNode('test_case', [
            'name' => 'totals the cart',
            'production_calls' => 1,
            'subjects' => ['src/cart.ts'],
            'should_panic' => false,
            'assertions' => [['api' => 'toBe', 'line' => 9, 'operands' => [['source' => 'mock-config', 'double' => 'src/a.test.ts#double:4:2'], ['source' => 'literal', 'text' => '42']]]],
        ]);

        assertSame([], $this->nodeSchemaErrors($double));
        assertSame([], $this->nodeSchemaErrors($case));
        assertSame([], $this->nodeSchemaErrors(self::wireNode('class', [])));
    }

    #[Group('protocol')]
    public function testContributionSchemaAcceptsPhpTestFacts(): void
    {
        $double = self::wireNode('test_double', [
            'framework' => 'mockery',
            'pattern' => 'Mockery::mock',
            'target' => ['kind' => 'class', 'specifier' => 'App\\Cart'],
            'automock' => false,
            'invocations' => 1,
            'allows_protected' => true,
            'members' => [[
                'name' => 'total',
                'line' => 8,
                'api' => 'instance-member',
                'called_with' => [['api' => 'toHaveBeenCalledWith', 'line' => 29, 'count' => 3, 'spread' => false]],
                'returns' => [['api' => 'andReturn', 'line' => 9, 'once' => false, 'assignable' => true, 'typed' => true, 'type' => 'string']],
            ]],
        ]);
        $badMatcher = $double;
        $badMatcher['attributes']['members'][0]['called_with'][0]['count'] = -1;
        $badProtected = $double;
        $badProtected['attributes']['allows_protected'] = 'yes';

        assertSame([], $this->nodeSchemaErrors($double));
        assertSame(1, count($this->nodeSchemaErrors($badMatcher)));
        assertSame(1, count($this->nodeSchemaErrors($badProtected)));
    }

    #[Group('protocol')]
    public function testContributionSchemaAcceptsPythonTestFacts(): void
    {
        $double = self::wireNode('test_double', [
            'framework' => 'pytest-mock',
            'pattern' => 'pytest_mock.mocker.patch.object',
            'target' => ['kind' => 'class', 'specifier' => 'app.cart'],
            'automock' => false,
            'invocations' => 1,
            'members' => [[
                'name' => 'total',
                'line' => 8,
                'api' => 'patch.object',
                'called_with' => [['api' => 'assert_called_with', 'line' => 29, 'count' => 2, 'spread' => false, 'keywords' => ['user', 'token']]],
                'returns' => [],
            ]],
        ]);
        $badKeywords = $double;
        $badKeywords['attributes']['members'][0]['called_with'][0]['keywords'] = [3];
        $badApi = $double;
        $badApi['attributes']['members'][0]['api'] = 'patch.nothing';
        $badFramework = $double;
        $badFramework['attributes']['framework'] = 'nose';

        assertSame([], $this->nodeSchemaErrors($double));
        assertSame(1, count($this->nodeSchemaErrors($badKeywords)));
        assertSame(1, count($this->nodeSchemaErrors($badApi)));
        assertSame(1, count($this->nodeSchemaErrors($badFramework)));
    }

    #[Group('protocol')]
    public function testContributionSchemaAcceptsRustTestFacts(): void
    {
        $double = self::wireNode('test_double', [
            'framework' => 'mockall',
            'pattern' => 'mockall::automock',
            'target' => ['kind' => 'class', 'export' => 'Store'],
            'automock' => false,
            'invocations' => 0,
            'compile_checked' => true,
            'members' => [['name' => 'get', 'line' => 8, 'api' => 'expect', 'returns' => []]],
        ]);
        $fake = $double;
        $fake['attributes']['framework'] = 'hand-written';
        $fake['attributes']['members'][0]['api'] = 'impl';
        $badFlag = $double;
        $badFlag['attributes']['compile_checked'] = 'yes';
        $badFramework = $double;
        $badFramework['attributes']['framework'] = 'mockito';

        assertSame([], $this->nodeSchemaErrors($double));
        assertSame([], $this->nodeSchemaErrors($fake));
        foreach (['faux', 'mry', 'mockers', 'mockiato', 'mocktopus', 'mock_derive', 'galvanic'] as $framework) {
            $other = $double;
            $other['attributes']['framework'] = $framework;
            assertSame([], $this->nodeSchemaErrors($other));
        }
        foreach (['context', 'when', 'mock', 'mock_fn', 'given'] as $api) {
            $other = $double;
            $other['attributes']['members'][0]['api'] = $api;
            assertSame([], $this->nodeSchemaErrors($other));
        }
        assertSame(1, count($this->nodeSchemaErrors($badFlag)));
        assertSame(1, count($this->nodeSchemaErrors($badFramework)));
    }

    #[Group('protocol')]
    public function testContributionSchemaRejectsTestDoubleWithoutTarget(): void
    {
        $double = self::wireNode('test_double', ['framework' => 'vitest', 'pattern' => 'vi.fn', 'automock' => false, 'invocations' => 0, 'members' => []]);
        $badMember = self::wireNode('test_case', ['name' => 'x', 'production_calls' => 0, 'subjects' => [], 'assertions' => [['api' => 'toBe', 'line' => 1, 'operands' => [['source' => 'telepathy']]]]]);

        assertSame(['$.attributes: missing target'], $this->nodeSchemaErrors($double));
        assertSame(1, count($this->nodeSchemaErrors($badMember)));
    }

    #[Group('protocol')]
    public function testContributionSchemaRejectsAMalformedStubbedMember(): void
    {
        $double = self::wireNode('test_double', [
            'framework' => 'vitest',
            'pattern' => 'vi.spyOn',
            'target' => ['kind' => 'class'],
            'automock' => false,
            'invocations' => 0,
            'members' => [['line' => 8, 'api' => 'spyOn', 'returns' => []]],
        ]);

        assertSame(1, count($this->nodeSchemaErrors($double)));
    }

    #[Group('protocol')]
    public function testContributionSchemaAcceptsFieldLists(): void
    {
        $dto = self::wireNode('class', [
            'fields' => [['name' => 'id', 'type' => 'string', 'required' => true, 'line' => 3], ['name' => 'note', 'required' => false, 'line' => 4]],
            'fields_open' => true,
            'fields_truncated' => true,
        ]);
        $enum = self::wireNode('enum', ['cases' => [['name' => 'Fast', 'value' => 'fast'], ['name' => 'Slow']], 'cases_truncated' => true]);
        $bad = [
            ['fields' => [['name' => 'id', 'line' => 3]]],
            ['fields' => [['name' => 'id', 'required' => 'yes', 'line' => 3]]],
            ['fields' => [['name' => 'id', 'required' => true, 'line' => 0]]],
            ['fields' => [['name' => 'id', 'required' => true, 'line' => 3, 'rename' => 'x']]],
            ['fields_open' => 'yes'],
            ['cases' => [['name' => 'Fast', 'value' => 1]]],
            ['cases' => [['value' => 'fast']]],
            ['cases_truncated' => 1],
        ];

        assertSame([], $this->nodeSchemaErrors($dto));
        assertSame([], $this->nodeSchemaErrors($enum));
        foreach ($bad as $attributes) {
            assertSame(1, count($this->nodeSchemaErrors(self::wireNode('class', $attributes))), (string) json_encode($attributes));
        }
    }
}

<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use InvalidArgumentException;
use Knossos\Mcp\ToolService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * `check_fixtures`, the port of Nemesis's stale-fixture check (Decision 6):
 * the Nemesis fixture dirs give Task 7's list exactly, a healthy tree with
 * its hostile files gives nothing (and each of its twins is matched, so the
 * silence is not one of nothing read; preflight F16), and a drift planted in
 * that tree is found.
 */
#[Group('mcp')]
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
#[Group('php-scanner')]
final class CheckFixturesToolTest extends KnossosTestCase
{
    private const FIXTURES = 'test-fidelity/fixtures/';

    /** The healthy tree's fixtures that hold records of a type whose wire names may differ: open, so never a candidate. */
    private const OPEN = [
        'php/fixtures/ignoredSecret.fixture.json', 'php/fixtures/jmsUser.fixture.json', 'php/fixtures/renamedUser.fixture.json',
        'python/fixtures/camelOrders.fixture.json', 'python/fixtures/labelled.fixture.json',
        'rust/fixtures/bundles.fixture.json', 'rust/fixtures/cartons.fixture.json', 'rust/fixtures/tracking.fixture.json',
        'typescript/fixtures/exposed.fixture.json',
    ];

    /** The healthy tree's committed hostile files, each unparsable and why. */
    private const UNPARSABLE = [
        ['file' => 'tests/fixtures/anchors.yaml', 'reason' => 'anchor, alias or tag'],
        ['file' => 'tests/fixtures/duplicate.json', 'reason' => 'duplicate key'],
        ['file' => 'tests/fixtures/invalid.json', 'reason' => 'invalid JSON'],
        ['file' => 'tests/fixtures/merge.yaml', 'reason' => 'merge key'],
        ['file' => 'tests/fixtures/tags.yaml', 'reason' => 'anchor, alias or tag'],
    ];

    public function testTheToolIsListed(): void
    {
        $this->withScan('nemesis-data', function (ToolService $tools): void {
            $tool = array_column($tools->definitions(), null, 'name')['check_fixtures'] ?? null;

            self::assertNotNull($tool);
            self::assertSame('Check fixtures', $tool['title']);
            self::assertSame(['project_id'], $tool['inputSchema']['required']);
            self::assertFalse($tool['inputSchema']['additionalProperties']);
            self::assertSame(['type' => 'array', 'maxItems' => 50], array_intersect_key($tool['inputSchema']['properties']['paths'], ['type' => 1, 'maxItems' => 1]));
        });
    }

    public function testNemesisFixtureDirs(): void
    {
        $expected = [
            'nemesis-data' => [
                ['missing_required', 'fixtures/users.fixture.json', 4, 'UserRecord.email'],
                ['missing_required', 'fixtures/users.fixture.json', 4, 'UserRecord.name'],
                ['unknown_key', 'fixtures/users.fixture.json', 4, 'UserRecord.emailAddress'],
                ['unknown_key', 'fixtures/users.fixture.json', 4, 'UserRecord.username'],
            ],
            'nemesis-typed' => [
                ['kind_mismatch', 'fixtures/shipments.fixture.json', 4, 'ShipmentRecord.express'],
                ['kind_mismatch', 'fixtures/shipments.fixture.json', 4, 'ShipmentRecord.id'],
                ['kind_mismatch', 'fixtures/shipments.fixture.json', 4, 'ShipmentRecord.weight'],
            ],
            'nemesis-enum' => [['not_a_case', 'fixtures/lanes.fixture.json', 4, 'LaneRecord.lane']],
            'nemesis-nested' => [
                ['missing_required', 'fixtures/consignments.fixture.json', 5, 'ShippingAddress.city'],
                ['unknown_key', 'fixtures/consignments.fixture.json', 5, 'ShippingAddress.town'],
            ],
            'nemesis-magic' => [],
            'nemesis-invalid' => [],
        ];
        foreach ($expected as $dir => $findings) {
            $this->withScan($dir, function (ToolService $tools, string $projectId) use ($dir, $findings): void {
                $data = $tools->call('check_fixtures', ['project_id' => $projectId])->data;

                self::assertSame($findings, self::findings($data['items']), $dir);
                foreach ($data['items'] as $item) {
                    self::assertSame(['FIXTURE_DRIFT', 'warning', 'test.fidelity.fixture_drift.v1', 'on_request'], [$item['category'], $item['confidence'], $item['rule_id'], $item['tier']], $dir);
                }
                self::assertSame([$dir === 'nemesis-invalid' ? 0 : 1, []], [$data['scanned'], $data['unmatched']], $dir);
                if ($dir === 'nemesis-invalid') {
                    self::assertSame([['file' => 'test/fixtures-invalid/invalid.json', 'reason' => 'invalid JSON']], $data['unparsable']);
                    // `fixtureDiagnostics.test.ts:9-14`: named, it is unparsable all the same.
                    self::assertSame(0, $tools->call('check_fixtures', ['project_id' => $projectId, 'paths' => ['test/fixtures-invalid/invalid.json']])->data['scanned']);
                }
                // audit_tests lists the same findings on request (the category implies the tier).
                $audited = $tools->call('audit_tests', ['project_id' => $projectId, 'category' => 'FIXTURE_DRIFT'])->data;
                self::assertSame($data['items'], $audited['items'], $dir);
                self::assertSame(count($findings), $audited['total'], $dir);
            });
        }
    }

    public function testHealthyTreeGivesNothing(): void
    {
        $this->withScan('healthy', function (ToolService $tools, string $projectId, string $root): void {
            // The hostile files that may not be committed: a link out of the root, a link loop, and a file over the size cap.
            $outside = self::scratchDirectory('outside');
            file_put_contents($outside . '/secret.json', '{"id": "outside"}');
            symlink($outside . '/secret.json', $root . '/tests/fixtures/outside.json');
            symlink($outside, $root . '/tests/fixtures/outside-dir');
            symlink($root . '/tests/fixtures/loop.json', $root . '/tests/fixtures/loop.json');
            file_put_contents($root . '/tests/fixtures/big.fixture.json', '[' . str_repeat('{"id": "x"},', 300_000) . '{"id": "y"}]');
            self::assertGreaterThan(3_000_000, filesize($root . '/tests/fixtures/big.fixture.json'));

            $data = $tools->call('check_fixtures', ['project_id' => $projectId])->data;

            self::assertSame([], $data['items']);
            self::assertSame(0, $data['total']);
            // Every other fixture was matched to its type and found to fit it,
            // `orders.fixture.json`'s "a,b" too: Order splits its tags before pydantic checks the list.
            self::assertSame(33, $data['scanned']);
            self::assertSame(self::OPEN, array_column($data['unmatched'], 'file'));
            self::assertSame(self::UNPARSABLE, $data['unparsable']);
            self::assertSame([4, false], [$data['skipped'], $data['truncated']]);
            // package.json and docker-compose.yml are configuration, never fixtures.
            $listed = json_encode($data, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('package.json', $listed);
            self::assertStringNotContainsString('docker-compose', $listed);
            self::assertSame(['definite' => 0, 'warning' => 0], $data['summary']['by_language']['php']);
            self::assertSame([], $tools->call('audit_tests', ['project_id' => $projectId, 'category' => 'FIXTURE_DRIFT'])->data['items']);
        });
    }

    public function testDriftPlantedInTheHealthyTreeIsFound(): void
    {
        $this->withScan('healthy', function (ToolService $tools, string $projectId, string $root): void {
            $plants = [
                'typescript/fixtures/trips.fixture.json' => ['"speed": "fast"' => '"speed": "medium"'],
                'typescript/fixtures/cargos.fixture.json' => ['"weight": 12,' => '"weight": "heavy",', '"id": "A-2", ' => ''],
                // A class with a constructor may set what its fixture leaves out (review ruling 1): no finding.
                'typescript/fixtures/baskets.fixture.json' => ['{ "owner": "ada" }' => '{ "count": 1 }'],
                'typescript/fixtures/routes.fixture.json' => ['"city": "Utrecht"' => '"town": "Utrecht"'],
                'php/fixtures/members.yaml' => ['tier: gold' => 'tier: silver'],
                'php/fixtures/invoices.fixture.json' => ['"lines": [1, 2]' => '"lines": "none", "extra": 1'],
                'php/fixtures/coupons.fixture.json' => ['"code": "WELCOME"' => '"percent": 5'],
                'php/fixtures/sacks.fixture.json' => ['"id": "s-1"' => '"id": ["s-1"]'],
                'python/fixtures/parcels.yaml' => ['courier: post' => 'courier: drone'],
                'python/fixtures/stops.fixture.json' => ['{ "city": "Utrecht" }' => '{ "city": 7, "street": "x" }'],
                'python/fixtures/manifests.yml' => ['reference: M-1' => 'courier: van'],
                // A validator before the type check spares a field's kind, never its name.
                'python/fixtures/orders.fixture.json' => ['"order_id": 1, "tags": "a,b"' => '"order_id": "one", "tag": "a,b"'],
                'rust/fixtures/pallets.fixture.json' => ['{ "id": 1, "dock": "North" }' => '{ "id": "one", "dock": "East", "colour": "red" }, { "dock": "South" }'],
            ];
            foreach ($plants as $file => $replacements) {
                file_put_contents($root . '/' . $file, strtr((string) file_get_contents($root . '/' . $file), $replacements));
            }

            $items = $tools->call('check_fixtures', ['project_id' => $projectId, 'limit' => 200])->data['items'];

            self::assertSame([
                ['missing_required', 'php/fixtures/coupons.fixture.json', 1, 'Coupon.code'],
                ['kind_mismatch', 'php/fixtures/invoices.fixture.json', 1, 'Invoice.lines'],
                ['unknown_key', 'php/fixtures/invoices.fixture.json', 1, 'Invoice.extra'],
                ['not_a_case', 'php/fixtures/members.yaml', 3, 'Member.tier'],
                ['kind_mismatch', 'php/fixtures/sacks.fixture.json', 1, 'Sack.id'],
                ['missing_required', 'python/fixtures/manifests.yml', 1, 'Manifest.reference'],
                ['kind_mismatch', 'python/fixtures/orders.fixture.json', 1, 'Order.order_id'],
                ['missing_required', 'python/fixtures/orders.fixture.json', 1, 'Order.tags'],
                ['unknown_key', 'python/fixtures/orders.fixture.json', 1, 'Order.tag'],
                ['not_a_case', 'python/fixtures/parcels.yaml', 5, 'Parcel.courier'],
                ['kind_mismatch', 'python/fixtures/stops.fixture.json', 1, 'Stop.city'],
                ['unknown_key', 'python/fixtures/stops.fixture.json', 1, 'Stop.street'],
                ['kind_mismatch', 'rust/fixtures/pallets.fixture.json', 1, 'Pallet.id'],
                ['missing_required', 'rust/fixtures/pallets.fixture.json', 1, 'Pallet.id'],
                ['not_a_case', 'rust/fixtures/pallets.fixture.json', 1, 'Pallet.dock'],
                ['unknown_key', 'rust/fixtures/pallets.fixture.json', 1, 'Pallet.colour'],
                ['kind_mismatch', 'typescript/fixtures/cargos.fixture.json', 2, 'CargoRecord.weight'],
                ['missing_required', 'typescript/fixtures/cargos.fixture.json', 3, 'CargoRecord.id'],
                ['missing_required', 'typescript/fixtures/routes.fixture.json', 4, 'AddressRecord.city'],
                ['unknown_key', 'typescript/fixtures/routes.fixture.json', 4, 'AddressRecord.town'],
                ['not_a_case', 'typescript/fixtures/trips.fixture.json', 3, 'TripRecord.speed'],
            ], self::findings($items));
        });
    }

    public function testPathsOutsideTheRootAreRejected(): void
    {
        $this->withScan('healthy', function (ToolService $tools, string $projectId, string $root): void {
            file_put_contents($root . '/notes.txt', 'not a fixture');
            foreach ([
                '../outside' => 'is outside the project',
                '/etc' => 'is outside the project',
                'php/../../x' => 'is outside the project',
                'nowhere/fixtures' => 'does not exist in the project',
                'notes.txt' => 'is not a JSON or YAML file or a directory',
            ] as $path => $message) {
                try {
                    $tools->call('check_fixtures', ['project_id' => $projectId, 'paths' => [$path]]);
                    self::fail($path . ' was accepted.');
                } catch (InvalidArgumentException $error) {
                    self::assertStringContainsString($message, $error->getMessage(), $path);
                }
            }
            // A link out of the root is no way out either.
            $outside = self::scratchDirectory('outside');
            file_put_contents($outside . '/users.fixture.json', '[{"id": 1}]');
            symlink($outside, $root . '/escape');
            $this->expectException(InvalidArgumentException::class);
            $tools->call('check_fixtures', ['project_id' => $projectId, 'paths' => ['escape']]);
        });
    }

    public function testPathsNarrowTheWalk(): void
    {
        $this->withScan('healthy', function (ToolService $tools, string $projectId, string $root): void {
            $php = $tools->call('check_fixtures', ['project_id' => $projectId, 'paths' => ['php/fixtures']])->data;
            self::assertSame([12, ['php/fixtures/ignoredSecret.fixture.json', 'php/fixtures/jmsUser.fixture.json', 'php/fixtures/renamedUser.fixture.json'], []], [$php['scanned'], array_column($php['unmatched'], 'file'), $php['unparsable']]);

            $one = $tools->call('check_fixtures', ['project_id' => $projectId, 'paths' => ['./typescript/fixtures/trips.fixture.json', 'rust/fixtures']])->data;
            self::assertSame([6, ['rust/fixtures/bundles.fixture.json', 'rust/fixtures/cartons.fixture.json', 'rust/fixtures/tracking.fixture.json']], [$one['scanned'], array_column($one['unmatched'], 'file')]);

            // A file outside any fixture directory is no fixture, unless it is named: then it is taken at its word.
            mkdir($root . '/data');
            file_put_contents($root . '/data/trips.json', '{"trips": [{"id": "T", "speed": "warp", "priority": 1}]}');
            self::assertSame(33, $tools->call('check_fixtures', ['project_id' => $projectId])->data['scanned']);
            $named = $tools->call('check_fixtures', ['project_id' => $projectId, 'paths' => ['data']])->data;
            self::assertSame([['not_a_case', 'data/trips.json', 1, 'TripRecord.speed']], self::findings($named['items']));
        });
    }

    public function testDeterministicAndNonEmpty(): void
    {
        $this->withScan('nemesis-data', function (ToolService $tools, string $projectId): void {
            $first = $tools->call('check_fixtures', ['project_id' => $projectId]);
            $second = $tools->call('check_fixtures', ['project_id' => $projectId]);

            self::assertNotSame([], $first->data['items']);
            self::assertSame(json_encode($first->data), json_encode($second->data));
            self::assertSame('4 fixture drift findings in 1 fixture file.', $first->summary);
            // Paged in that order.
            $page = $tools->call('check_fixtures', ['project_id' => $projectId, 'limit' => 1, 'offset' => 1])->data;
            self::assertSame([[$first->data['items'][1]], 4, ['offset' => 1, 'next_offset' => 2]], [$page['items'], $page['total'], $page['pagination']]);
            self::assertNull($tools->call('check_fixtures', ['project_id' => $projectId, 'offset' => 3])->data['pagination']['next_offset']);
        });
    }

    /**
     * Each item as its sub-rule, file, line and target, sorted.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array{string, string, int, string}>
     */
    private static function findings(array $items): array
    {
        $findings = array_map(static fn(array $item): array => [(string) $item['sub_rule'], (string) $item['file'], (int) $item['line'], (string) $item['target']], $items);
        usort($findings, static fn(array $a, array $b): int => [$a[1], $a[2], $a[0], $a[3]] <=> [$b[1], $b[2], $b[0], $b[3]]);

        return $findings;
    }

    /**
     * A language with no type that carries field facts matches nothing, and
     * both tools say so: a Rust type without a derived `Deserialize` holds the
     * only record type here.
     */
    #[Group('rust-scanner')]
    public function testALanguageWithoutFieldFactsIsNamedNotSilent(): void
    {
        $this->withScan('factless-rust', function (ToolService $tools, string $projectId): void {
            $note = ['FIXTURE_DRIFT: no type with field facts from knossos.rust'];
            $checked = $tools->call('check_fixtures', ['project_id' => $projectId]);
            self::assertSame([0, 1, ['fixtures/order.json']], [$checked->data['total'], $checked->data['scanned'], array_column($checked->data['unmatched'], 'file')]);
            self::assertSame($note, $checked->data['summary']['by_language']['rust']['notes']);
            $audited = $tools->call('audit_tests', ['project_id' => $projectId, 'tier' => 'on_request']);
            self::assertSame(0, $audited->data['total']);
            self::assertSame($note, $audited->data['summary']['by_language']['rust']['notes']);
            // The scan tier walks no fixture, so it says nothing about field facts.
            self::assertArrayNotHasKey('notes', $tools->call('audit_tests', ['project_id' => $projectId])->data['summary']['by_language']['rust']);
        });
    }

    /**
     * Run `$test` against a scanned copy of one fixture tree, removed afterwards.
     *
     * @param \Closure(ToolService, string, string): void $test
     */
    private function withScan(string $dir, \Closure $test): void
    {
        [$tools, $projectId, $root] = $this->buildToolServiceWithScan(self::FIXTURES . $dir);
        try {
            $test($tools, $projectId, $root);
        } finally {
            $this->removeTempTree($root);
        }
    }
}

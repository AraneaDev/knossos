<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Fixtures;

use Knossos\TestFidelity\Fixtures\DtoMatch;
use Knossos\TestFidelity\Fixtures\FixtureType;
use Knossos\TestFidelity\Fixtures\FixtureTypes;
use Knossos\TestFidelity\Fixtures\JsonDocument;
use Knossos\TestFidelity\Fixtures\Value;
use Knossos\TestFidelity\TestFidelityGraph;
use Knossos\Tests\Phpunit\KnossosTestCase;

/**
 * Which type a fixture holds records of, and where in the file they are: the
 * port of Nemesis's `matchDto`, `normalizeName` and `recordsOf`
 * (`staleFixtures.ts:230-282`, `610-666`), its signals and thresholds exact,
 * and its `fixtureRecords.test.ts` and `fixtureDiagnostics.test.ts:39-62`.
 */
final class DtoMatchTest extends KnossosTestCase
{
    /** A TypeScript type whose fields are all required strings. */
    private static function type(string $name, string ...$fields): FixtureType
    {
        return new FixtureType($name, 'src/dto.ts#' . $name, 'typescript', 'src/dto.ts', array_fill_keys($fields, ['type' => 'string', 'required' => true, 'line' => 2]));
    }

    /**
     * The match among `$types`, every one also counted once as a type name unless `$names` says otherwise.
     *
     * @param list<FixtureType> $types
     * @param array<string, int> $names
     * @return array{type: ?FixtureType, best: ?array{type: string, score: int}}
     */
    private static function match(string $fixtureName, string $json, array $types, array $names = []): array
    {
        $counts = $names + array_count_values(array_map(static fn(FixtureType $type): string => $type->name, $types));
        $root = JsonDocument::parse($json)?->root;
        self::assertNotNull($root);

        return (new DtoMatch(new FixtureTypes($types, [], $counts, TestFidelityGraph::fromContributions([]))))->match($fixtureName, $root);
    }

    /** The name the match settled on, or null. */
    private static function matched(string $fixtureName, string $json, FixtureType ...$types): ?string
    {
        return self::match($fixtureName, $json, $types)['type']?->name;
    }

    public function testAShapeMatchNeedsThreeKeysCoveringMostOfBothShapes(): void
    {
        // Three shared keys covering all the fixture and half the type: the boundary holds.
        self::assertSame('Alpha', self::matched('blob', '{"a":"","b":"","c":""}', self::type('Alpha', 'a', 'b', 'c', 'd', 'e', 'f')));
        // Under half the type is not a match.
        self::assertNull(self::matched('blob', '{"a":"","b":"","c":""}', self::type('Alpha', 'a', 'b', 'c', 'd', 'e', 'f', 'g')));
        // Three in five of the fixture's keys hold.
        self::assertSame('Alpha', self::matched('blob', '{"a":"","b":"","c":"","x":"","y":""}', self::type('Alpha', 'a', 'b', 'c', 'd')));
        // Under three in five do not.
        self::assertNull(self::matched('blob', '{"a":"","b":"","c":"","x":"","y":"","z":""}', self::type('Alpha', 'a', 'b', 'c', 'd')));
        // Two shared keys is coincidence, however well they cover.
        self::assertNull(self::matched('blob', '{"a":"","b":""}', self::type('Alpha', 'a', 'b')));
        // An array's records are not read for the shape: only an object's own keys are.
        self::assertNull(self::matched('blob', '[{"a":"","b":"","c":""}]', self::type('Alpha', 'a', 'b', 'c')));
    }

    public function testANameThatLinesUpOutweighsAShape(): void
    {
        $person = self::type('Person', 'id', 'name', 'email');
        $user = self::type('UserRecord', 'id', 'name', 'email');

        self::assertSame('UserRecord', self::matched('users.fixture', '{"id":"","name":"","email":""}', $person, $user));
        $scores = self::match('users.fixture', '{"id":"","name":"","email":""}', [$person, $user]);
        self::assertSame(['type' => 'src/dto.ts#UserRecord', 'score' => 18], $scores['best']);
        // A name alone is enough, whatever the shape.
        self::assertSame('UserRecord', self::matched('users.fixture', '[{"id":"1"}]', $user));
        // So is a key holding records: `{ "users": [ {...} ] }` names UserRecord.
        self::assertSame('UserRecord', self::matched('report', '{"users":[{"id":"1"}]}', $user));
    }

    public function testNormalizeName(): void
    {
        foreach ([
            'users.fixture' => 'user', 'UserRecord' => 'user', 'shipments-data' => 'shipment', 'lane_sample' => 'lane', 'OrderDto' => 'order',
            'captureMeta.fixture' => 'capturemeta', 'consignments.fixtures' => 'consignment', 'AddressModel' => 'addres', 'address' => 'addres',
            'example' => '', 'TaxEntity' => 'tax', 'EventSchema' => 'event', 'lanes' => 'lane',
        ] as $name => $normalized) {
            self::assertSame($normalized, DtoMatch::normalizeName($name), $name);
        }
    }

    public function testATieMatchesNothing(): void
    {
        $result = self::match('blob', '{"a":"","b":"","c":""}', [self::type('Right', 'a', 'b', 'c'), self::type('Left', 'a', 'b', 'c')]);

        self::assertNull($result['type']);
        // The near miss is named, the first by canonical name.
        self::assertSame(['type' => 'src/dto.ts#Left', 'score' => 8], $result['best']);
        // A higher score breaks the tie.
        self::assertSame('Left', self::matched('blob', '{"a":"","b":"","c":"","d":""}', self::type('Right', 'a', 'b', 'c'), self::type('Left', 'a', 'b', 'c', 'd')));
    }

    public function testAnAmbiguousTypeNameMatchesNothing(): void
    {
        $user = self::type('User', 'id', 'name', 'email');
        $legacy = new FixtureType('User', 'src/legacy.ts#User', 'typescript', 'src/legacy.ts', $user->fields);

        self::assertNull(self::match('users', '{"id":"","name":"","email":""}', [$user, $legacy])['type']);
        // Another type of that name ties it up too, though it has no fields to match against.
        self::assertNull(self::match('users', '{"id":"","name":"","email":""}', [$user], ['User' => 2])['type']);
        self::assertNull(self::match('users', '{"id":"","name":"","email":""}', [$user], ['User' => 2])['best']);
        self::assertSame('User', self::matched('users', '{"id":"","name":"","email":""}', $user));
    }

    public function testAScalarKeyThatSharesAClassNameIsNoSignal(): void
    {
        // `fixtureDiagnostics.test.ts:44-62`: a report blob is not an `Edition` record.
        $result = self::match('report', '{"schema":1,"edition":"2026-q1","asOf":"now"}', [self::type('Edition', 'slug', 'data', 'findings')]);

        self::assertSame(['type' => null, 'best' => null], $result);
    }

    public function testTheRecordsOfATopLevelArray(): void
    {
        $user = self::type('UserRecord', 'id', 'name');

        self::assertSame([[1, ['id']], [2, ['id']]], self::records('[{"id":"1"},' . "\n" . '{"id":"2"}, 7, [1]]', $user));
    }

    public function testTheRecordsUnderAKeyedWrapper(): void
    {
        $user = self::type('UserRecord', 'id', 'name', 'email');
        // `fixtureDiagnostics.test.ts:39-43`: the wrapper's name need not be the type's; its array of objects holds the records.
        self::assertSame([[1, ['id', 'name', 'email']], [1, ['id', 'username', 'emailAddress']]], self::records('{"users": [{"id": "1", "name": "Ada", "email": "a"}, {"id": "2", "username": "g", "emailAddress": "g"}]}', $user));
        // A key naming the type holds one record, or an array of them.
        self::assertSame([[1, ['label']]], self::records('{"gap": {"label": "x"}, "other": [{"x": 1}]}', self::type('Gap', 'label')));
        self::assertSame([[1, ['label']], [1, ['label']]], self::records('{"gaps": [{"label": "x"}, {"label": "y"}]}', self::type('Gap', 'label')));
        self::assertSame([[1, ['label']]], self::records('{"gapRecords": [{"label": "x"}]}', self::type('Gap', 'label')));
    }

    public function testAnObjectCarryingTheTypesFieldsIsTheRecord(): void
    {
        // `fixtureRecords.test.ts:29-75`: the array inside it is a member, not the record list.
        $meta = self::type('CaptureMeta', 'app', 'mode', 'run', 'gaps');
        $json = '{"app": "directus", "mode": "baseline", "run": 1, "gaps": [{"kind": "idle", "ms": 12}, {"kind": "idle", "ms": 40}]}';

        self::assertSame([[1, ['app', 'mode', 'run', 'gaps']]], self::records($json, $meta));
        self::assertSame([[1, ['mode', 'run', 'gaps']]], self::records('{"mode": "baseline", "run": 1, "gaps": [{"kind": "idle"}]}', $meta));
        // An object carrying none of them, and no array, is still the one record.
        self::assertSame([[1, ['x']]], self::records('{"x": 1}', $meta));
        // A scalar document holds no record.
        self::assertSame([], self::records('"text"', $meta));
    }

    /**
     * Each record's line and keys.
     *
     * @return list<array{int, list<string>}>
     */
    private static function records(string $json, FixtureType $type): array
    {
        $root = JsonDocument::parse($json)?->root;
        self::assertNotNull($root);

        return array_map(static fn(Value $record): array => [$record->line, $record->keys()], DtoMatch::recordsOf($root, $type));
    }
}

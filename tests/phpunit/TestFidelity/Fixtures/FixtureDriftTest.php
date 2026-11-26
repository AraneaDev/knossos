<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Fixtures;

use Knossos\TestFidelity\Facts\EnumCases;
use Knossos\TestFidelity\Fixtures\DtoMatch;
use Knossos\TestFidelity\Fixtures\FixtureDrift;
use Knossos\TestFidelity\Fixtures\FixtureType;
use Knossos\TestFidelity\Fixtures\FixtureTypes;
use Knossos\TestFidelity\Fixtures\JsonDocument;
use Knossos\TestFidelity\Fixtures\YamlDocument;
use Knossos\TestFidelity\OnRequest\OnRequestFinding;
use Knossos\TestFidelity\TestFidelityGraph;
use Knossos\Tests\Phpunit\KnossosTestCase;

/**
 * One fixture's records against the type they hold: the port of Nemesis's
 * `checkRecord` (`staleFixtures.ts:383-467`) with its silences, one test per
 * sub-rule and per silence. Ports `nestedFixtures.test.ts` and the
 * named-type and Python cases of `fixtureRecords.test.ts`.
 */
final class FixtureDriftTest extends KnossosTestCase
{
    private const FILE = 'fixtures/consignments.fixture.json';

    /** A field. @return array{type: ?string, required: bool, line: int, visibility?: string} */
    private static function field(?string $type, bool $required = true): array
    {
        return ['type' => $type, 'required' => $required, 'line' => 2];
    }

    /** @param array<string, array{type: ?string, required: bool, line: int, visibility?: string}> $fields */
    private static function ts(string $name, array $fields): FixtureType
    {
        return new FixtureType($name, 'src/dto.ts#' . $name, 'typescript', 'src/dto.ts', $fields);
    }

    /** @return list<FixtureType> */
    private static function shipping(): array
    {
        return [
            self::ts('ShippingAddress', ['street' => self::field('string'), 'city' => self::field('string')]),
            self::ts('ConsignmentRecord', [
                'reference' => self::field('string'),
                'destination' => self::field('ShippingAddress'),
                'stops' => self::field('ShippingAddress[]'),
                'lookup' => self::field('Record<string, ShippingAddress>'),
            ]),
        ];
    }

    /** A record as `nestedFixtures.test.ts` builds it, `$over` replacing its members. */
    private static function consignment(string $over = ''): string
    {
        $members = ['reference' => '"CN-1"', 'destination' => '{"street": "Kanaalweg", "city": "Utrecht"}', 'stops' => '[{"street": "Havenkade", "city": "Rotterdam"}]', 'lookup' => '{}'];
        foreach (json_decode('{' . $over . '}', true) ?? [] as $key => $value) {
            $members[$key] = json_encode($value);
        }

        return "[\n  {\n" . implode(",\n", array_map(static fn(string $key, string $value): string => '    "' . $key . '": ' . $value, array_keys($members), $members)) . "\n  }\n]";
    }

    /**
     * The findings of `$json` against `$owner`, among `$types` and the enums, each as `sub_rule line message`.
     *
     * @param list<FixtureType> $types
     * @param list<array{language: string, name: string, canonical: string, cases: ?EnumCases}> $enums
     * @return list<string>
     */
    private static function drift(string $json, string $owner, array $types, array $enums = [], string $file = self::FILE): array
    {
        $names = array_count_values([...array_map(static fn(FixtureType $type): string => $type->name, $types), ...array_column($enums, 'name')]);
        $index = new FixtureTypes($types, $enums, $names, TestFidelityGraph::fromContributions([]));
        $type = array_values(array_filter($types, static fn(FixtureType $type): bool => $type->name === $owner))[0];
        $root = JsonDocument::parse($json)?->root;
        self::assertNotNull($root, $json);

        return array_map(
            static fn(OnRequestFinding $finding): string => sprintf('%s %d %s', $finding->subRule, $finding->line, $finding->message),
            FixtureDrift::check($file, DtoMatch::recordsOf($root, $type), $type, $index),
        );
    }

    public function testARenamedFieldInsideANestedObject(): void
    {
        self::assertSame([
            "missing_required 4 Fixture 'consignments.fixture.json' is missing required field 'destination.city' of ShippingAddress.",
            "unknown_key 4 Fixture 'consignments.fixture.json' has field 'destination.town' which no longer exists on ShippingAddress.",
        ], self::drift(self::consignment('"destination": {"street": "Kanaalweg", "town": "Utrecht"}'), 'ConsignmentRecord', self::shipping()));
    }

    public function testEachElementOfANestedArray(): void
    {
        $messages = self::drift(self::consignment('"stops": [{"street": "A", "city": "X"}, {"street": "B", "town": "Y"}]'), 'ConsignmentRecord', self::shipping());

        self::assertSame([
            "missing_required 5 Fixture 'consignments.fixture.json' is missing required field 'stops.city' of ShippingAddress.",
            "unknown_key 5 Fixture 'consignments.fixture.json' has field 'stops.town' which no longer exists on ShippingAddress.",
        ], $messages);
    }

    public function testANestedObjectThatStillFits(): void
    {
        self::assertSame([], self::drift(self::consignment(), 'ConsignmentRecord', self::shipping()));
    }

    public function testAMapOfTheNestedTypeIsLeftAlone(): void
    {
        // The keys of a `Record<string, T>` are data.
        self::assertSame([], self::drift(self::consignment('"lookup": {"anything at all": {}}'), 'ConsignmentRecord', self::shipping()));
    }

    public function testATypeThatContainsItselfDoesNotLoop(): void
    {
        $types = [self::ts('Leg', ['label' => self::field('string'), 'next' => self::field('Leg | null')]), self::ts('ConsignmentRecord', ['reference' => self::field('string'), 'route' => self::field('Leg')])];
        $deep = '{"label": "end", "next": null}';
        for ($depth = 1; $depth <= 8; ++$depth) {
            $deep = '{"label": "n' . $depth . '", "next": ' . $deep . '}';
        }
        // Drift past the nesting bound is not read: the eighth leg's stray key says nothing.
        $drifted = str_replace('"label": "end"', '"label": "end", "stray": 1', $deep);

        self::assertSame([], self::drift('[{"reference": "CN-1", "route": ' . $deep . '}]', 'ConsignmentRecord', $types));
        self::assertSame([], self::drift('[{"reference": "CN-1", "route": ' . $drifted . '}]', 'ConsignmentRecord', $types));
        // Within it, it is.
        self::assertSame(["unknown_key 1 Fixture 'consignments.fixture.json' has field 'route.stray' which no longer exists on Leg."], self::drift('[{"reference": "CN-1", "route": {"label": "a", "stray": 1, "next": null}}]', 'ConsignmentRecord', $types));
    }

    public function testAScalarAgainstANamedTypeIsNotJudged(): void
    {
        // `fixtureRecords.test.ts:77-105`: `Mode` is a union alias the scan holds no members of.
        $meta = [self::ts('CaptureMeta', ['app' => self::field('string'), 'mode' => self::field('Mode')])];

        self::assertSame([], self::drift('{"app": "directus", "mode": "baseline"}', 'CaptureMeta', $meta));
        self::assertSame(["kind_mismatch 1 Fixture 'consignments.fixture.json' has 'app' as int but CaptureMeta declares 'string'."], self::drift('{"app": 7, "mode": "baseline"}', 'CaptureMeta', $meta));
        self::assertSame(["missing_required 1 Fixture 'consignments.fixture.json' is missing required field 'app' of CaptureMeta."], self::drift('{"mode": "baseline"}', 'CaptureMeta', $meta));
        // Nullable, it is still only a name (`fixtureRecords.test.ts:108-113`).
        self::assertSame([], self::drift('{"app": "x", "mode": "baseline"}', 'CaptureMeta', [self::ts('CaptureMeta', ['app' => self::field('string'), 'mode' => self::field('Mode | null')])]));
    }

    public function testAPythonStrFieldIsJudged(): void
    {
        // `fixtureRecords.test.ts:115-133`.
        $user = new FixtureType('UserRecord', 'src.dto.UserRecord', 'python', 'src/dto.py', ['age' => self::field('int'), 'name' => self::field('str')]);

        self::assertSame(["kind_mismatch 1 Fixture 'userRecord.fixture.json' has 'name' as int but UserRecord declares 'str'."], self::drift('{"name": 7, "age": 3}', 'UserRecord', [$user], file: 'fixtures/userRecord.fixture.json'));
        // A float for an int, and an int for a float, are one kind (Nemesis's lattice).
        self::assertSame([], self::drift('{"name": "x", "age": 3.0}', 'UserRecord', [$user]));
        // Optional[...] admits None.
        $optional = new FixtureType('UserRecord', 'src.dto.UserRecord', 'python', 'src/dto.py', ['name' => self::field('Optional[str]')]);
        self::assertSame([], self::drift('{"name": null}', 'UserRecord', [$optional]));
        self::assertSame(["kind_mismatch 1 Fixture 'consignments.fixture.json' has 'name' as list but UserRecord declares 'Optional[str]'."], self::drift('{"name": []}', 'UserRecord', [$optional]));
    }

    public function testKindsAcrossLanguages(): void
    {
        $shipment = self::ts('ShipmentRecord', ['id' => self::field('string'), 'weight' => self::field('number'), 'express' => self::field('boolean'), 'tags' => self::field('string[]'), 'meta' => self::field('{ [k: string]: string }'), 'at' => self::field('Date'), 'when' => self::field('string | number')]);
        self::assertSame([
            "kind_mismatch 3 Fixture 'shipments.fixture.json' has 'id' as int but ShipmentRecord declares 'string'.",
            "kind_mismatch 3 Fixture 'shipments.fixture.json' has 'weight' as string but ShipmentRecord declares 'number'.",
            "kind_mismatch 3 Fixture 'shipments.fixture.json' has 'express' as string but ShipmentRecord declares 'boolean'.",
            "kind_mismatch 3 Fixture 'shipments.fixture.json' has 'tags' as dict but ShipmentRecord declares 'string[]'.",
        ], self::drift("[\n {\"id\": \"A-1\", \"weight\": 12, \"express\": true, \"tags\": [], \"meta\": {}, \"at\": \"2026\", \"when\": 1},\n {\"id\": 2, \"weight\": \"12.50\", \"express\": \"yes\", \"tags\": {}, \"meta\": {}, \"at\": 3, \"when\": \"x\"}\n]", 'ShipmentRecord', [$shipment], file: 'fixtures/shipments.fixture.json'));

        $pallet = new FixtureType('Pallet', 'healthy::Pallet', 'rust', 'src/lib.rs', ['id' => self::field('u64'), 'note' => self::field('Option<String>', false), 'weights' => self::field('Vec<f64>', false), 'raw' => self::field(null, false)]);
        self::assertSame([], self::drift('{"id": 3.0, "note": null, "weights": [1, 2.5], "raw": {"any": 1}}', 'Pallet', [$pallet]));
        self::assertSame(["kind_mismatch 1 Fixture 'consignments.fixture.json' has 'note' as int but Pallet declares 'Option<String>'."], self::drift('{"id": 1, "note": 4}', 'Pallet', [$pallet]));

        $money = new FixtureType('Money', 'App\Money', 'php', 'src/Money.php', ['cents' => self::field('int'), 'tags' => self::field('array'), 'label' => self::field('?string'), 'owner' => self::field('?App\Owner'), 'any' => self::field('mixed'), 'code' => self::field('string|App\Code')]);
        // PHP's list and dict are one `array`; a float for an int is Nemesis's leniency; a nullable takes null; a class takes an object.
        self::assertSame([], self::drift('{"cents": 1.5, "tags": {"a": 1}, "label": null, "owner": {"id": 1}, "any": [], "code": {"x": 1}}', 'Money', [$money]));
        self::assertSame([], self::drift('{"cents": 1, "tags": [1], "label": "x", "owner": null, "any": 1, "code": "c"}', 'Money', [$money]));
        self::assertSame([
            "kind_mismatch 1 Fixture 'consignments.fixture.json' has 'cents' as list but Money declares 'int'.",
            "kind_mismatch 1 Fixture 'consignments.fixture.json' has 'tags' as string but Money declares 'array'.",
            "kind_mismatch 1 Fixture 'consignments.fixture.json' has 'label' as dict but Money declares '?string'.",
        ], self::drift('{"cents": [], "tags": "x", "label": {}, "owner": null, "any": 1, "code": "c"}', 'Money', [$money]));
    }

    public function testADecimalTakesAStringOrANumber(): void
    {
        // rust_decimal and bigdecimal read "12.50" as readily as 12.5: a decimal is a name, not a float (a departure from Nemesis).
        $quote = new FixtureType('Quote', 'healthy::Quote', 'rust', 'src/lib.rs', ['price' => self::field('Decimal'), 'total' => self::field('rust_decimal::Decimal'), 'big' => self::field('BigDecimal')]);
        self::assertSame([], self::drift('[{"price": "12.50", "total": 3, "big": "1e400"}]', 'Quote', [$quote]));
        $ts = self::ts('PriceRecord', ['amount' => self::field('Decimal'), 'cost' => self::field('number')]);
        self::assertSame(["kind_mismatch 1 Fixture 'consignments.fixture.json' has 'cost' as string but PriceRecord declares 'number'."], self::drift('[{"amount": "1.10", "cost": "x"}]', 'PriceRecord', [$ts]));
    }

    public function testACaseTheEnumNoLongerHas(): void
    {
        $lane = ['language' => 'php', 'name' => 'Lane', 'canonical' => 'FixturesEnum\Lane', 'cases' => new EnumCases(['Fast' => 'fast', 'Slow' => 'slow'])];
        $record = new FixtureType('LaneRecord', 'FixturesEnum\LaneRecord', 'php', 'src/dto.php', ['id' => self::field('string'), 'lane' => self::field('FixturesEnum\Lane')]);

        self::assertSame([
            "not_a_case 4 Fixture 'lanes.fixture.json' has 'lane' as \"express\", which is not a case of Lane.",
            "not_a_case 5 Fixture 'lanes.fixture.json' has 'lane' as \"slw\", which is not a case of Lane. Did you mean \"slow\"?",
        ], self::drift("{\n  \"lanes\": [\n    { \"id\": \"L-1\", \"lane\": \"fast\" },\n    { \"id\": \"L-2\", \"lane\": \"express\" },\n    { \"id\": \"L-3\", \"lane\": \"slw\" }\n  ]\n}", 'LaneRecord', [$record], [$lane], 'fixtures/lanes.fixture.json'));
        // A nullable enum, and an int-backed one read from a number.
        $nullable = new FixtureType('LaneRecord', 'FixturesEnum\LaneRecord', 'php', 'src/dto.php', ['lane' => self::field('?FixturesEnum\Lane'), 'level' => self::field('FixturesEnum\Level')]);
        $level = ['language' => 'php', 'name' => 'Level', 'canonical' => 'FixturesEnum\Level', 'cases' => new EnumCases(['Low' => '1', 'High' => '2'])];
        self::assertSame([], self::drift('{"lane": null, "level": 2}', 'LaneRecord', [$nullable], [$lane, $level]));
        self::assertSame(["not_a_case 1 Fixture 'consignments.fixture.json' has 'level' as 3, which is not a case of Level. Did you mean \"1\"?"], self::drift('{"lane": "fast", "level": 3}', 'LaneRecord', [$nullable], [$lane, $level]));
    }

    public function testAnEnumWithoutValuesIsNotJudged(): void
    {
        // A TypeScript enum with implicit members, a Python `auto()`: what the wire holds is not known.
        $implicit = ['language' => 'typescript', 'name' => 'Lane', 'canonical' => 'src/dto.ts#Lane', 'cases' => new EnumCases(['Fast' => null, 'Slow' => null])];
        $record = self::ts('LaneRecord', ['lane' => self::field('Lane')]);

        self::assertSame([], self::drift('{"lane": "express"}', 'LaneRecord', [$record], [$implicit]));
        self::assertSame([], self::drift('{"lane": 7}', 'LaneRecord', [$record], [$implicit]));
        // An enum whose cases could not be read is still an enum: its field takes no kind judgement either.
        self::assertSame([], self::drift('{"lane": []}', 'LaneRecord', [$record], [['language' => 'typescript', 'name' => 'Lane', 'canonical' => 'src/dto.ts#Lane', 'cases' => null]]));
        // A name two enums share settles nothing.
        $other = ['language' => 'typescript', 'name' => 'Lane', 'canonical' => 'src/other.ts#Lane', 'cases' => new EnumCases(['Fast' => 'fast'])];
        self::assertSame([], self::drift('{"lane": "express"}', 'LaneRecord', [$record], [$implicit, $other]));
    }

    public function testAYamlScalarOfAmbiguousKindIsNotJudged(): void
    {
        $order = self::ts('OrderRecord', ['qty' => self::field('number'), 'insured' => self::field('boolean'), 'code' => self::field('string')]);
        $types = new FixtureTypes([$order], [], ['OrderRecord' => 1], TestFidelityGraph::fromContributions([]));
        $root = (YamlDocument::parse("- qty: 1_000\n  insured: yes\n  code: 010\n- qty: lots\n  insured: true\n  code: x\n") ?? self::fail())->root;

        self::assertSame(
            ["kind_mismatch 4 Fixture 'orders.yaml' has 'qty' as string but OrderRecord declares 'number'."],
            array_map(static fn(OnRequestFinding $finding): string => sprintf('%s %d %s', $finding->subRule, $finding->line, $finding->message), FixtureDrift::check('fixtures/orders.yaml', DtoMatch::recordsOf($root, $order), $order, $types)),
        );
    }

    public function testAMagicTypeTakesAnyKey(): void
    {
        $bag = new FixtureType('Bag', 'Magic\Bag', 'php', 'src/Bag.php', ['id' => self::field('string', false)], magic: true);

        self::assertSame([], self::drift('[{"id": "b-1", "whateverYouLike": 2}]', 'Bag', [$bag]));
        // Its own fields are still judged; a scalar PHP may coerce is not (preflight F14).
        self::assertSame(["kind_mismatch 1 Fixture 'consignments.fixture.json' has 'id' as list but Bag declares 'string'."], self::drift('[{"id": []}]', 'Bag', [$bag]));
        self::assertSame([], self::drift('[{"id": 1}]', 'Bag', [$bag]));
    }

    public function testAConstructorMayFillWhatTheFixtureLeavesOut(): void
    {
        // A Doctrine entity's constructor sets its collections; only what the constructor takes is required of the fixture.
        $entity = new FixtureType('Article', 'App\Article', 'php', 'src/Article.php', ['id' => self::field('int'), 'tags' => self::field('Doctrine\Common\Collections\Collection'), 'title' => self::field('string')], constructed: true, promoted: ['title']);

        self::assertSame([], self::drift('{"id": 1, "title": "x"}', 'Article', [$entity]));
        self::assertSame(["missing_required 1 Fixture 'consignments.fixture.json' is missing required field 'title' of Article."], self::drift('{"id": 1}', 'Article', [$entity]));
        // Its unknown keys are still unknown.
        self::assertSame(["unknown_key 1 Fixture 'consignments.fixture.json' has field 'titel' which no longer exists on Article. Did you mean 'title'?"], self::drift('{"title": "x", "titel": "x"}', 'Article', [$entity]));
        // A constructor taking a key no field names (`__construct(int $amount)` setting `$this->cents`) says nothing of the wire's keys.
        $money = new FixtureType('Money', 'App\Money', 'php', 'src/Money.php', ['cents' => self::field('int'), 'currency' => self::field('string')], constructed: true, keysRenamed: true, promoted: ['currency']);
        self::assertSame([], self::drift('{"amount": 100}', 'Money', [$money]));
        self::assertSame(["kind_mismatch 1 Fixture 'consignments.fixture.json' has 'currency' as list but Money declares 'string'."], self::drift('{"amount": 100, "currency": []}', 'Money', [$money]));
    }

    public function testANonPublicFieldIsAKnownKeyButNeverRequired(): void
    {
        $plain = self::ts('Plain', [
            'plainId' => self::field('string'),
            'internalHash' => ['type' => 'string', 'required' => true, 'line' => 3, 'visibility' => 'private'],
            'tag' => ['type' => 'string', 'required' => true, 'line' => 4, 'visibility' => 'protected'],
        ]);

        self::assertSame([], self::drift('[{"plainId": "p"}]', 'Plain', [$plain]));
        self::assertSame([], self::drift('[{"plainId": "p", "tag": "t"}]', 'Plain', [$plain]));
        // Its value is still held against its type.
        self::assertSame(["kind_mismatch 1 Fixture 'consignments.fixture.json' has 'tag' as int but Plain declares 'string'."], self::drift('[{"plainId": "p", "tag": 3}]', 'Plain', [$plain]));
        self::assertSame(["missing_required 1 Fixture 'consignments.fixture.json' is missing required field 'plainId' of Plain."], self::drift('[{"tag": "t"}]', 'Plain', [$plain]));
    }

    public function testAKeyInAnotherCaseStyleIsTheFieldItSpells(): void
    {
        // A camelCase fixture against snake_case fields: a name converter the scan cannot see may map one to the other.
        $parcel = new FixtureType('Parcel', 'src.shipping.Parcel', 'python', 'src/shipping.py', ['parcel_id' => self::field('int'), 'weight_kg' => self::field('float')]);

        self::assertSame([], self::drift('{"parcelId": 1, "weightKg": 2.5}', 'Parcel', [$parcel]));
        self::assertSame(["kind_mismatch 1 Fixture 'consignments.fixture.json' has 'weightKg' as string but Parcel declares 'float'."], self::drift('{"parcelId": 1, "weightKg": "heavy"}', 'Parcel', [$parcel]));
    }

    public function testFindingsPointAtTheirKeysAndAreSaidOnce(): void
    {
        $user = self::ts('UserRecord', ['id' => self::field('string'), 'name' => self::field('string'), 'note' => self::field(null, false)]);
        $json = "[\n  {\"id\": \"1\"},\n  {\n    \"id\": 2,\n    \"note\": 4\n  }\n]";

        // `fixtureRecords.test.ts:64-74` says the missing field once per record; one finding per file, field and sub-rule says it once.
        self::assertSame([
            "missing_required 2 Fixture 'users.fixture.json' is missing required field 'name' of UserRecord.",
            "kind_mismatch 4 Fixture 'users.fixture.json' has 'id' as int but UserRecord declares 'string'.",
        ], self::drift($json, 'UserRecord', [$user], file: 'fixtures/users.fixture.json'));
        $findings = FixtureDrift::check('fixtures/users.fixture.json', DtoMatch::recordsOf((JsonDocument::parse($json) ?? self::fail())->root, $user), $user, new FixtureTypes([$user], [], ['UserRecord' => 1], TestFidelityGraph::fromContributions([])));
        self::assertSame(
            [['FIXTURE_DRIFT', 'warning', 'test.fidelity.fixture_drift.v1', 'fixtures/users.fixture.json', null, 'UserRecord.name', 'src/dto.ts#UserRecord.name']],
            array_map(static fn(OnRequestFinding $finding): array => [$finding->category->value, $finding->confidence->value, $finding->ruleId, $finding->file, $finding->double, $finding->target, $finding->targetCanonical], array_slice($findings, 0, 1)),
        );
    }
}

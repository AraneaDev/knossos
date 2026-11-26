<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Synthesis;

use Knossos\TestFidelity\Synthesis\ExampleValue;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertSame;

/**
 * A template's example values (Decision 5): Momus's zero values per
 * language, a record built from a complete field list two levels deep, an
 * enum's first case, and a marked placeholder, listed in `unknown`, for
 * anything else.
 */
final class ExampleValueTest extends KnossosTestCase
{
    /** @return array{value: ?string, unknown: list<string>, imports: list<string>} */
    private static function of(string $language, ?string $type, ?\Closure $lookup = null): array
    {
        return (new ExampleValue($language, $lookup ?? static fn(): ?array => null))->of($type, 'src/a');
    }

    private static function value(string $language, ?string $type, ?\Closure $lookup = null): ?string
    {
        return self::of($language, $type, $lookup)['value'];
    }

    public function testTypeScriptPrimitives(): void
    {
        $expected = [
            'number' => '0', 'bigint' => '0n', 'string' => "''", 'boolean' => 'false', 'void' => 'undefined', 'undefined' => 'undefined', 'null' => 'null',
            'unknown' => 'undefined', 'any' => 'undefined', 'never' => null, 'string[]' => '[]', 'Array<number>' => '[]', 'number | undefined' => '0',
            'null | string' => "''", "'on' | 'off'" => '"on"', '42' => '42', '[string, number]' => "['', 0]", '{ ok: boolean; count: number }' => '{ ok: false, count: 0 }',
            '{ run(): void }' => '{ run: vi.fn() }', '(x: number) => void' => 'vi.fn()', 'Promise<number>' => 'Promise.resolve(0)', 'Date' => 'new Date()',
            'Map<string, number>' => 'new Map()', 'Record<string, number>' => '{}', '(string | number)' => "''",
        ];
        foreach ($expected as $type => $value) {
            assertSame($value, self::value('typescript', (string) $type), (string) $type);
        }
        assertSame('undefined', self::value('typescript', null));
    }

    public function testPhpPrimitives(): void
    {
        $expected = ['int' => '0', 'float' => '0.0', 'string' => "''", 'bool' => 'false', 'array' => '[]', 'iterable' => '[]', 'mixed' => 'null', 'null' => 'null',
            '?int' => '0', 'int|string' => '0', 'null|bool' => 'false', 'array|float|null' => '[]', 'never' => null, 'true' => 'true'];
        foreach ($expected as $type => $value) {
            assertSame($value, self::value('php', (string) $type), (string) $type);
        }
    }

    public function testPythonPrimitives(): void
    {
        $expected = ['int' => '0', 'float' => '0.0', 'str' => "''", 'bool' => 'False', 'bytes' => "b''", 'None' => 'None', 'Any' => 'None', 'list[str]' => '[]',
            'dict[str, int]' => '{}', 'set[int]' => 'set()', 'tuple[int, str]' => "(0, '')", 'tuple[int]' => '(0,)', 'tuple[int, ...]' => '()', 'Optional[int]' => '0',
            'int | None' => '0', 'Union[None, str]' => "''", "Literal['a', 'b']" => "'a'", "'int'" => '0', 'typing.List[int]' => '[]', 'NoReturn' => null];
        foreach ($expected as $type => $value) {
            assertSame($value, self::value('python', (string) $type), (string) $type);
        }
    }

    public function testRustPrimitives(): void
    {
        // Momus's `rustReturnExample`, with `!` as its honest `panic!()`.
        $expected = ['u32' => '0', 'i64' => '0', 'f64' => '0.0', 'bool' => 'false', 'char' => "'a'", 'String' => 'String::from("")', '&str' => '""', "&'static str" => '""',
            'Option<u32>' => 'None', 'Result<Vec<String>, String>' => 'Ok(vec![])', '(u32, bool)' => '(0, false)', '()' => '()', '!' => 'panic!()', 'Box<u8>' => 'Box::new(0)',
            'std::string::String' => 'String::from("")', 'HashMap<String, u8>' => 'Default::default()'];
        foreach ($expected as $type => $value) {
            assertSame($value, self::value('rust', (string) $type), (string) $type);
        }
    }

    public function testARecordFromCompleteFieldsAtDepthTwo(): void
    {
        $records = [
            'User' => ['kind' => 'record', 'name' => 'User', 'fields' => ['id' => 'number', 'address' => 'Address'], 'methods' => ['close'], 'import' => null, 'file' => 'src/user.ts'],
            'Address' => ['kind' => 'record', 'name' => 'Address', 'fields' => ['city' => 'string', 'geo' => 'Geo'], 'methods' => [], 'import' => null, 'file' => 'src/user.ts'],
            'Geo' => ['kind' => 'record', 'name' => 'Geo', 'fields' => ['lat' => 'number'], 'methods' => [], 'import' => null, 'file' => 'src/user.ts'],
        ];
        $asked = [];
        $lookup = static function (string $type, string $file) use ($records, &$asked): ?array {
            $asked[] = $type . '@' . $file;

            return $records[$type] ?? null;
        };

        $user = self::of('typescript', 'User', $lookup);

        // Geo is a third level: a placeholder, listed.
        assertSame("{ id: 0, address: { city: '', geo: undefined as unknown as Geo /* knossos: fill in Geo */ }, close: vi.fn() }", $user['value']);
        assertSame(['value of Geo'], $user['unknown']);
        // A field's type is looked up in the record's own file.
        assertSame(['User@src/a', 'Address@src/user.ts'], $asked);
        assertSame("{ city: '', geo: { lat: 0 } }", self::value('typescript', 'Address', $lookup));
    }

    public function testRecordsAndEnumsInEachLanguage(): void
    {
        $php = static fn(string $type): ?array => match ($type) {
            'App\Invoice' => ['kind' => 'record', 'name' => '\App\Invoice', 'fields' => ['id' => 'int', 'lane' => 'App\Lane'], 'methods' => [], 'import' => null, 'file' => 'src/Invoice.php'],
            'App\Lane' => ['kind' => 'enum', 'value' => '\App\Lane::Road', 'import' => null],
            default => null,
        };
        assertSame('new \App\Invoice(id: 0, lane: \App\Lane::Road)', self::value('php', '?App\Invoice', $php));
        $python = static fn(string $type): ?array => $type === 'Item' ? ['kind' => 'record', 'name' => 'Item', 'fields' => ['id' => 'int', 'tags' => 'list[str]'], 'methods' => [], 'import' => 'from app.models import Item', 'file' => 'app/models.py'] : null;
        assertSame(['value' => 'Item(id=0, tags=[])', 'unknown' => [], 'imports' => ['from app.models import Item']], self::of('python', '"Item"', $python));
        $rust = static fn(string $type): ?array => $type === 'Payload' ? ['kind' => 'record', 'name' => 'Payload', 'fields' => ['id' => 'u32'], 'methods' => [], 'import' => 'use synth::Payload;', 'file' => 'src/lib.rs'] : null;
        assertSame(['value' => 'Ok(Payload { id: 0 })', 'unknown' => [], 'imports' => ['use synth::Payload;']], self::of('rust', 'Result<Payload, String>', $rust));
    }

    public function testAnythingElseIsAMarkedPlaceholder(): void
    {
        assertSame(['value' => 'undefined as unknown as Widget /* knossos: fill in Widget */', 'unknown' => ['value of Widget'], 'imports' => []], self::of('typescript', 'Widget'));
        assertSame('undefined as unknown as Page<User> /* knossos: fill in Page<User> */', self::value('typescript', 'Page<User>'));
        assertSame(['value' => 'null /* knossos: fill in App\Item */', 'unknown' => ['value of App\Item'], 'imports' => []], self::of('php', 'App\Item'));
        assertSame('null /* knossos: fill in self */', self::value('php', 'self'));
        assertSame('null /* knossos: fill in A&B */', self::value('php', 'A&B'));
        // Python's mark goes at the end of the template's line: a `#` inside a tuple would end it.
        assertSame(['value' => 'None', 'unknown' => ['value of Item'], 'imports' => []], self::of('python', 'Item'));
        assertSame(['value' => '(None, 0)', 'unknown' => ['value of Path'], 'imports' => []], self::of('python', 'tuple[Path | None, int]'));
        assertSame(['value of Callable[[int], str]'], self::of('python', 'Callable[[int], str]')['unknown']);
        assertSame(['value' => 'todo!() /* knossos: fill in Record */', 'unknown' => ['value of Record'], 'imports' => []], self::of('rust', 'Record'));
        assertSame('todo!() /* knossos: fill in Self */', self::value('rust', 'Self'));
        assertSame('todo!() /* knossos: fill in impl Iterator<Item = u8> */', self::value('rust', 'impl Iterator<Item = u8>'));
        // A comment end in the type cannot close the marker early.
        assertSame('todo!() /* knossos: fill in a* /b */', (new ExampleValue('rust', static fn(): ?array => null))->placeholder('a*/b')['value']);
    }

    public function testJestStubsInLiterals(): void
    {
        assertSame('{ run: jest.fn() }', (new ExampleValue('typescript', static fn(): ?array => null, 'jest.fn()'))->of('{ run(): void }', 'src/a')['value']);
    }
}

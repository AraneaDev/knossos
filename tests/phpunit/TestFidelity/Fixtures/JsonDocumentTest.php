<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Fixtures;

use Knossos\TestFidelity\Fixtures\JsonDocument;
use Knossos\TestFidelity\Fixtures\Value;
use Knossos\Tests\Phpunit\KnossosTestCase;

/**
 * Core's own JSON reader for fixture files: every key's line, so a finding
 * points at the key rather than at line 1 as Nemesis does, and nothing a
 * fixture's author may not have meant (a duplicate key) read as data.
 */
final class JsonDocumentTest extends KnossosTestCase
{
    public function testEachKeyCarriesItsLine(): void
    {
        $json = <<<'JSON'
            {
              "users": [
                { "id": "1", "name": "Ada" },
                {
                  "id": "2",
                  "email": "grace@example.com"
                }
              ]
            }
            JSON;
        $root = JsonDocument::parse($json)?->root;

        self::assertNotNull($root);
        self::assertSame([Value::DICT, 1], [$root->kind, $root->line]);
        $users = $root->get('users');
        self::assertSame([Value::LIST, 2], [$users?->kind, $users?->line]);
        [$first, $second] = $users?->items() ?? [null, null];
        self::assertSame([3, 3, 3], [$first?->line, $first?->get('id')?->line, $first?->get('name')?->line]);
        self::assertSame([4, 5, 6], [$second?->line, $second?->get('id')?->line, $second?->get('email')?->line]);
        self::assertSame(['id', 'email'], $second?->keys());
    }

    /** An object's items are its members' values as a list, in order, its keys dropped. */
    public function testAnObjectsItemsAreItsValuesAsAList(): void
    {
        $items = JsonDocument::parse('{"b": 1, "a": "x"}')?->root->items() ?? [];

        self::assertSame([[0, 1], [1, 'x']], [array_keys($items), array_map(static fn(Value $item): int|float|string|bool|null => $item->scalar, $items)]);
    }

    public function testScalarsKeepTheirKinds(): void
    {
        $root = JsonDocument::parse('{"i": -12, "f": 1.5, "e": 1e3, "z": 1.0, "s": "caf\\u00e9 \"q\"", "t": true, "n": null, "o": {}, "l": []}')?->root;

        self::assertSame(
            ['i' => [Value::INT, -12], 'f' => [Value::FLOAT, 1.5], 'e' => [Value::FLOAT, 1000.0], 'z' => [Value::FLOAT, 1.0], 's' => [Value::STRING, 'café "q"'], 't' => [Value::BOOL, true], 'n' => [Value::NULL, null], 'o' => [Value::DICT, null], 'l' => [Value::LIST, null]],
            array_map(static fn(Value $value): array => [$value->kind, $value->scalar], ($root ?? self::fail())->children),
        );
        // A numeric key is still a key, read back as the text it was.
        self::assertSame(['10', 'x'], JsonDocument::parse('{"10": 1, "x": 2}')?->root->keys());
        // A byte-order mark is not part of the document.
        self::assertSame(Value::LIST, JsonDocument::parse("\xEF\xBB\xBF[]")?->root->kind);
    }

    public function testADuplicateKeyMakesTheFileUnparsable(): void
    {
        foreach (['{"a": 1, "a": 2}', '[{"id": 1, "x": {"k": 1, "k": 2}}]', '{"1": 1, "1": 2}'] as $json) {
            $reason = null;
            self::assertNull(JsonDocument::parse($json, $reason), $json);
            self::assertSame('duplicate key', $reason, $json);
        }
        // Keys that only look alike are two keys.
        self::assertSame(['1', '01', '1.0'], JsonDocument::parse('{"1": 1, "01": 2, "1.0": 3}')?->root->keys());
    }

    public function testNestingIsBounded(): void
    {
        $deepest = str_repeat('[', JsonDocument::MAX_DEPTH) . str_repeat(']', JsonDocument::MAX_DEPTH);
        self::assertNotNull(JsonDocument::parse($deepest));

        $reason = null;
        $tooDeep = str_repeat('{"a":', JsonDocument::MAX_DEPTH + 1) . '1' . str_repeat('}', JsonDocument::MAX_DEPTH + 1);
        self::assertNull(JsonDocument::parse($tooDeep, $reason));
        self::assertSame('nested too deep', $reason);
    }

    public function testInvalidInputGivesNull(): void
    {
        foreach (['{"broken":', '', '   ', '{"a": 1,}', '[1 2]', '[1,]', "\x80", "{\"a\": \"raw\x01\"}", '{"a": NaN}', '{a: 1}', "{'a': 1}", '[] []', '01', '"\ud800"', '{"a" 1}', '{"a"x1}', '{"a": 1x', '[1x', '-', '1.', 'tru'] as $json) {
            $reason = null;
            self::assertNull(JsonDocument::parse($json, $reason), var_export($json, true));
            self::assertSame('invalid JSON', $reason, var_export($json, true));
        }
    }
}

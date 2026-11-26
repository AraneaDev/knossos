<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Fixtures;

use Knossos\TestFidelity\Fixtures\Value;
use Knossos\TestFidelity\Fixtures\YamlDocument;
use Knossos\Tests\Phpunit\KnossosTestCase;

/**
 * Core's YAML subset reader for fixture files (Q4(A)): block maps and
 * sequences, flow collections, plain, quoted and block scalars, and comments.
 * What it does not read (an anchor, an alias, a tag, a merge key, a second
 * document) makes the file unparsable, never a guess.
 */
final class YamlDocumentTest extends KnossosTestCase
{
    /** A value as plain PHP data, for comparing whole trees. */
    private static function plain(?Value $value): mixed
    {
        if ($value === null) {
            return '<missing>';
        }

        return match ($value->kind) {
            Value::DICT => array_map(self::plain(...), $value->children),
            Value::LIST => array_map(self::plain(...), $value->items()),
            default => $value->scalar,
        };
    }

    public function testBlockMapsAndSequencesWithLines(): void
    {
        $yaml = <<<'YAML'
            # shipments for the import test
            shipments:
              - id: A-1
                weight: 12.5
                tags:
                  - fragile
                  - "urgent"
              -   id: A-2
                  weight: 3
            owner:
              name: Ada
            YAML;
        $root = YamlDocument::parse($yaml)?->root;

        self::assertSame([
            'shipments' => [['id' => 'A-1', 'weight' => 12.5, 'tags' => ['fragile', 'urgent']], ['id' => 'A-2', 'weight' => 3]],
            'owner' => ['name' => 'Ada'],
        ], self::plain($root));
        $second = $root?->get('shipments')?->items()[1];
        self::assertSame([2, 3, 4, 8, 9, 10, 11], [
            $root?->get('shipments')?->line, $root?->get('shipments')?->items()[0]->line, $root?->get('shipments')?->items()[0]->get('weight')?->line,
            $second?->get('id')?->line, $second?->get('weight')?->line, $root?->get('owner')?->line, $root?->get('owner')?->get('name')?->line,
        ]);
    }

    public function testASequenceMayStandAtItsKeysIndent(): void
    {
        $yaml = "items:\n- a\n- b: 1\n  c: 2\n- - x\n  - y\nafter: ~\n";

        self::assertSame(['items' => ['a', ['b' => 1, 'c' => 2], ['x', 'y']], 'after' => null], self::plain(YamlDocument::parse($yaml)?->root));
        self::assertSame(['a', 'b'], self::plain(YamlDocument::parse("- a\n- b\n")?->root));
    }

    public function testFlowCollections(): void
    {
        $yaml = "point: {x: 1, y: -2.5, label: 'a, b'}\nlist: [one, \"two\", [3], {k: v}, ]\nempty: []\nmulti: [a,\n  b]\nkeyonly: {a}\n";

        self::assertSame([
            'point' => ['x' => 1, 'y' => -2.5, 'label' => 'a, b'],
            'list' => ['one', 'two', [3], ['k' => 'v']],
            'empty' => [],
            'multi' => ['a', 'b'],
            'keyonly' => ['a' => null],
        ], self::plain(YamlDocument::parse($yaml)?->root));
    }

    public function testPlainScalarsResolveAsTheCoreSchemaDoes(): void
    {
        $yaml = "a: ~\nb: null\nc:\nd: true\ne: False\nf: 12\ng: -3\nh: 0x1F\ni: 0o17\nj: 1.5\nk: .inf\nl: yes\nm: 2026-11-26\nn: '12'\no: \"tab\\there\"\np: it's fine\nq: http://example.com:80/x\nr: +7\ns: 1e3\nt: 'it''s'\n";

        $root = YamlDocument::parse($yaml)?->root;
        self::assertSame(
            ['a' => null, 'b' => null, 'c' => null, 'd' => true, 'e' => false, 'f' => 12, 'g' => -3, 'h' => 31, 'i' => '0o17', 'j' => 1.5, 'k' => INF, 'l' => 'yes', 'm' => '2026-11-26', 'n' => '12', 'o' => "tab\there", 'p' => "it's fine", 'q' => 'http://example.com:80/x', 'r' => 7, 's' => 1000.0, 't' => "it's"],
            self::plain($root),
        );
        self::assertSame([Value::NULL, Value::INT, Value::FLOAT, Value::STRING, Value::BOOL], [
            $root?->get('c')?->kind, $root?->get('f')?->kind, $root?->get('j')?->kind, $root?->get('n')?->kind, $root?->get('e')?->kind,
        ]);
        self::assertTrue(is_nan((float) YamlDocument::parse('x: .nan')?->root->get('x')?->scalar));
    }

    public function testAScalarWhoseKindDependsOnTheYamlVersionIsAmbiguous(): void
    {
        $words = ['yes', 'No', 'ON', 'off', 'y', 'N', '1_000', '-1_000.5', '0b101', '0o17', '010', '08', '1:30', '12:30:00'];
        $root = YamlDocument::parse(implode("\n", array_map(static fn(int $at, string $word): string => 'k' . $at . ': ' . $word, array_keys($words), $words)) . "\n")?->root;

        foreach ($words as $at => $word) {
            self::assertSame([Value::AMBIGUOUS, $word], [$root?->get('k' . $at)?->kind, $root?->get('k' . $at)?->scalar], $word);
        }
        // In a flow collection too; and the core schema's own kinds stay as they were.
        self::assertSame([Value::AMBIGUOUS, Value::AMBIGUOUS], array_column(array_map(static fn(Value $item): array => ['kind' => $item->kind], YamlDocument::parse('[yes, 1_000]')?->root->items() ?? []), 'kind'));
        $plain = YamlDocument::parse("a: 0\nb: 10\nc: true\nd: 0x1F\ne: yesterday\nf: '010'\ng: 1.5\n")?->root;
        self::assertSame([Value::INT, Value::INT, Value::BOOL, Value::INT, Value::STRING, Value::STRING, Value::FLOAT], array_map(static fn(string $key): ?string => $plain?->get($key)?->kind, ['a', 'b', 'c', 'd', 'e', 'f', 'g']));
    }

    public function testALongFlowCollectionIsReadInLinearTime(): void
    {
        // A flow sequence over 30,000 lines: each line's brackets are scanned once, not all the text so far.
        $yaml = "[\n" . str_repeat("1,\n", 30_000) . "1]\n";
        $started = hrtime(true);
        $root = YamlDocument::parse($yaml)?->root;
        $seconds = (hrtime(true) - $started) / 1e9;

        self::assertSame(30_001, count($root?->items() ?? []));
        self::assertLessThan(1.0, $seconds);
        $map = "{\n" . implode(",\n", array_map(static fn(int $at): string => "'k" . $at . "': \"v\"", range(1, 30_000))) . "}\n";
        $started = hrtime(true);
        self::assertSame(30_000, count(YamlDocument::parse($map)?->root->keys() ?? []));
        self::assertLessThan(1.0, (hrtime(true) - $started) / 1e9);
    }

    public function testBlockScalars(): void
    {
        $yaml = "lit: |\n  one\n    two\n  # kept\n\nfold: >-\n  a\n  b\n\n  c\nstrip: |-\n  x\nkeep: |+\n  y\n\nnext: 1\n";

        self::assertSame([
            'lit' => "one\n  two\n# kept\n",
            'fold' => "a b\nc",
            'strip' => 'x',
            'keep' => "y\n\n",
            'next' => 1,
        ], self::plain(YamlDocument::parse($yaml)?->root));
    }

    public function testComments(): void
    {
        $yaml = "# head\na: 1 # trailing\n\n   # indented\nb: \"x # not a comment\"\nc: a#b\nd: ['#', x] # after\n";

        self::assertSame(['a' => 1, 'b' => 'x # not a comment', 'c' => 'a#b', 'd' => ['#', 'x']], self::plain(YamlDocument::parse($yaml)?->root));
    }

    public function testOneLeadingDocumentMarker(): void
    {
        self::assertSame(['a' => 1], self::plain(YamlDocument::parse("---\na: 1\n")?->root));
        self::assertSame(['a' => 1], self::plain(YamlDocument::parse("# c\n--- # start\na: 1\n")?->root));
    }

    public function testAnEmptyDocumentIsNull(): void
    {
        self::assertSame(Value::NULL, YamlDocument::parse('')?->root->kind);
        self::assertSame(Value::NULL, YamlDocument::parse("# nothing\n\n")?->root->kind);
    }

    public function testUnsupportedConstructsGiveNull(): void
    {
        foreach ([
            'anchor' => "base: &base\n  a: 1\n",
            'alias' => "a: 1\nb: *a\n",
            'anchored scalar' => "a: &x 1\n",
            'tag' => "a: !php/const FOO\n",
            'tagged sequence item' => "- !!str 1\n",
            'flow anchor' => "a: [&x 1]\n",
            'merge key' => "<<: {a: 1}\nb: 2\n",
            'two documents' => "a: 1\n---\nb: 2\n",
            'document end' => "a: 1\n...\n",
            'directive' => "%YAML 1.2\n---\na: 1\n",
            'complex key' => "? a\n: 1\n",
            'tab indentation' => "a:\n\tb: 1\n",
            'multi-line plain scalar' => "a: one\n  two\n",
            'multi-line quoted scalar' => "a: \"one\n  two\"\n",
            'duplicate key' => "a: 1\na: 2\n",
            'duplicate flow key' => "a: {k: 1, k: 2}\n",
            'bad indentation' => "a:\n    b: 1\n  c: 2\n",
            'unclosed flow' => "a: [1, 2\n",
            'value after a mapping' => "a: 1\n- b\n",
            'colon in a value' => "a: b: c\n",
        ] as $case => $yaml) {
            self::assertNull(YamlDocument::parse($yaml), $case);
        }
        $reason = null;
        YamlDocument::parse("a: &x 1\n", $reason);
        self::assertSame('anchor, alias or tag', $reason);
        YamlDocument::parse("a: 1\na: 2\n", $reason);
        self::assertSame('duplicate key', $reason);
        YamlDocument::parse("a: 1\n---\nb: 2\n", $reason);
        self::assertSame('multiple documents', $reason);
    }

    public function testNestingIsBounded(): void
    {
        $deep = '';
        for ($depth = 0; $depth <= YamlDocument::MAX_DEPTH; ++$depth) {
            $deep .= str_repeat(' ', $depth) . "k:\n";
        }
        $reason = null;
        self::assertNull(YamlDocument::parse($deep . str_repeat(' ', YamlDocument::MAX_DEPTH + 1) . "k: 1\n", $reason));
        self::assertSame('nested too deep', $reason);
        self::assertNull(YamlDocument::parse(str_repeat('[', YamlDocument::MAX_DEPTH + 1) . str_repeat(']', YamlDocument::MAX_DEPTH + 1)));
    }
}

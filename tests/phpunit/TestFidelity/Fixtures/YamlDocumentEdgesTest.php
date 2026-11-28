<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Fixtures;

use Knossos\TestFidelity\Fixtures\Value;
use Knossos\TestFidelity\Fixtures\YamlDocument;
use Knossos\Tests\Phpunit\KnossosTestCase;

/**
 * The YAML subset reader's edges, each pinned to its exact result: why a
 * refused file is refused, the line every value stands at, block scalar
 * headers, chomping and folding, quoted keys, comments beside quotes, and
 * quotes and nesting inside flow collections.
 */
final class YamlDocumentEdgesTest extends KnossosTestCase
{
    /** A value as plain PHP data. */
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

    /** The parsed document as plain data, or the reason it was refused. */
    private static function read(string $yaml): mixed
    {
        $reason = null;
        $document = YamlDocument::parse($yaml, $reason);

        return $document === null ? ['refused' => $reason] : self::plain($document->root);
    }

    public function testEachRefusalSaysWhy(): void
    {
        foreach ([
            "%YAML 1.2\n---\na: 1\n" => 'directive',
            "? a\n: 1\n" => 'complex key',
            "a: one\n  two\n" => 'multi-line scalar',
            "a:\n    b: 1\n  c: 2\n" => 'bad indentation',
            "a: 1\n- b\n" => 'not a mapping entry',
            "a: |x\n" => 'unsupported block scalar',
            "a: ||\n" => 'unsupported block scalar',
            "a: |x|\n" => 'unsupported block scalar',
            "a: |-x\n" => 'unsupported block scalar',
            "a: |-+\n  x\n" => 'unsupported block scalar',
            "a: |+2-\n  x\n" => 'unsupported block scalar',
            "a:\n  b: |\n      x\n    y\n" => 'bad indentation',
        ] as $yaml => $reason) {
            self::assertSame(['refused' => $reason], self::read($yaml), $yaml);
        }
    }

    public function testEveryValueStandsAtItsOwnLine(): void
    {
        $root = YamlDocument::parse("items:\n  - 1\n\n  - two\n  -\n  - |\n    text\nempty:\nlast: x\n")?->root;
        $items = $root?->get('items')?->items() ?? [];
        self::assertSame([2, 4, 5, 6], array_map(static fn(Value $item): int => $item->line, $items));
        self::assertSame([1, 8, 9], [$root?->get('items')?->line, $root?->get('empty')?->line, $root?->get('last')?->line]);
        self::assertSame([1, 'two', null, "text\n"], array_map(static fn(Value $item): mixed => $item->scalar, $items));
    }

    public function testBlockScalarHeaders(): void
    {
        // An explicit indentation keeps the spaces past it; either order of indicators is read.
        self::assertSame(['a' => "  x\n  y"], self::read("a: |2-\n    x\n    y\n"));
        self::assertSame(['a' => "  x\n  y"], self::read("a: |-2\n    x\n    y\n"));
        self::assertSame(['a' => "  x\n  y\n"], self::read("a: |2\n    x\n    y\n"));
        self::assertSame(['a' => "x\ny\n"], self::read("a: |\n    x\n    y\n"));
        // Keep: every trailing line break, and with no body the breaks alone.
        self::assertSame(['a' => "x\n\n\n", 'b' => 1], self::read("a: |+\n  x\n\n\nb: 1\n"));
        self::assertSame(['a' => "\n\n", 'b' => 1], self::read("a: |+\n\n\nb: 1\n"));
        // At the end of the file too: the break ending the last line is no empty line of its own.
        self::assertSame(['a' => "x\n\n"], self::read("a: |+\n  x\n\n"));
        self::assertSame(['a' => "x\n"], self::read("a: |+\n  x\n"));
        // A line of spaces only is an empty line, not text.
        self::assertSame(['a' => "x\n\ny\n"], self::read("a: |\n  x\n     \n  y\n"));
        // A body line no deeper than the key ends the block, even when it is the first.
        self::assertSame(['a' => '', 'b' => 1], self::read("a: |\nb: 1\n"));
        // Literal keeps the breaks, folded joins them; `|-` is literal too.
        self::assertSame(['a' => "x\ny"], self::read("a: |-\n  x\n  y\n"));
        self::assertSame(['a' => "x y\n"], self::read("a: >\n  x\n  y\n"));
    }

    public function testFoldingKeepsTheBreaksOfMoreIndentedLines(): void
    {
        self::assertSame(['a' => "x\n  y\nz\n"], self::read("a: >\n  x\n    y\n  z\n"));
        self::assertSame(['a' => "x\n  y\n  w\nz\n"], self::read("a: >\n  x\n    y\n    w\n  z\n"));
        // An empty line between two lines is their break; the break before it folds away.
        self::assertSame(['a' => "x\ny z\n"], self::read("a: >\n  x\n\n  y\n  z\n"));
    }

    public function testQuotedKeys(): void
    {
        self::assertSame(['a b' => 1, 'x: y' => 'z', 'q' => 2, 'e' => null], self::read("\"a b\": 1\n'x: y': z\n\"q\" : 2\n\"e\":\n"));
        self::assertSame(['refused' => 'not a mapping entry'], self::read("a: 1\n\"b\":c\n"));
        self::assertSame(['refused' => 'not a mapping entry'], self::read("a: 1\n\"b\"\n"));
        self::assertSame(['k' => ['n' => ' v']], self::read("k:\n  \"n\": \" v\"\n"));
    }

    public function testACommentEndsOnlyOutsideQuotes(): void
    {
        self::assertSame(
            ['a' => 'x # y', 'b' => 'a\\', 'c' => 'a" # b', 'd' => "it's # not"],
            self::read("a: \"x # y\" # one\nb: 'a\\' # two\nc: \"a\\\" # b\" # three\nd: 'it''s # not' # four\n"),
        );
        // A quote inside a comment opens nothing.
        self::assertSame(['a' => 1], self::read("a: 1 # a \"quoted\" word, then \"\n"));
    }

    public function testAFlowCollectionReadsItsQuotes(): void
    {
        self::assertSame(
            ['a' => ['x, y', 'p]q', 'r"s', "t'u", 'v\\', '"w', 'z']],
            self::read("a: [\"x, y\", 'p]q', \"r\\\"s\", 't''u', 'v\\', \"\\\"w\", z]\n"),
        );
        // A bracket inside a quote does not close a collection that runs on below; nor does one after an
        // escaped double quote, while a backslash in a single-quoted scalar escapes nothing.
        self::assertSame(['a' => ['x]y', 'z']], self::read("a: [\"x]y\",\n  z]\n"));
        self::assertSame(['a' => ['r"]s', 'z']], self::read("a: [\"r\\\"]s\",\n  z]\n"));
        self::assertSame(['a' => ['v\\', ']', 'z']], self::read("a: ['v\\', \"]\",\n  z]\n"));
        self::assertSame(['a' => ['q\\', 'z']], self::read("a: [\"q\\\\\",\n  z]\n"));
        // Across lines, each continuation read without its indentation.
        self::assertSame(['a' => [1, 2, 3], 'b' => 4], self::read("a: [1,\n     2,\n   3]   \nb: 4\n"));
        // A trailing tab on a continuation is trimmed too: the flow reader skips spaces only.
        self::assertSame(['a' => [1, 2]], self::read("a: [1,\n  2]\t\n"));
    }

    public function testAFlowCollectionIsNestedToItsBoundAndNoFurther(): void
    {
        $list = static fn(int $depth): string => str_repeat('[', $depth) . str_repeat(']', $depth) . "\n";
        self::assertNotNull(YamlDocument::parse($list(YamlDocument::MAX_DEPTH)));
        self::assertSame(['refused' => 'nested too deep'], self::read($list(YamlDocument::MAX_DEPTH + 1)));
    }

    public function testATopLevelFlowMappingIsAFlowMapping(): void
    {
        // The root stands at its first content line, past comments and a document start.
        self::assertSame([3, 2, 4], [
            YamlDocument::parse("# one\n\n{a: 1}\n")?->root->line,
            YamlDocument::parse("---\na: 1\n")?->root->line,
            YamlDocument::parse("# one\n# two\n\n- 1\n")?->root->line,
        ]);
        self::assertSame(['a' => 1, 'b' => [2]], self::read("{a: 1, b: [2]}\n"));
        self::assertSame([['a' => 1]], self::read("- {a: 1}\n"));
    }
}

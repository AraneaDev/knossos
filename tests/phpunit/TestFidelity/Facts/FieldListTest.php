<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Facts;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\TestFidelity\Facts\EnumCases;
use Knossos\TestFidelity\Facts\FieldList;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;

/**
 * The core's reading of a node's `fields` and `cases`: a list that is
 * present, well formed, whole and closed, or nothing at all.
 */
final class FieldListTest extends KnossosTestCase
{
    private const FIELDS = [
        ['name' => 'id', 'type' => 'string', 'required' => true, 'line' => 3],
        ['name' => 'note', 'required' => false, 'line' => 4],
    ];

    public function testAWellFormedListReadsEveryField(): void
    {
        $list = FieldList::ofNode(['fields' => self::FIELDS, 'fields_open' => false]);

        assertNotNull($list);
        assertSame([
            'id' => ['type' => 'string', 'required' => true, 'line' => 3],
            'note' => ['type' => null, 'required' => false, 'line' => 4],
        ], $list->fields());
    }

    public function testANodeIsReadThroughItsAttributes(): void
    {
        $node = new NodeFact('php:class:A', 'class', 'A', 'A', Origin::Ast, Confidence::Certain, new Evidence('src/A.php', 1, 5), ['fields' => self::FIELDS]);

        assertSame(['id', 'note'], array_keys(FieldList::ofNode($node)?->fields() ?? []));
        assertSame([], FieldList::ofNode(['fields' => []])?->fields());
    }

    public function testAMalformedTruncatedOrOpenListReadsAsNull(): void
    {
        $lists = [
            'absent' => [],
            'not a list' => ['fields' => ['id' => self::FIELDS[0]]],
            'not an array' => ['fields' => 'id'],
            'entry not an array' => ['fields' => ['id']],
            'empty name' => ['fields' => [['name' => '', 'required' => true, 'line' => 1]]],
            'name not a string' => ['fields' => [['name' => 3, 'required' => true, 'line' => 1]]],
            'required not a bool' => ['fields' => [['name' => 'id', 'required' => 1, 'line' => 1]]],
            'required missing' => ['fields' => [['name' => 'id', 'line' => 1]]],
            'line not an int' => ['fields' => [['name' => 'id', 'required' => true, 'line' => '1']]],
            'line below one' => ['fields' => [['name' => 'id', 'required' => true, 'line' => 0]]],
            'type not a string' => ['fields' => [['name' => 'id', 'type' => 4, 'required' => true, 'line' => 1]]],
            'a name twice' => ['fields' => [self::FIELDS[0], self::FIELDS[0]]],
            'truncated' => ['fields' => self::FIELDS, 'fields_truncated' => true],
            'open' => ['fields' => self::FIELDS, 'fields_open' => true],
            'open flag malformed' => ['fields' => self::FIELDS, 'fields_open' => 'no'],
            'truncated flag malformed' => ['fields' => self::FIELDS, 'fields_truncated' => 1],
        ];

        foreach ($lists as $label => $attributes) {
            assertSame([$label, null], [$label, FieldList::ofNode($attributes)]);
        }
    }

    public function testEnumCasesReadTheirValues(): void
    {
        $backed = EnumCases::ofNode(['cases' => [['name' => 'Fast', 'value' => 'fast'], ['name' => 'Slow', 'value' => 'slow']]]);
        $pure = EnumCases::ofNode(['cases' => [['name' => 'Hearts'], ['name' => 'Spades']]]);

        assertNotNull($backed);
        assertNotNull($pure);
        assertSame(['Fast' => 'fast', 'Slow' => 'slow'], $backed->cases());
        assertSame(true, $backed->backed());
        assertSame(['Hearts' => null, 'Spades' => null], $pure->cases());
        assertSame(false, $pure->backed());
        assertSame(false, EnumCases::ofNode(['cases' => []])?->backed());
    }

    public function testMalformedOrTruncatedCasesReadAsNull(): void
    {
        $lists = [
            'absent' => [],
            'not a list' => ['cases' => ['A' => ['name' => 'A']]],
            'entry not an array' => ['cases' => ['A']],
            'empty name' => ['cases' => [['name' => '']]],
            'value not a string' => ['cases' => [['name' => 'A', 'value' => 1]]],
            'a name twice' => ['cases' => [['name' => 'A'], ['name' => 'A']]],
            'some values only' => ['cases' => [['name' => 'A', 'value' => 'a'], ['name' => 'B']]],
            'truncated' => ['cases' => [['name' => 'A']], 'cases_truncated' => true],
            'truncated flag malformed' => ['cases' => [['name' => 'A']], 'cases_truncated' => 'yes'],
        ];

        foreach ($lists as $label => $attributes) {
            assertSame([$label, null], [$label, EnumCases::ofNode($attributes)]);
        }
    }
}

<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\SignatureLine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** A stored signature as the one line a caller reads. */
#[Group('query')]
final class SignatureLineTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function signatures(): iterable
    {
        yield 'typed, optional, variadic and a return' => [
            ['params' => [['name' => 'root', 'type' => 'string'], ['name' => 'limit', 'type' => 'number', 'optional' => true], ['name' => 'rest', 'type' => 'string[]', 'variadic' => true]], 'returns' => 'Promise<void>'],
            'scan(root: string, limit?: number, ...rest: string[]): Promise<void>',
        ];
        yield 'untyped' => [['params' => [['name' => 'a'], ['name' => 'b', 'optional' => true]]], 'scan(a, b?)'];
        yield 'no parameters' => [['params' => [], 'returns' => 'void'], 'scan(): void'];
        yield 'names that carry their stars' => [
            ['params' => [['name' => '*rest', 'type' => 'str', 'variadic' => true], ['name' => '**options', 'variadic' => true]]],
            'scan(*rest: str, **options)',
        ];
        yield 'a cut type alone adds no parameter' => [
            ['params' => [['name' => 'a', 'type' => 'Long…']], 'truncated' => true],
            'scan(a: Long…)',
        ];
        yield 'a cut list says more follow' => [
            ['params' => array_fill(0, 12, ['name' => 'p']), 'truncated' => true],
            'scan(' . implode(', ', array_fill(0, 12, 'p')) . ', …)',
        ];
        yield 'control characters and line breaks become spaces' => [
            ['params' => [['name' => 'a', 'type' => "x\ny\u{2028}z"]], 'returns' => "void\x07"],
            'scan(a: x y z): void',
        ];
    }

    /** @param array<string, mixed> $signature */
    #[DataProvider('signatures')]
    public function testASignatureReadsAsOneLine(array $signature, string $line): void
    {
        assertSame($line, SignatureLine::of($signature, 'scan'));
    }

    public function testAnythingThatIsNotASignatureHasNoLine(): void
    {
        foreach ([null, 'scan()', [], ['params' => 'a'], ['params' => ['a']], ['params' => [['type' => 'int']]], ['params' => [['name' => 7]]]] as $value) {
            assertSame(null, SignatureLine::of($value, 'scan'), json_encode($value, JSON_THROW_ON_ERROR));
        }
    }

    public function testFieldsGiveTheLineInPlaceOfTheStoredParts(): void
    {
        $with = ['static' => true, 'signature' => ['params' => [['name' => 'id', 'type' => 'int']], 'returns' => '?User']];

        assertSame(['attributes' => ['static' => true], 'signature' => 'find(id: int): ?User'], SignatureLine::fields($with, 'find'));
        assertSame(['attributes' => ['signature' => 'not one']], SignatureLine::fields(['signature' => 'not one'], 'find'));
        assertSame(['attributes' => ['static' => true]], SignatureLine::fields(['static' => true], 'find'));
        assertSame(['attributes' => []], SignatureLine::fields([], 'find'));
    }
}

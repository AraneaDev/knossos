<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan\Conventions;

use Knossos\Scan\Conventions\NameWords;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** The one word splitter the folder facts and the brief share. */
#[Group('scan')]
final class NameWordsTest extends KnossosTestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function names(): iterable
    {
        yield 'an acronym at the end stays whole' => ['FooHTTP', ['Foo', 'HTTP']];
        yield 'an acronym before a word gives up its last capital' => ['HTTPClient', ['HTTP', 'Client']];
        yield 'CamelCase' => ['ChurnService', ['Churn', 'Service']];
        yield 'lower camelCase' => ['fooBar', ['foo', 'Bar']];
        yield 'snake_case' => ['foo_service', ['foo', 'service']];
        yield 'kebab-case' => ['new-thing', ['new', 'thing']];
        yield 'dotted' => ['a.spec', ['a', 'spec']];
        yield 'digits stay with their word' => ['Foo2Bar', ['Foo2', 'Bar']];
        yield 'nothing' => ['', []];
    }

    /** @param list<string> $words */
    #[DataProvider('names')]
    public function testANameSplitsIntoItsWords(string $name, array $words): void
    {
        self::assertSame($words, array_column(NameWords::split($name), 0));
    }

    /** Offsets point at each word's first byte, so a suffix is the name from that offset on. */
    public function testOffsetsAreWhereEachWordStarts(): void
    {
        self::assertSame([['foo', 0], ['service', 4]], NameWords::split('foo_service'));
    }

    /** The last word is compared in lower case, so every spelling of one word agrees. */
    public function testTheLastWordIsLowerCase(): void
    {
        self::assertSame('thing', NameWords::last('new-thing'));
        self::assertSame('thing', NameWords::last('NewThing'));
        self::assertSame('http', NameWords::last('FooHTTP'));
        self::assertSame('', NameWords::last('__'));
    }
}

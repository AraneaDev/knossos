<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\TestFidelity\PythonTypeFit;
use Knossos\TestFidelity\TestFidelityGraph;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\TestFidelity\Rules\PythonFixtures;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('test-fidelity')]
final class PythonTypeFitTest extends KnossosTestCase
{
    /**
     * `app.money.Money` is closed and stands alone; `Change` extends it;
     * `Cents` subclasses `int`, which gives no edge and opens its list;
     * `Colour` extends `enum.Enum`, outside the project; `Shape` extends
     * `abc.ABC`, which is seen.
     */
    private static function graph(): TestFidelityGraph
    {
        $money = PythonFixtures::node('class', 'app.money.Money', ['member_names' => ['cents']]);
        $change = PythonFixtures::node('class', 'app.money.Change', ['member_names' => []]);
        $cents = PythonFixtures::node('class', 'app.money.Cents', ['member_names' => [], 'member_names_open' => true]);
        $colour = PythonFixtures::node('class', 'app.money.Colour', ['member_names' => ['RED']]);
        $shape = PythonFixtures::node('class', 'app.money.Shape', ['member_names' => ['area']]);

        return TestFidelityGraph::fromContributions([new ScanContribution('knossos.python:file:src/app/money.py', [$money, $change, $cents, $colour, $shape], [
            PythonFixtures::edge('extends', $change, 'py:class:app.money.Money'),
            PythonFixtures::edge('extends', $colour, 'py:external_symbol:enum.Enum'),
            PythonFixtures::edge('extends', $shape, 'py:external_symbol:abc.ABC'),
        ])]);
    }

    private static function judge(string $pinned, ?string $declared): ?bool
    {
        return PythonTypeFit::judge($pinned, $declared, self::graph());
    }

    public function testBoolFitsInt(): void
    {
        assertSame(true, self::judge('bool', 'int'));
        assertSame(false, self::judge('int', 'bool'));
    }

    public function testIntFitsFloatAndComplex(): void
    {
        assertSame(true, self::judge('int', 'float'));
        assertSame(true, self::judge('int', 'complex'));
        assertSame(true, self::judge('float', 'complex'));
        assertSame(false, self::judge('float', 'int'));
        assertSame(false, self::judge('complex', 'float'));
    }

    public function testStrRefusesInt(): void
    {
        assertSame(false, self::judge('str', 'int'));
        assertSame(false, self::judge('bytes', 'str'));
        assertSame(true, self::judge('str', 'str'));
        assertSame(true, self::judge('str', 'typing.Text'));
        assertSame(false, self::judge('str', 'int  '));
    }

    public function testNoneNeedsOptionalOrUnion(): void
    {
        assertSame(false, self::judge('None', 'int'));
        assertSame(true, self::judge('None', 'None'));
        assertSame(true, self::judge('None', 'Optional[int]'));
        assertSame(true, self::judge('None', 'typing.Optional[int]'));
        assertSame(true, self::judge('None', 'Union[int, None]'));
        assertSame(true, self::judge('None', 'int | None'));
        assertSame(false, self::judge('None', 'int | str'));
        assertSame(false, self::judge('str', 'Optional[int]'));
        assertSame(true, self::judge('int', 'Union[str, int]'));
    }

    public function testAnyAndObjectFitEverything(): void
    {
        foreach (['Any', 'typing.Any', 'object'] as $declared) {
            foreach (['int', 'None', 'list', 'class:app.money.Money'] as $pinned) {
                assertSame([$pinned, $declared, true], [$pinned, $declared, self::judge($pinned, $declared)]);
            }
        }
    }

    public function testContainerKindOnly(): void
    {
        assertSame(true, self::judge('list', 'list[str]'));
        assertSame(true, self::judge('list', 'List[int]'));
        assertSame(true, self::judge('dict', 'dict[str, list[int]]'));
        assertSame(true, self::judge('tuple', 'tuple[int, ...]'));
        assertSame(false, self::judge('dict', 'list[int]'));
        assertSame(false, self::judge('set', 'frozenset[int]'));
    }

    public function testAQuotedAnnotationIsRead(): void
    {
        assertSame(false, self::judge('str', '"int"'));
        assertSame(true, self::judge('None', "'Optional[int]'"));
        assertSame(true, self::judge('None', 'Optional["int"]'));
        assertSame(true, self::judge('int', 'Annotated[int, "meta"]'));
    }

    public function testUnjudgedFormsAreUnknown(): void
    {
        foreach (['Iterator[int]', 'Literal["a"]', 'T', 'Self', 'Invoice', 'Callable[[int], str]', 'Sequence[int]', 'Optional[Invoice]', 'int | Invoice', 'int[', 'tuple[()]'] as $declared) {
            assertSame([$declared, null], [$declared, self::judge('str', $declared)]);
        }
        assertSame(null, self::judge('callable', 'int'));
        assertSame(null, self::judge('unknown', 'int'));
        assertSame(null, self::judge('int', null));
    }

    public function testAClassPinAgainstABuiltinIsRefusedOnlyWithAWholeLineage(): void
    {
        assertSame(false, self::judge('class:app.money.Money', 'int'));
        assertSame(false, self::judge('class:app.money.Change', 'str'));
        assertSame(false, self::judge('class:app.money.Shape', 'int'));
        assertSame(false, self::judge('class:app.money.Money', 'Optional[int]'));
        // An open list (a builtin base), an ancestor outside the project, a class the scan lacks: it may be the builtin.
        assertSame(null, self::judge('class:app.money.Cents', 'int'));
        assertSame(null, self::judge('class:app.money.Colour', 'int'));
        assertSame(null, self::judge('class:app.other.Missing', 'int'));
        assertSame(null, self::judge('class:app.money.Money', 'Money'));
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'unclosed' => ['list[int'];
        yield 'stray close' => ['int]'];
        yield 'empty' => [''];
        yield 'trailing bar' => ['int |'];
        yield 'missing comma' => ['dict[str int]'];
        yield 'a bar before a comma' => ['int | ,'];
        yield 'an open bracketed list' => ['Callable[[int, str]'];
        yield 'a leading comma' => [', int'];
        yield 'a bad bracketed list' => ['[int | ]'];
    }

    #[DataProvider('malformed')]
    public function testAMalformedAnnotationIsUnknown(string $declared): void
    {
        assertSame(null, self::judge('str', $declared));
    }
}

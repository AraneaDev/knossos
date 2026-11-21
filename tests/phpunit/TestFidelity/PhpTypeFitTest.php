<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\TestFidelity\PhpTypeFit;
use Knossos\TestFidelity\TestFidelityGraph;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\TestFidelity\Rules\DriftFixtures;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

#[Group('test-fidelity')]
final class PhpTypeFitTest extends KnossosTestCase
{
    private const FITS = ['fits' => true, 'typed' => true];
    private const REFUSED = ['fits' => false, 'typed' => true];
    private const WARNS = ['fits' => false, 'typed' => false];
    private const UNKNOWN = ['fits' => null, 'typed' => false];

    /**
     * `App\Card` implements `App\Payable`; `App\Visa` extends it and also
     * implements `App\Named`; `App\Ledger` stands alone; `App\FileLog`
     * extends `Monolog\Logger`, which only `vendor/` holds.
     */
    private static function graph(): TestFidelityGraph
    {
        $payable = DriftFixtures::phpType('interface', 'App\Payable', file: 'src/Payable.php');
        $named = DriftFixtures::phpType('interface', 'App\Named', file: 'src/Named.php');
        $card = DriftFixtures::phpType('class', 'App\Card', file: 'src/Card.php');
        $visa = DriftFixtures::phpType('class', 'App\Visa', file: 'src/Visa.php');
        $ledger = DriftFixtures::phpType('class', 'App\Ledger', file: 'src/Ledger.php');
        $fileLog = DriftFixtures::phpType('class', 'App\FileLog', file: 'src/FileLog.php');

        return TestFidelityGraph::fromContributions([new ScanContribution('knossos.php:file:src', [$payable, $named, $card, $visa, $ledger, $fileLog], [
            DriftFixtures::edge('implements', $card, 'php:interface:App\Payable'),
            // The worker writes a name as the source does; PHP matches it in any case.
            DriftFixtures::edge('extends', $visa, 'php:class:app\card'),
            DriftFixtures::edge('implements', $visa, 'php:interface:App\Named'),
            DriftFixtures::edge('extends', $fileLog, 'php:class:Monolog\Logger'),
        ])]);
    }

    /** @return array{fits: bool|null, typed: bool} */
    private static function judge(string $pinned, ?string $declared, ?string $self = null): array
    {
        return PhpTypeFit::judge($pinned, $declared, self::graph(), $self);
    }

    public function testScalarsFollowStrictTypes(): void
    {
        assertSame(self::FITS, self::judge('int', 'int'));
        assertSame(self::REFUSED, self::judge('string', 'int'));
        assertSame(self::REFUSED, self::judge('bool', 'int'));
        assertSame(self::REFUSED, self::judge('int', 'string'));
        assertSame(self::REFUSED, self::judge('float', 'int'));
        assertSame(self::FITS, self::judge('array', 'array'));
        assertSame(self::FITS, self::judge('array', 'iterable'));
        assertSame(self::FITS, self::judge('bool', 'BOOL'), 'A builtin type name is matched in any case.');
    }

    public function testIntFitsFloat(): void
    {
        assertSame(self::FITS, self::judge('int', 'float'));
        assertSame(self::FITS, self::judge('float', 'float'));
    }

    public function testNullNeedsANullableType(): void
    {
        assertSame(self::REFUSED, self::judge('null', 'int'));
        assertSame(self::FITS, self::judge('null', '?int'));
        assertSame(self::FITS, self::judge('null', 'int|null'));
        assertSame(self::FITS, self::judge('null', '(App\Payable&App\Named)|null'));
        assertSame(self::FITS, self::judge('int', '?int'));
        assertSame(self::UNKNOWN, self::judge('null', 'mixed'));
    }

    public function testVoidAndNeverAcceptNoPin(): void
    {
        assertSame(self::REFUSED, self::judge('int', 'void'));
        assertSame(self::REFUSED, self::judge('null', 'void'));
        assertSame(self::REFUSED, self::judge('string', 'never'));
    }

    public function testMixedAndAnAbsentDeclarationAreUnknown(): void
    {
        assertSame(self::UNKNOWN, self::judge('string', 'mixed'));
        assertSame(self::UNKNOWN, self::judge('string', null));
        assertSame(self::UNKNOWN, self::judge('string', ''));
    }

    public function testAClassFitsItselfOrWhatItReaches(): void
    {
        assertSame(self::FITS, self::judge('class:App\Card', 'App\Card'));
        assertSame(self::FITS, self::judge('class:App\Card', 'App\Payable'));
        assertSame(self::FITS, self::judge('class:App\Visa', 'App\Payable'), 'Through the parent, named in another case.');
        assertSame(self::FITS, self::judge('class:app\visa', '\APP\CARD'));
        assertSame(self::FITS, self::judge('class:App\Card', 'object'));
        assertSame(self::REFUSED, self::judge('class:App\Ledger', 'App\Payable'));
        assertSame(self::REFUSED, self::judge('class:App\Card', 'App\Visa'), 'A parent is no child.');
        assertSame(self::REFUSED, self::judge('class:App\Card', 'int'));
        assertSame(self::REFUSED, self::judge('string', 'App\Card'));
    }

    public function testAVendorRelationIsAWarning(): void
    {
        // A type only vendor/ holds, a class whose parent only vendor/ holds, a class the scan does not hold.
        assertSame(self::WARNS, self::judge('class:App\Card', 'Psr\Log\LoggerInterface'));
        assertSame(self::WARNS, self::judge('class:App\FileLog', 'App\Payable'));
        assertSame(self::WARNS, self::judge('class:Monolog\Logger', 'App\Payable'));
        assertSame(self::FITS, self::judge('class:Monolog\Logger', 'Monolog\Logger'), 'A class is itself wherever it lives.');
    }

    public function testAUnionFitsWhenAnyMemberFits(): void
    {
        assertSame(self::FITS, self::judge('string', 'int|string'));
        assertSame(self::REFUSED, self::judge('array', 'int|string'));
        assertSame(self::FITS, self::judge('class:App\Ledger', 'App\Payable|App\Ledger'));
        assertSame(self::WARNS, self::judge('class:App\Ledger', 'App\Payable|Psr\Log\LoggerInterface'), 'One member a vendor type: at most a warning.');
    }

    public function testAnIntersectionFitsWhenEveryMemberFits(): void
    {
        assertSame(self::FITS, self::judge('class:App\Visa', 'App\Payable&App\Named'));
        assertSame(self::REFUSED, self::judge('class:App\Card', 'App\Payable&App\Named'));
        assertSame(self::FITS, self::judge('class:App\Visa', '(App\Payable&App\Named)|null'));
    }

    public function testSelfAndStaticResolveToTheOwner(): void
    {
        assertSame(self::FITS, self::judge('class:App\Visa', 'self', 'App\Card'));
        assertSame(self::FITS, self::judge('class:App\Card', '?static', 'App\Card'));
        assertSame(self::REFUSED, self::judge('class:App\Card', 'static', 'App\Visa'));
        assertSame(self::UNKNOWN, self::judge('class:App\Card', 'self'), 'No owner, nothing to resolve it to.');
    }

    public function testAClosureFitsClosureAndCallable(): void
    {
        assertSame(self::FITS, self::judge('closure', 'Closure'));
        assertSame(self::FITS, self::judge('closure', '\Closure'));
        assertSame(self::FITS, self::judge('closure', 'callable'));
        assertSame(self::FITS, self::judge('closure', 'object'));
        assertSame(self::REFUSED, self::judge('closure', 'string'));
        assertSame(self::REFUSED, self::judge('closure', 'App\Payable'));
        assertSame(self::UNKNOWN, self::judge('string', 'callable'), 'A string may name a function.');
    }

    public function testATypeOutsideTheListIsUnknown(): void
    {
        foreach (['unknown', 'resource', 'class:', 'Int', ''] as $pinned) {
            assertSame(self::UNKNOWN, self::judge($pinned, 'int'), $pinned);
        }
        assertSame(self::UNKNOWN, self::judge('bool', 'true'), 'Which bool the pin is, the type does not say.');
        assertSame(self::UNKNOWN, self::judge('int', '?'), 'A declared type the worker could not write.');
    }

    public function testACoercingDoubleLeavesScalarsAndNullOnVoidOpen(): void
    {
        // Mockery's and Prophecy's generated code is not strict: '5' is coerced to int, and a void method drops what it is given.
        $loose = static fn(string $pinned, string $declared): array => PhpTypeFit::judge($pinned, $declared, self::graph(), null, false);

        assertSame(self::UNKNOWN, $loose('string', 'int'));
        assertSame(self::UNKNOWN, $loose('float', 'int'));
        assertSame(self::UNKNOWN, $loose('bool', 'string'));
        assertSame(self::UNKNOWN, $loose('null', 'void'));
        assertSame(self::FITS, $loose('int', 'int'));
        assertSame(self::REFUSED, $loose('array', 'int'), 'An array is coerced to no scalar.');
        assertSame(self::REFUSED, $loose('class:App\Card', 'int'));
        assertSame(self::REFUSED, $loose('int', 'void'), 'Only null is dropped silently.');
        assertSame(self::REFUSED, $loose('null', 'int'), 'A non-nullable return refuses null in any mode.');
        assertSame(self::REFUSED, self::judge('null', 'void'), 'A strict double still refuses it.');
    }

    public function testACoercingDoubleLeavesAClassThatMayBeStringableOpenAgainstString(): void
    {
        // Ruling R20: outside strict types PHP turns a Stringable object returned for `string` into a string.
        $named = DriftFixtures::phpType('class', 'App\Name', file: 'src/Name.php');
        $child = DriftFixtures::phpType('class', 'App\Child', file: 'src/Child.php');
        $plain = DriftFixtures::phpType('class', 'App\Plain', file: 'src/Plain.php');
        $marked = DriftFixtures::phpType('class', 'App\Marked', file: 'src/Marked.php');
        $toString = DriftFixtures::phpMethod($named, '__toString');
        $graph = TestFidelityGraph::fromContributions([new ScanContribution('knossos.php:file:src', [$named, $child, $plain, $marked, $toString], [
            DriftFixtures::edge('contains', $named, $toString->localId),
            DriftFixtures::edge('extends', $child, 'php:class:App\Name'),
            DriftFixtures::edge('implements', $marked, 'php:interface:Stringable'),
        ])]);
        $loose = static fn(string $class): array => PhpTypeFit::judge('class:' . $class, 'string', $graph, null, false);

        assertSame(self::UNKNOWN, $loose('App\Name'), 'It declares __toString().');
        assertSame(self::UNKNOWN, $loose('App\Child'), 'It inherits __toString().');
        assertSame(self::UNKNOWN, $loose('App\Marked'), 'It implements Stringable, which the scan does not hold.');
        assertSame(self::UNKNOWN, $loose('Vendor\Thing'), 'Outside the project, nothing shows it is not Stringable.');
        assertSame(self::REFUSED, $loose('App\Plain'), 'Nothing in or above it makes a string.');
        assertSame(self::REFUSED, PhpTypeFit::judge('class:App\Name', 'string', $graph), 'A strict double still refuses it.');
        assertSame(self::REFUSED, PhpTypeFit::judge('class:App\Name', 'int', $graph, null, false), 'A Stringable object is coerced to no int.');
    }
}

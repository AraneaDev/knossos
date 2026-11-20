<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\TestFidelity\Facts\Assertion;
use Knossos\TestFidelity\Facts\CallMatcher;
use Knossos\TestFidelity\Facts\Operand;
use Knossos\TestFidelity\Facts\TestCaseFact;
use Knossos\TestFidelity\Facts\TestDouble;
use Knossos\TestFidelity\Rules\TautologyRule;
use Knossos\TestFidelity\TestFidelityGraph;
use Knossos\TestFidelity\TestFinding;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\ScanLimits;
use Knossos\Tests\Phpunit\Support\SchemaSubset;
use PHPUnit\Framework\Attributes\Group;

/**
 * The PHP worker reports a test file's PHPUnit methods and Pest tests as
 * `test_case` nodes: the production calls each makes, the project modules
 * they land in, and its assertions with where each operand's value comes
 * from, in the wire shape the TypeScript worker set. Scanned through the
 * real worker, read back through the core's readers without loss, and
 * judged by the core's tautology rule.
 */
#[Group('php-scanner')]
final class PhpTestCasesTest extends KnossosTestCase
{
    public function testTracesAStubEchoToItsMockConfig(): void
    {
        [$test] = $this->scan('cases', ['tests/EchoTest.php']);
        $cases = self::cases($test);
        $stub = 'tests/EchoTest.php#double:13:17';

        assertSame(['tests/EchoTest.php#test:11', 'tests/EchoTest.php#test:18', 'tests/EchoTest.php#test:30'], array_keys($cases), 'A test* method, a #[Test] method and an @test method; no other public method.');
        assertSame(['testEchoesTheStub', 'echoesAKeptValue', 'comparesAChangedValue'], array_values(array_map(static fn(TestCaseFact $case): string => $case->name, $cases)));
        self::assertEquals([new Assertion('assertSame', 15, [new Operand('literal', '42', null, false), new Operand('mock-config', null, $stub, false, '42')])], $cases['tests/EchoTest.php#test:11']->assertions);
        self::assertEquals([new Operand('literal', '$expected', null, false), new Operand('mock-config', null, 'tests/EchoTest.php#double:22:17', false, '$expected')], $cases['tests/EchoTest.php#test:18']->assertions[0]->operands, 'A variable assigned once is followed to its literal; the pin is kept as written.');
        self::assertEquals([
            new Assertion('assertNotSame', 34, [new Operand('unknown', '$value', null, false), new Operand('unknown', '$value', null, false)], true),
            new Assertion('assertSame', 35, [new Operand('literal', '4', null, false), new Operand('unknown', '$value', null, false)]),
        ], $cases['tests/EchoTest.php#test:30']->assertions, 'A variable changed after it is assigned holds no value the source states.');
        assertSame('tests/EchoTest.php#test:11', self::doubles($test)[13]->testCase, 'A double made in a test belongs to it.');
        self::assertContains(['contains', 'php:module:tests/EchoTest.php', 'php:test_case:tests/EchoTest.php#test:11'], self::edges($test));

        $findings = self::tautologies([$test]);
        assertSame([['mock_echo', 15], ['mock_echo', 24]], array_map(static fn(TestFinding $finding): array => [$finding->subRule, $finding->evidence->startLine], $findings));
    }

    public function testCountsProductionCallsAndSubjects(): void
    {
        [$test] = $this->scan('cases', ['tests/ProductionTest.php']);
        $case = self::cases($test)['tests/ProductionTest.php#test:19'];

        assertSame(3, $case->productionCalls, 'Calculator::make(), a call on the setUp() property bound to new Calculator() and the new Calculator() in a local helper; not the double, its pins, a test-module class or PHP\'s own.');
        assertSame(['src/Billing/Calculator.php'], $case->subjects);
    }

    public function testKeepsAHealthyTestProductionSourced(): void
    {
        [$test] = $this->scan('cases', ['tests/ProductionTest.php']);
        $case = self::cases($test)['tests/ProductionTest.php#test:31'];

        assertSame([
            [['literal', '42'], ['production', '$total']],
            [['literal', '42'], ['production', null]],
            [['literal', '42'], ['production', null]],
        ], array_map(static fn(Assertion $assertion): array => array_map(static fn(Operand $operand): array => [$operand->source, $operand->text], $assertion->operands), $case->assertions));
        assertSame(4, $case->productionCalls);
        assertSame(['src/Billing/Calculator.php'], $case->subjects);
        assertSame([], self::tautologies([$test]));
    }

    public function testReadsPestCases(): void
    {
        [$momus] = $this->scan('momus', ['tests/MockeryPestTest.php']);
        $cases = self::cases($momus);
        assertSame(['tests/MockeryPestTest.php#test:10', 'tests/MockeryPestTest.php#test:17', 'tests/MockeryPestTest.php#test:24', 'tests/MockeryPestTest.php#test:30'], array_keys($cases), 'The file-level function is not a test.');
        self::assertEquals(new Assertion('assertNotSame', 14, [new Operand('mock-call', '$mockeryRepo', 'tests/MockeryPestTest.php#double:12:24', false), new Operand('literal', 'null', null, false)], true), $cases['tests/MockeryPestTest.php#test:10']->assertions[0]);
        assertSame('tests/MockeryPestTest.php#test:24', self::doubles($momus)[26]->testCase);

        [$pest] = $this->scan('cases', ['tests/Feature/billing.php']);
        $cases = self::cases($pest);
        $gateway = self::doubles($pest)[8];
        assertSame(['tests/Feature/billing.php#test:11', 'tests/Feature/billing.php#test:15', 'tests/Feature/billing.php#test:20', 'tests/Feature/billing.php#test:26', 'tests/Feature/billing.php#test:31', 'tests/Feature/billing.php#test:35', 'tests/Feature/billing.php#test:43'], array_keys($cases), 'it() and test() with a closure; a pending it() runs nothing.');
        assertSame(['totals', 'echoes the stub', 'reads a dataset', 'keeps what it expects', 'sets a property', "reads another test's property", 'refunds'], array_values(array_map(static fn(TestCaseFact $case): string => $case->name, $cases)));
        self::assertEquals([new Assertion('toBe', 12, [new Operand('production', null, null, false), new Operand('literal', '42', null, false)])], $cases['tests/Feature/billing.php#test:11']->assertions);
        assertSame([1, ['src/Billing/Calculator.php']], [$cases['tests/Feature/billing.php#test:11']->productionCalls, $cases['tests/Feature/billing.php#test:11']->subjects], 'A beforeEach() property bound to new Calculator().');
        self::assertEquals([
            new Assertion('toBeFalse', 17, [new Operand('mock-config', null, $gateway->canonicalName, false, 'true')], true),
            new Assertion('toBe', 17, [new Operand('literal', 'true', null, false), new Operand('literal', 'true', null, false)]),
        ], $cases['tests/Feature/billing.php#test:15']->assertions, 'The beforeEach() double is configured in the test; ->and() starts a new subject.');
        self::assertEquals([new Assertion('toBeInt', 21, [new Operand('parameter', '$cents', null, false)])], $cases['tests/Feature/billing.php#test:20']->assertions);
        self::assertEquals([new Operand('literal', '$expected', null, false), new Operand('literal', '42', null, false)], $cases['tests/Feature/billing.php#test:26']->assertions[0]->operands, 'expect() reads its value; it does not change it.');
        self::assertEquals([new Operand('unknown', '$this->local', null, false), new Operand('literal', '5', null, false)], $cases['tests/Feature/billing.php#test:35']->assertions[0]->operands, 'A property one Pest test sets is not set in another.');
        assertSame(null, $gateway->testCase, 'A double made in beforeEach() answers to every test.');
        assertSame(['charge', 'refund'], array_map(static fn($member): string => $member->name, $gateway->members));
        self::assertEquals([new Operand('mock-config', null, $gateway->canonicalName, false, 'false')], $cases['tests/Feature/billing.php#test:43']->assertions[0]->operands, 'A pin made in beforeEach() reaches every test of the file.');
    }

    public function testAFileOfTestsWithoutDoublesGainsItsModule(): void
    {
        [$test] = $this->scan('cases', ['tests/Feature/plain.php']);

        assertSame(['tests/Feature/plain.php#test:3'], array_keys(self::cases($test)));
        self::assertContains('php:module:tests/Feature/plain.php', array_map(static fn($node): string => $node->localId, $test->nodes));
        self::assertContains(['contains', 'php:module:tests/Feature/plain.php', 'php:test_case:tests/Feature/plain.php#test:3'], self::edges($test));
    }

    public function testMarksAnUnconfiguredSpy(): void
    {
        [$test] = $this->scan('cases', ['tests/SpyTest.php']);
        $cases = self::cases($test);
        $doubles = self::doubles($test);

        self::assertEquals([new Assertion('shouldHaveReceived', 15, [new Operand('mock-call', '$spy', 'tests/SpyTest.php#double:14:16', true)])], $cases['tests/SpyTest.php#test:12']->assertions);
        self::assertEquals([new Assertion('shouldNotHaveReceived', 22, [new Operand('mock-call', '$spy', 'tests/SpyTest.php#double:20:16', false)], true)], $cases['tests/SpyTest.php#test:18']->assertions, 'A spy the test stubs is configured.');
        assertSame(true, $cases['tests/SpyTest.php#test:25']->assertions[0]->operands[0]->unconfiguredSpy);
        assertSame([0, 0, 1], [$doubles[14]->invocations, $doubles[20]->invocations, $doubles[27]->invocations]);

        assertSame([['charge', 'shouldHaveReceived']], array_map(static fn($member): array => [$member->name, $member->api], $doubles[14]->members), 'A spy assertion names the member it is about.');
        self::assertEquals([new CallMatcher('with', 15, 1, false)], $doubles[14]->members[0]->calledWith);
        self::assertEquals([new CallMatcher('with', 22, 2, false)], $doubles[20]->members[0]->calledWith, 'The argument list shouldNotHaveReceived() is given.');
        assertSame('tests/SpyTest.php#test:12', $doubles[14]->testCase);

        $findings = self::tautologies([$test]);
        assertSame([['unconfigured_spy', 15], ['mock_only', 22]], array_map(static fn(TestFinding $finding): array => [$finding->subRule, $finding->evidence->startLine], $findings), 'The third spy is handed to production, which runs.');
    }

    public function testFollowsHelpersDoublesAndChangedValues(): void
    {
        [$test] = $this->scan('cases', ['tests/FlowTest.php']);
        $cases = self::cases($test);
        $summary = array_map(static fn(TestCaseFact $case): array => [
            $case->productionCalls,
            $case->subjects,
            array_map(static fn(Assertion $assertion): array => [$assertion->api, $assertion->line, array_map(static fn(Operand $operand): array => array_filter([$operand->source, $operand->text, $operand->double, $operand->value], static fn(?string $part): bool => $part !== null), $assertion->operands)], $case->assertions),
        ], $cases);

        assertSame([
            'tests/FlowTest.php#test:12' => [1, ['src/Billing/Calculator.php'], [['assertSame', 14, [['production'], ['unknown']]]]],
            'tests/FlowTest.php#test:17' => [0, [], [
                ['assertSame', 22, [['literal', '9'], ['mock-config', 2 => 'tests/FlowTest.php#double:19:21', 3 => '9']]],
                ['assertSame', 23, [['literal', '9'], ['mock-call', 2 => 'tests/FlowTest.php#double:19:21']]],
            ]],
            'tests/FlowTest.php#test:26' => [0, [], [['assertSame', 29, [['literal', "'cached'"], ['mock-config', 2 => 'tests/FlowTest.php#double:28:9', 3 => "'cached'"]]]]],
            'tests/FlowTest.php#test:32' => [1, [], [['assertTrue', 35, [['production']]]]],
            'tests/FlowTest.php#test:38' => [0, [], [
                ['assertSame', 53, [['literal', '[3, 1]'], ['unknown', '$rows']]],
                ['assertSame', 54, [['literal', '1'], ['unknown', '$first']]],
                ['assertSame', 55, [['literal', '1'], ['unknown', '$limit']]],
                ['assertSame', 56, [['literal', '1'], ['unknown', '$total']]],
                ['assertSame', 57, [['literal', '1'], ['unknown', '$kept']]],
                ['assertSame', 58, [['literal', '0'], ['unknown', '$item']]],
            ]],
            'tests/FlowTest.php#test:66' => [0, [], [['assertTrue', 68, [['production']]]]],
        ], $summary, 'A helper hands back its return\'s source, a literal aside; a Prophecy double answers once revealed; a facade answers what it was stubbed; an inherited request runs production; a value assigned twice, or that `++`, a reference, a destructuring or a foreach may change, is unknown. A class the test file declares runs no production. An @testdox method is no test.');
        assertSame([['mock_echo', 22], ['mock_echo', 29]], array_map(static fn(TestFinding $finding): array => [$finding->subRule, $finding->evidence->startLine], self::tautologies([$test])));
    }

    public function testAValueTheSourceDoesNotProveIsUnknown(): void
    {
        [$writes, $shared] = $this->scan('cases', ['tests/WriteTest.php', 'tests/SharedTest.php']);
        $operands = static fn(ScanContribution $contribution): array => array_map(static fn(TestCaseFact $case): array => array_map(
            static fn(Assertion $assertion): array => array_map(static fn(Operand $operand): array => array_filter([$operand->source, $operand->text, $operand->value], static fn(?string $part): bool => $part !== null), $assertion->operands),
            $case->assertions,
        ), self::cases($contribution));

        assertSame([
            'tests/WriteTest.php#test:7' => [[['literal', "['a']"], ['unknown', '$items']]],
            'tests/WriteTest.php#test:13' => [[['literal', '[]'], ['unknown', '$map']]],
            'tests/WriteTest.php#test:19' => [[['literal', 'true'], ['unknown', '$this->fired']]],
            'tests/WriteTest.php#test:26' => [[['literal', 'true']]],
            'tests/WriteTest.php#test:30' => [[['literal', '1'], ['mock-call']]],
            'tests/WriteTest.php#test:37' => [[['literal', "'x'"], ['mock-call']]],
            'tests/WriteTest.php#test:45' => [[['literal', '1'], ['parameter', '$n']]],
            'tests/WriteTest.php#test:49' => [[['literal', '[1]'], ['unknown', '$this->list']]],
            'tests/WriteTest.php#test:54' => [[['literal', '[1, 2]'], ['unknown', '$ids']]],
            'tests/WriteTest.php#test:62' => [[['literal', '0'], ['unknown', '$this->count']]],
            'tests/WriteTest.php#test:68' => [[['literal', '1'], ['literal', '$x']]],
            'tests/WriteTest.php#test:73' => [[['literal', 'true']], [['literal', 'null']], [['literal', '1'], ['literal', '[1]']]],
            'tests/WriteTest.php#test:79' => [[['literal', '1'], ['mock-call']]],
            'tests/WriteTest.php#test:85' => [[['literal', "'x'"], ['mock-call']], [['literal', '1'], ['mock-call']], [['literal', '1'], ['mock-call']], [['literal', '1']]],
        ], $operands($writes), 'A value an element write, an unset, a callback, a loop or an argument may change is unknown, and so is a pin that is one of several in turn or for some arguments only; a one-value assertion has one operand, its message none; a value nothing changes stays literal.');
        assertSame([
            'tests/SharedTest.php#test:12' => [[['mock-call', '$this->stub']]],
            'tests/SharedTest.php#test:17' => [[['literal', '1'], ['mock-call']]],
            'tests/SharedTest.php#test:21' => [[['literal', '5'], ['mock-config', 2 => '5']]],
            'tests/SharedTest.php#test:25' => [[['literal', '5'], ['mock-call']]],
        ], $operands($shared), 'A pin made in another test does not reach this one; one made in setUp() does, unless the test shapes the member again.');

        $findings = static fn(array $contributions): array => array_map(static fn(TestFinding $finding): array => [$finding->subRule, $finding->confidence->value, $finding->evidence->startLine], self::tautologies($contributions));
        assertSame([['constant', 'definite', 71]], $findings([$writes]), 'Only the value nothing changes is a constant; assertTrue(true) with or without a message is silent.');
        assertSame([['mock_only', 'warning', 15], ['mock_echo', 'definite', 23]], $findings([$shared]), 'Asserting on a double alone, with no production run, is the mock-only warning; only the setUp() pin that alone reaches its test echoes.');
    }

    public function testAWorkerReadsComposerAfreshForEachRequest(): void
    {
        $root = sys_get_temp_dir() . '/knossos-php-cases-' . bin2hex(random_bytes(6));
        mkdir($root . '/tests', 0o777, true);
        mkdir($root . '/src');
        mkdir($root . '/lib');
        try {
            file_put_contents($root . '/composer.json', '{"autoload": {"psr-4": {"App\\\\": "src/"}}}');
            file_put_contents($root . '/src/Thing.php', "<?php\nnamespace App;\nfinal class Thing {}\n");
            file_put_contents($root . '/tests/ThingTest.php', "<?php\nfinal class ThingTest extends \\PHPUnit\\Framework\\TestCase\n{\n    public function testMakes(): void\n    {\n        \\App\\Thing::make();\n    }\n}\n");
            $client = $this->phpWorkerClient();
            try {
                $subjects = static function () use ($client, $root): array {
                    $contributions = iterator_to_array($client->scan(['limits' => ScanLimits::DEFAULT, 'root' => $root, 'files' => ['tests/ThingTest.php']]), false);
                    $cases = array_values(array_filter($contributions[0]->nodes, static fn($node): bool => $node->kind === 'test_case'));

                    return TestCaseFact::fromNode($cases[0])->subjects ?? [];
                };
                assertSame(['src/Thing.php'], $subjects());
                file_put_contents($root . '/composer.json', '{"autoload": {"psr-4": {"App\\\\": "lib/"}}}');
                rename($root . '/src/Thing.php', $root . '/lib/Thing.php');
                assertSame(['lib/Thing.php'], $subjects(), 'The same worker, a second request: the moved class is found where composer now maps it.');
            } finally {
                $client->shutdown();
            }
        } finally {
            foreach (['tests/ThingTest.php', 'lib/Thing.php', 'src/Thing.php', 'composer.json'] as $file) {
                @unlink($root . '/' . $file);
            }
            foreach (['tests', 'src', 'lib', ''] as $directory) {
                @rmdir($root . '/' . $directory);
            }
        }
    }

    public function testEmittedTestCasesFitTheWireSchema(): void
    {
        $schema = json_decode((string) file_get_contents(self::repositoryRoot() . '/schemas/scanner/v1/contribution.schema.json'), true, 512, JSON_THROW_ON_ERROR);
        require_once self::repositoryRoot() . '/workers/php/vendor/autoload.php';
        $root = self::repositoryRoot() . '/tests/Fixtures/test-fidelity/php/cases';
        $checked = 0;
        foreach (['tests/EchoTest.php', 'tests/ProductionTest.php', 'tests/SpyTest.php', 'tests/FlowTest.php', 'tests/WriteTest.php', 'tests/SharedTest.php', 'tests/Feature/billing.php', 'tests/Feature/plain.php'] as $file) {
            $contribution = json_decode((string) json_encode((new \KnossosPhpScanner\PhpScanner())->scan($root, $root . '/' . $file, $file)), true, 512, JSON_THROW_ON_ERROR);
            assertSame([], $contribution['diagnostics'], $file);
            foreach ([...$contribution['nodes'], ...$contribution['edges']] as $fact) {
                $definition = isset($fact['local_id']) ? 'node' : 'edge';
                assertSame([], SchemaSubset::errors(['$ref' => '#/$defs/' . $definition], $fact, $schema), $file);
                $checked += ($fact['kind'] ?? '') === 'test_case' ? 1 : 0;
            }
        }
        assertSame(40, $checked);
    }

    /**
     * Scan fixture files through the worker, one contribution per file in the order given.
     *
     * @param list<string> $files
     * @return list<ScanContribution>
     */
    private function scan(string $fixture, array $files): array
    {
        $client = $this->phpWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan(['limits' => ScanLimits::DEFAULT,
                'root' => self::repositoryRoot() . '/tests/Fixtures/test-fidelity/php/' . $fixture,
                'files' => $files,
            ]), false);
        } finally {
            $client->shutdown();
        }
        $byOwner = [];
        foreach ($contributions as $contribution) {
            $byOwner[$contribution->ownerKey] = $contribution;
        }

        return array_map(static fn(string $file): ScanContribution => $byOwner['knossos.php:file:' . $file], $files);
    }

    /**
     * The contribution's test cases by canonical name, every one read back without loss.
     *
     * @return array<string, TestCaseFact>
     */
    private static function cases(ScanContribution $contribution): array
    {
        $cases = [];
        foreach ($contribution->nodes as $node) {
            if ($node->kind !== 'test_case') {
                continue;
            }
            $case = TestCaseFact::fromNode($node);
            self::assertNotNull($case, $node->canonicalName);
            self::assertEquals($node->attributes, self::wire($case), 'The reader keeps every attribute the worker wrote.');
            assertSame('php:test_case:' . $case->canonicalName, $node->localId);
            $cases[$case->canonicalName] = $case;
        }

        return $cases;
    }

    /**
     * The contribution's doubles by the line each is made on.
     *
     * @return array<int, TestDouble>
     */
    private static function doubles(ScanContribution $contribution): array
    {
        $doubles = [];
        foreach ($contribution->nodes as $node) {
            $double = TestDouble::fromNode($node);
            if ($double !== null) {
                $doubles[$node->evidence->startLine] = $double;
            }
        }

        return $doubles;
    }

    /**
     * A test case as the wire states it, rebuilt from what the reader kept.
     *
     * @return array<string, mixed>
     */
    private static function wire(TestCaseFact $case): array
    {
        $present = static fn(mixed $value): bool => $value !== null && $value !== false;

        return [
            'name' => $case->name,
            'production_calls' => $case->productionCalls,
            'subjects' => $case->subjects,
            'assertions' => array_map(static fn(Assertion $assertion): array => array_filter([
                'api' => $assertion->api,
                'line' => $assertion->line,
                'negated' => $assertion->negated,
                'operands' => array_map(static fn(Operand $operand): array => array_filter([
                    'source' => $operand->source,
                    'text' => $operand->text,
                    'value' => $operand->value,
                    'double' => $operand->double,
                    'unconfigured_spy' => $operand->unconfiguredSpy,
                ], $present), $assertion->operands),
            ], static fn(mixed $value): bool => $value !== false), $case->assertions),
        ];
    }

    /**
     * The tautology rule's findings over the contributions.
     *
     * @param list<ScanContribution> $contributions
     * @return list<TestFinding>
     */
    private static function tautologies(array $contributions): array
    {
        return [...(new TautologyRule())->findings(TestFidelityGraph::fromContributions($contributions))];
    }

    /** @return list<array{string, string, string}> */
    private static function edges(ScanContribution $contribution): array
    {
        return array_map(static fn($edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference], $contribution->edges);
    }
}

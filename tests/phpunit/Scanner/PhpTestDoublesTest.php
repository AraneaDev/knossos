<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\TestFidelity\Facts\CallMatcher;
use Knossos\TestFidelity\Facts\PinnedValue;
use Knossos\TestFidelity\Facts\StubbedMember;
use Knossos\TestFidelity\Facts\TestDouble;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\ScanLimits;
use Knossos\Tests\Phpunit\Support\SchemaSubset;
use PHPUnit\Framework\Attributes\Group;

/**
 * The PHP worker reports a test file's PHPUnit, Mockery, Pest and Prophecy
 * doubles as `test_double` nodes, in the wire shape the TypeScript worker
 * set, scanned through the real worker and read back through the core's
 * readers without loss.
 */
#[Group('php-scanner')]
final class PhpTestDoublesTest extends KnossosTestCase
{
    public function testRecordsAPhpunitDoubleWithMemberPinAndEdge(): void
    {
        [$production, $test] = $this->scan('momus', ['src/InvoiceRepository.php', 'tests/InvoiceTest.php']);
        $doubles = $this->doubles($test);

        $double = $doubles[13];
        assertSame('tests/InvoiceTest.php#double:13:17', $double->canonicalName);
        assertSame(['phpunit', 'createMock', ['kind' => 'class', 'export' => 'App\InvoiceRepository'], true, 0], [$double->framework, $double->pattern, $double->target, $double->automock, $double->invocations]);
        assertSame([['findById', 14, 'method'], ['fetchAll', 15, 'method']], array_map(static fn(StubbedMember $member): array => [$member->name, $member->line, $member->api], $double->members));
        self::assertEquals([new PinnedValue('willReturn', 14, false, 'unknown', true, 'class:App\Tests\Invoice')], $double->members[0]->returns);
        assertSame([], $double->members[1]->returns);
        assertSame(['int', 1], [$doubles[21]->members[0]->returns[0]->type, $doubles[21]->invocations]);
        assertSame([13, 21, 28], array_keys($doubles));

        $id = 'php:test_double:tests/InvoiceTest.php#double:13:17';
        self::assertContains(['contains', 'php:module:tests/InvoiceTest.php', $id], self::edges($test));
        self::assertContains(['doubles', $id, 'php:class:App\InvoiceRepository'], self::edges($test));
        self::assertContains('php:module:tests/InvoiceTest.php', array_map(static fn($node): string => $node->localId, $test->nodes));
        self::assertContains('php:class:App\InvoiceRepository', array_map(static fn($node): string => $node->localId, $production->nodes), 'The edge lands on the class node the production scan emits.');
        assertSame([], array_values(array_filter($production->nodes, static fn($node): bool => $node->kind === 'test_double' || $node->kind === 'module')), 'Production code reports no doubles and gains no module.');
    }

    public function testATestFileBesideTheCodeIsReadByItsName(): void
    {
        [$test] = $this->scan('doubles', ['src/Billing/FooTest.php']);

        assertSame([11], array_keys($this->doubles($test)));
    }

    public function testEachPestClosureIsAScopeOfItsOwn(): void
    {
        [$test] = $this->scan('doubles', ['tests/Feature/charging.php']);
        $doubles = $this->doubles($test);

        assertSame(['pest', 'pest.mock'], [$doubles[6]->framework, $doubles[6]->pattern]);
        assertSame([6 => ['charge'], 12 => ['refund']], array_map(static fn(TestDouble $double): array => array_map(static fn(StubbedMember $member): string => $member->name, $double->members), $doubles), 'Another test imports a $foo its file never bound, and a closure parameter shadows the name.');
    }

    public function testRecordsMockeryAndPestDoubles(): void
    {
        [$test] = $this->scan('momus', ['tests/MockeryPestTest.php']);
        $doubles = $this->doubles($test);

        assertSame([12, 19, 26, 32, 41], array_keys($doubles));
        $summary = array_map(static fn(TestDouble $double): array => [
            $double->framework,
            $double->pattern,
            $double->target['export'] ?? null,
            array_map(static fn(StubbedMember $member): array => [$member->name, $member->api, array_map(static fn(PinnedValue $pin): ?string => $pin->type, $member->returns)], $double->members),
        ], $doubles);
        assertSame([
            12 => ['mockery', 'Mockery::mock', 'App\InvoiceRepository', [['fetchAll', 'shouldReceive', ['array']]]],
            19 => ['mockery', 'Mockery::spy', 'App\InvoiceRepository', [['findById', 'shouldReceive', ['class:App\Tests\Invoice']]]],
            26 => ['mockery', 'Mockery::mock', 'App\InvoiceRepository', [['findById', 'shouldReceive', ['class:App\Tests\Invoice']]]],
            32 => ['mockery', 'Mockery::mock', 'App\InvoiceRepository', [['fetchAll', 'shouldReceive', ['array']]]],
            41 => ['pest', 'pest.mock', 'App\InvoiceRepository', [['fetchAll', 'shouldReceive', ['array']]]],
        ], $summary);
        assertSame(33, $doubles[32]->members[0]->line, 'A closure parameter binds the double the closure configures.');
    }

    public function testRecordsAProphecyDouble(): void
    {
        [$test] = $this->scan('doubles', ['tests/ProphecyTest.php']);
        $double = $this->doubles($test)[16];

        assertSame(['prophecy', 'prophesize', ['kind' => 'class', 'export' => 'App\Billing\Foo'], false], [$double->framework, $double->pattern, $double->target, $double->automock]);
        assertSame([['charge', 17, 'prophecy'], ['audit', 19, 'prophecy']], array_map(static fn(StubbedMember $member): array => [$member->name, $member->line, $member->api], $double->members));
        self::assertEquals([new CallMatcher('prophecy', 17, 2, false), new CallMatcher('prophecy', 18, 1, true)], $double->members[0]->calledWith);
        self::assertEquals([new CallMatcher('prophecy', 19, 0, false)], $double->members[1]->calledWith);
        assertSame([[17, 'int'], [18, 'string']], array_map(static fn(PinnedValue $pin): array => [$pin->line, $pin->type], $double->members[0]->returns));
        assertSame(2, $double->invocations, 'The revealed object handed off twice, once through a variable.');
    }

    public function testBindsASetupDoubleAcrossMethods(): void
    {
        [$test] = $this->scan('momus', ['tests/SetUpMockTest.php']);
        $doubles = $this->doubles($test);

        assertSame([14], array_keys($doubles), 'One double, made in setUp(), configured in three tests.');
        assertSame([['deleteById', 19, ['null']], ['findById', 25, ['int']], ['save', 31, ['null']]], array_map(static fn(StubbedMember $member): array => [$member->name, $member->line, array_map(static fn(PinnedValue $pin): ?string => $pin->type, $member->returns)], $doubles[14]->members));
        assertSame(1, $doubles[14]->invocations);
    }

    public function testAReassignedVariableHoldsEachDoubleInTurn(): void
    {
        [$test] = $this->scan('momus', ['tests/ReassignTest.php']);

        assertSame([13 => ['save'], 16 => ['findById']], array_map(static fn(TestDouble $double): array => array_map(static fn(StubbedMember $member): string => $member->name, $double->members), $this->doubles($test)));
    }

    public function testAPropertyBindsOnlyTheRestOfItsMethodOutsideSetUp(): void
    {
        [$test] = $this->scan('doubles', ['tests/PropertyScopeTest.php']);
        $doubles = $this->doubles($test);

        assertSame([['first']], array_map(static fn(TestDouble $double): array => array_map(static fn(StubbedMember $member): string => $member->name, $double->members), array_values($doubles)));
    }

    public function testResolvesAliasesAndStringNames(): void
    {
        [$test] = $this->scan('doubles', ['tests/ResolutionTest.php']);
        $doubles = $this->doubles($test);

        foreach ([14 => 'createMock', 15 => 'Mockery::mock', 16 => 'Mockery::spy', 17 => 'Mockery::mock', 18 => 'Mockery::mock'] as $line => $pattern) {
            assertSame([$pattern, ['kind' => 'class', 'export' => 'App\Billing\Foo']], [$doubles[$line]->pattern, $doubles[$line]->target], "line {$line}");
            self::assertContains(['doubles', 'php:test_double:' . $doubles[$line]->canonicalName, 'php:class:App\Billing\Foo'], self::edges($test));
        }
        assertSame([['charge', 17, 'partial']], array_map(static fn(StubbedMember $member): array => [$member->name, $member->line, $member->api], $doubles[17]->members));
        assertSame([true, [['audit', 19]]], [$doubles[18]->allowsProtected, array_map(static fn(StubbedMember $member): array => [$member->name, $member->line], $doubles[18]->members)]);
        assertSame(false, $doubles[14]->allowsProtected);
        foreach ([31, 32, 33] as $line) {
            assertSame(['kind' => 'unknown'], $doubles[$line]->target, "A computed class name gives no target (line {$line}).");
        }
    }

    public function testAFacadeDoubleHasAnUnknownTarget(): void
    {
        [$test] = $this->scan('doubles', ['tests/ResolutionTest.php']);
        $doubles = $this->doubles($test);
        $facade = $doubles[24];

        assertSame(['mockery', 'Facade::shouldReceive', ['kind' => 'unknown']], [$facade->framework, $facade->pattern, $facade->target]);
        assertSame([['get', 24], ['put', 25]], array_map(static fn(StubbedMember $member): array => [$member->name, $member->line], $facade->members));
        self::assertEquals([new CallMatcher('with', 24, 1, false)], $facade->members[0]->calledWith);
        assertSame('string', $facade->members[0]->returns[0]->type);
        assertSame(false, isset($doubles[25]), 'Every call on one facade configures the one double.');
        $edges = array_filter(self::edges($test), static fn(array $edge): bool => $edge[0] === 'doubles' && $edge[1] === 'php:test_double:' . $facade->canonicalName);
        assertSame([], $edges, 'A facade double names nothing to hold it against.');
    }

    public function testSkipsDynamicMemberNames(): void
    {
        [$dynamic, $edgeCases] = $this->scan('', ['doubles/tests/DynamicTest.php', 'momus/tests/EdgeCasesTest.php']);

        $doubles = $this->doubles($dynamic);
        assertSame([13, 15, 17, 19], array_keys($doubles));
        foreach ($doubles as $line => $double) {
            assertSame([], $double->members, "line {$line}");
        }
        $momus = $this->doubles($edgeCases);
        assertSame([[], 1], [$momus[30]->members, $momus[30]->invocations]);
        assertSame(['kind' => 'unknown'], $momus[17]->target);
    }

    public function testRecordsWithArgumentsAsCallMatchers(): void
    {
        [$test] = $this->scan('doubles', ['tests/MatchersTest.php']);
        $doubles = $this->doubles($test);

        self::assertEquals([new CallMatcher('with', 13, 3, false), new CallMatcher('with', 14, 1, true)], $doubles[12]->members[0]->calledWith);
        self::assertEquals([
            new CallMatcher('with', 20, 1, false),
            new CallMatcher('withArgs', 21, 2, false),
            new CallMatcher('withArgs', 22, 0, true),
            new CallMatcher('withArgs', 23, 0, true),
            new CallMatcher('withNoArgs', 24, 0, false),
        ], $doubles[19]->members[0]->calledWith);
        assertSame([1, 1], [count($doubles[12]->members), count($doubles[19]->members)]);
    }

    public function testTypesPinnedLiterals(): void
    {
        [$test] = $this->scan('doubles', ['tests/PinsTest.php']);
        $members = [];
        foreach ($this->doubles($test)[14]->members as $member) {
            $members[$member->name] = array_map(static fn(PinnedValue $pin): array => [$pin->api, $pin->type, $pin->typed, $pin->assignable], $member->returns);
        }

        assertSame([
            'a' => [['willReturn', 'string', true, 'unknown']],
            'b' => [['willReturn', 'int', true, 'unknown']],
            'c' => [['willReturn', 'class:App\Invoice', true, 'unknown']],
            'd' => [['willReturn', 'unknown', false, 'unknown']],
            'e' => array_map(static fn(string $type): array => ['willReturn', $type, true, 'unknown'], ['float', 'int', 'bool', 'null', 'array', 'closure']),
            'f' => [['willReturnCallback', 'unknown', false, 'unknown']],
            'g' => [],
        ], $members);
    }

    public function testReadsBuilderConfiguredAndPartialMembers(): void
    {
        [$test] = $this->scan('doubles', ['tests/PinsTest.php']);
        $doubles = $this->doubles($test);
        $summary = static fn(TestDouble $double): array => [
            $double->pattern,
            $double->automock,
            array_map(static fn(StubbedMember $member): array => [$member->name, $member->line, $member->api, array_map(static fn(PinnedValue $pin): array => [$pin->api, $pin->type], $member->returns)], $double->members),
        ];

        assertSame(['getMockBuilder', false, [['charge', 28, 'partial', [['willReturn', 'int']]]]], $summary($doubles[26]), 'addMethods() names members the class lacks on purpose.');
        assertSame(['createConfiguredMock', false, [['charge', 32, 'configured', [['configured', 'int']]]]], $summary($doubles[32]));
        assertSame(['createPartialMock', false, [['charge', 33, 'partial', []]]], $summary($doubles[33]));
        assertSame(['createStub', true, []], $summary($doubles[34]));
        assertSame(['getMockForAbstractClass', false, []], $summary($doubles[35]));
    }

    public function testEmittedDoublesFitTheWireSchema(): void
    {
        $schema = json_decode((string) file_get_contents(self::repositoryRoot() . '/schemas/scanner/v1/contribution.schema.json'), true, 512, JSON_THROW_ON_ERROR);
        require_once self::repositoryRoot() . '/workers/php/vendor/autoload.php';
        $root = self::repositoryRoot() . '/tests/Fixtures/test-fidelity/php';
        $checked = 0;
        foreach (['momus/tests/InvoiceTest.php', 'momus/tests/MockeryPestTest.php', 'doubles/tests/ProphecyTest.php', 'doubles/tests/ResolutionTest.php', 'doubles/tests/MatchersTest.php', 'doubles/tests/PinsTest.php'] as $file) {
            $contribution = json_decode((string) json_encode((new \KnossosPhpScanner\PhpScanner())->scan($root, $root . '/' . $file, $file)), true, 512, JSON_THROW_ON_ERROR);
            foreach ([...$contribution['nodes'], ...$contribution['edges']] as $fact) {
                $definition = isset($fact['local_id']) ? 'node' : 'edge';
                assertSame([], SchemaSubset::errors(['$ref' => '#/$defs/' . $definition], $fact, $schema), $file);
                $checked += ($fact['kind'] ?? '') === 'test_double' ? 1 : 0;
            }
        }
        assertSame(true, $checked > 20);
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
                'root' => rtrim(self::repositoryRoot() . '/tests/Fixtures/test-fidelity/php/' . $fixture, '/'),
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
     * The contribution's doubles by the line each is made on, every one read back without loss.
     *
     * @return array<int, TestDouble>
     */
    private function doubles(ScanContribution $contribution): array
    {
        $doubles = [];
        foreach ($contribution->nodes as $node) {
            if ($node->kind !== 'test_double') {
                continue;
            }
            $double = TestDouble::fromNode($node);
            self::assertNotNull($double, $node->canonicalName);
            self::assertEquals($node->attributes, self::wire($double), 'The reader keeps every attribute the worker wrote.');
            assertSame('php:test_double:' . $double->canonicalName, $node->localId);
            self::assertArrayNotHasKey($node->evidence->startLine, $doubles);
            $doubles[$node->evidence->startLine] = $double;
        }
        ksort($doubles);

        return $doubles;
    }

    /**
     * A double as the wire states it, rebuilt from what the reader kept.
     *
     * @return array<string, mixed>
     */
    private static function wire(TestDouble $double): array
    {
        $present = static fn(mixed $value): bool => $value !== null;

        return array_filter([
            'framework' => $double->framework,
            'pattern' => $double->pattern,
            'target' => $double->target,
            'automock' => $double->automock,
            'invocations' => $double->invocations,
            'allows_protected' => $double->allowsProtected ? true : null,
            'test_case' => $double->testCase,
            'members' => array_map(static fn(StubbedMember $member): array => array_filter([
                'name' => $member->name,
                'line' => $member->line,
                'api' => $member->api,
                'params' => $member->params,
                'called_with' => $member->calledWith === [] ? null : array_map(static fn(CallMatcher $matcher): array => ['api' => $matcher->api, 'line' => $matcher->line, 'count' => $matcher->count, 'spread' => $matcher->spread], $member->calledWith),
                'returns' => array_map(static fn(PinnedValue $pin): array => array_filter(['api' => $pin->api, 'line' => $pin->line, 'once' => $pin->once, 'assignable' => $pin->assignable, 'typed' => $pin->typed, 'type' => $pin->type], $present), $member->returns),
            ], $present), $double->members),
        ], $present);
    }

    /** @return list<array{string, string, string}> */
    private static function edges(ScanContribution $contribution): array
    {
        return array_map(static fn($edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference], $contribution->edges);
    }
}

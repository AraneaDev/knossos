<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner\Worker;

use Knossos\Scanner\Worker\ReadTable;
use Knossos\Scanner\Worker\WorkerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** The rows a worker sends once, and the `reads` map a contribution's row numbers stand for. */
#[Group('worker-protocol')]
final class ReadTableTest extends TestCase
{
    private const A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testRowNumbersBecomeTheReadsMapTheyStandFor(): void
    {
        $table = new ReadTable();
        $table->absorb(['rows' => [['package.json', self::A], ['src/missing.ts', null]]]);
        $table->absorb(['rows' => [['tsconfig.json', self::B]]]);

        assertSame(
            ['owner_key' => 'ts:file:a.ts', 'reads' => ['tsconfig.json' => self::B, 'package.json' => self::A, 'src/missing.ts' => null]],
            $table->expand(['owner_key' => 'ts:file:a.ts', 'reads_ref' => [2, 0, 1]]),
        );
        assertSame(['reads' => ['src/missing.ts' => null]], $table->expand(['reads_ref' => [1, 1]]));
        assertSame(['reads' => []], $table->expand(['reads_ref' => []]));
    }

    public function testParamsWithoutRowNumbersPassUnchanged(): void
    {
        $table = new ReadTable();
        $params = ['owner_key' => 'ts:file:a.ts', 'reads' => ['package.json' => self::A]];

        assertSame($params, $table->expand($params));
        assertSame(['owner_key' => 'ts:file:a.ts'], $table->expand(['owner_key' => 'ts:file:a.ts']));
    }

    /** A path read twice in one request has a row per value, and a contribution names the one it saw. */
    public function testAPathReadAgainWithAnotherValueKeepsBothRows(): void
    {
        $table = new ReadTable();
        $table->absorb(['rows' => [['package.json', self::A], ['package.json', null]]]);

        assertSame(['reads' => ['package.json' => self::A]], $table->expand(['reads_ref' => [0]]));
        assertSame(['reads' => ['package.json' => null]], $table->expand(['reads_ref' => [1]]));

        $error = captureThrows(static fn() => $table->expand(['reads_ref' => [0, 1]]), WorkerException::class);
        assertSame('WORKER_CONTRIBUTION_INVALID', $error->diagnosticCode);
        assertSame('reads_ref names package.json twice, with different values.', $error->getMessage());
    }

    /** A path discovery cannot carry is dropped from a reads map, and its row keeps the numbering in step. */
    public function testARowDiscoveryCannotCarryHoldsItsNumberAndNamesNothing(): void
    {
        $table = new ReadTable();
        $table->absorb(['rows' => [["src/bad\tname.ts", self::A], ['src/b.ts', self::B]]]);

        assertSame(['reads' => ['src/b.ts' => self::B]], $table->expand(['reads_ref' => [0, 1]]));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function malformedRows(): iterable
    {
        yield 'no params' => [null, 'read_table rows must be a list.'];
        yield 'no rows' => [[], 'read_table rows must be a list.'];
        yield 'rows as an object' => [['rows' => ['a' => ['package.json', null]]], 'read_table rows must be a list.'];
        yield 'a row that is not a pair' => [['rows' => [['package.json']]], 'A read_table row must be a path and a SHA-256 or null.'];
        yield 'a row with three fields' => [['rows' => [['package.json', null, null]]], 'A read_table row must be a path and a SHA-256 or null.'];
        yield 'a row as an object' => [['rows' => [['path' => 'package.json', 'hash' => null]]], 'A read_table row must be a path and a SHA-256 or null.'];
        yield 'a row that is a string' => [['rows' => ['package.json']], 'A read_table row must be a path and a SHA-256 or null.'];
        yield 'a path that is not a string' => [['rows' => [[7, null]]], 'A read_table row must be a path and a SHA-256 or null.'];
        yield 'a hash that is not one' => [['rows' => [['package.json', 'abc']]], 'reads for package.json must be null or a lowercase SHA-256 hex digest.'];
    }

    #[DataProvider('malformedRows')]
    public function testMalformedRowsAreRefused(mixed $params, string $message): void
    {
        $error = captureThrows(static fn() => (new ReadTable())->absorb($params), WorkerException::class);

        assertSame('WORKER_CONTRIBUTION_INVALID', $error->diagnosticCode);
        assertSame($message, $error->getMessage());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function malformedReferences(): iterable
    {
        $instead = 'reads_ref must be a list of read_table rows, sent instead of reads.';

        yield 'beside reads' => [['reads_ref' => [0], 'reads' => []], $instead];
        yield 'not a list' => [['reads_ref' => ['a' => 0]], $instead];
        yield 'not an array' => [['reads_ref' => 0], $instead];
        yield 'a row never sent' => [['reads_ref' => [0, 1]], 'reads_ref names row 1, which no read_table part has sent.'];
        yield 'a negative row' => [['reads_ref' => [-1]], 'reads_ref names row -1, which no read_table part has sent.'];
        yield 'a row named by a string' => [['reads_ref' => ['0']], 'reads_ref names row string, which no read_table part has sent.'];
    }

    /** @param array<string, mixed> $params */
    #[DataProvider('malformedReferences')]
    public function testMalformedRowNumbersAreRefused(array $params, string $message): void
    {
        $table = new ReadTable();
        $table->absorb(['rows' => [['package.json', self::A]]]);

        $error = captureThrows(static fn() => $table->expand($params), WorkerException::class);

        assertSame('WORKER_CONTRIBUTION_INVALID', $error->diagnosticCode);
        assertSame($message, $error->getMessage());
    }
}

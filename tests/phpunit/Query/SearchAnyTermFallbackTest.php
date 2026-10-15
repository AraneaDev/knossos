<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Every term must match, so a description with one word the code never uses
 * found nothing at all: "source hash stale binary" missed WorkerSourceHash.
 * When nothing matches every term, the search now ranks what matches some.
 */
final class SearchAnyTermFallbackTest extends KnossosTestCase
{
    #[Group('query')]
    public function testAQueryNothingMatchesWhollyRanksWhatMatchesMostTerms(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);

        $result = ArchitectureQueryService::forDatabase($pdo)->searchArchitecture($ids['project'], 'invoice service billing');

        self::assertSame('any_term', $result->data['match']);
        self::assertSame('App\\InvoiceService', $result->data['results'][0]['canonical_name'], 'Two of the three terms beat one.');
        self::assertStringContainsString('No component matches every term', $result->summary);
    }

    /** A query that matches wholly is answered as before, and says so. */
    #[Group('query')]
    public function testAWholeMatchIsAnsweredAsBefore(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);

        $result = ArchitectureQueryService::forDatabase($pdo)->searchArchitecture($ids['project'], 'invoice service');

        self::assertSame('all_terms', $result->data['match']);
        self::assertSame(['App\\InvoiceService'], array_column($result->data['results'], 'canonical_name'));
    }

    /** A page past the end of a whole match is empty; it does not switch to the looser search. */
    #[Group('query')]
    public function testAPagePastAWholeMatchStaysEmpty(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);

        $result = ArchitectureQueryService::forDatabase($pdo)->searchArchitecture($ids['project'], 'invoice service', offset: 5);

        self::assertSame([], $result->data['results']);
        self::assertSame('all_terms', $result->data['match']);
    }
}

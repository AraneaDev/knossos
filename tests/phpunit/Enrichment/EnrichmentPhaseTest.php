<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Enrichment;

use Knossos\Configuration\EnrichersFile;
use Knossos\Configuration\ProjectConfiguration;
use Knossos\Enrichment\EnrichmentPhase;
use Knossos\Scanner\Protocol\Diagnostic;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

use PHPUnit\Framework\Attributes\Group;

/** What the enrichment step does when something under it fails: it reports, it never throws into the scan. */
#[Group('enrichment')]
final class EnrichmentPhaseTest extends KnossosTestCase
{
    public function testAFailingCarryOverBecomesTheEnrichersDiagnostic(): void
    {
        $installation = sys_get_temp_dir() . '/knossos-stale-phase-' . bin2hex(random_bytes(6));
        mkdir($installation);
        try {
            file_put_contents($installation . '/enrichers.json', '{"enrichers": [{"name": "fixture", "command": ["unused"]}]}');
            // A database without the graph tables: reading the previous facts throws.
            $phase = new EnrichmentPhase(new PDO('sqlite::memory:'), new EnrichersFile($installation . '/enrichers.json'));

            $contributions = $phase->contributions(new ProjectConfiguration(enrichers: ['fixture']), 'project', 'scan', '/tmp', [], [], false);

            assertCount(1, $contributions);
            assertSame('enricher:fixture', $contributions[0]->ownerKey);
            assertSame([[], []], [$contributions[0]->nodes, $contributions[0]->edges]);
            $codes = array_map(static fn(Diagnostic $diagnostic): string => $diagnostic->code, $contributions[0]->diagnostics);
            assertSame(['ENRICHER_FAILED'], $codes);
            assertStringContainsString('internal_error', $contributions[0]->diagnostics[0]->message);
        } finally {
            $this->removeTempTree($installation);
        }
    }
}

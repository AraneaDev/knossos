<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Worker\ProcessScannerClient;
use Knossos\TestFidelity\Facts\EnumCases;
use Knossos\TestFidelity\Facts\FieldList;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\ScanLimits;
use Knossos\Tests\Phpunit\Support\SchemaSubset;

use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * Each worker's `fields` and `cases` fit the wire schema over the
 * fixture-drift tree, and the core reads each list it sends; an open list
 * reads as none.
 */
#[Group('test-fidelity')]
final class FieldFactsSchemaTest extends KnossosTestCase
{
    #[Group('slow')]
    public function testWorkerOutputOnTheFixturesValidates(): void
    {
        $lists = $this->lists(['ts', 'php', 'py']);

        // An open list (a rename, an alias generator, a decorator, an ignored key) reads as none.
        assertSame([
            'AccountRecord' => ['accountId', 'name', 'nickname'],
            'AddressRecord' => ['city', 'street'],
            'Article' => ['id', 'tags', 'title'],
            'Bag' => ['id'],
            'BagTest' => [],
            'Basket' => ['count', 'items', 'owner'],
            'CamelOrder' => [],
            'CargoRecord' => ['express', 'id', 'meta', 'mode', 'sentAt', 'tags', 'weight'],
            'Consignment' => ['billing_address', 'shipping_address'],
            'ConsignmentRecord' => ['destination', 'reference', 'stops', 'weightKg'],
            'Coupon' => ['code', 'percent'],
            'Courier' => ['POST' => 'post', 'VAN' => 'van'],
            'Customer' => ['cache', 'customerId', 'passwordHash'],
            'Envelope' => ['stamp'],
            'ExposedRecord' => [],
            'IgnoredSecret' => [],
            'Invoice' => ['lines', 'memo', 'rate', 'total'],
            'JmsUser' => [],
            'Labelled' => [],
            'Lane' => ['Fast' => 'fast', 'Slow' => 'slow'],
            'LaneRecord' => ['id', 'lane'],
            'Manifest' => ['courier', 'reference'],
            'Member' => ['id', 'nickname', 'tier'],
            'Order' => ['note', 'order_id', 'tags'],
            'Parcel' => ['courier', 'id', 'label', 'weight_kg'],
            'PostalAddress' => ['city_name', 'street_name'],
            'Price' => ['cents', 'currency'],
            'Priority' => ['High' => null, 'Low' => null],
            'Rate' => ['basisPoints'],
            'RenamedUser' => [],
            'RouteRecord' => ['destination', 'lookup', 'reference', 'stops'],
            'Sack' => ['id'],
            'Settings' => ['name'],
            'ShipmentRecord' => ['express', 'id', 'weight'],
            'ShippingAddress' => ['city', 'street'],
            'SnakeRecord' => ['order_id', 'placed_at'],
            'Speed' => ['Fast' => 'fast', 'Slow' => 'slow'],
            'Stop' => ['city', 'notes'],
            'Tier' => ['Free' => 'free', 'Gold' => 'gold'],
            'TripRecord' => ['id', 'note', 'priority', 'speed'],
            'UserRecord' => ['email', 'id', 'name'],
            'Vault' => ['hash', 'tag', 'vaultId'],
        ], $lists);
    }

    /** The schema says what the PHP worker sends and FixtureTypes compares: a class name fully qualified. */
    public function testTheSchemaSaysAPhpFieldTypeIsFullyQualified(): void
    {
        $schema = json_decode((string) file_get_contents(self::repositoryRoot() . '/schemas/scanner/v1/contribution.schema.json'), true, 512, JSON_THROW_ON_ERROR);

        assertSame(
            "The field's type as the source writes it, but a PHP class name fully qualified; absent when it writes none.",
            $schema['$defs']['fieldList']['items']['properties']['type']['description'],
        );
    }

    public function testTheRustWorkerOutputOnTheFixturesValidates(): void
    {
        if (!is_executable(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker is not built.');
        }

        assertSame([
            'Bundle' => [],
            'Carton' => [],
            'Dock' => ['North' => 'North', 'South' => 'South'],
            'Pallet' => ['dock', 'id', 'label', 'layers', 'note'],
            'Quote' => ['price', 'quantity'],
            'Tracking' => [],
        ], $this->lists(['rs']));
    }

    /**
     * Scan the fixture tree with the workers of `$extensions`, check every fact
     * against the schema, and read each list sent, by the node's display name.
     *
     * @param list<string> $extensions
     * @return array<string, mixed>
     */
    private function lists(array $extensions): array
    {
        $schema = json_decode((string) file_get_contents(self::repositoryRoot() . '/schemas/scanner/v1/contribution.schema.json'), true, 512, JSON_THROW_ON_ERROR);
        $lists = [];
        foreach (glob(self::repositoryRoot() . '/tests/Fixtures/test-fidelity/fixtures/*', GLOB_ONLYDIR) ?: [] as $root) {
            foreach ($this->scan($root, $extensions) as $contribution) {
                $raw = json_decode((string) json_encode([...$contribution->nodes, ...$contribution->edges]), true, 512, JSON_THROW_ON_ERROR);
                foreach ($raw as $fact) {
                    $definition = isset($fact['local_id']) ? 'node' : 'edge';
                    assertSame([], SchemaSubset::errors(['$ref' => '#/$defs/' . $definition], $fact, $schema), basename($root) . ' ' . $contribution->ownerKey);
                }
                foreach ($contribution->nodes as $node) {
                    if (isset($node->attributes['fields'])) {
                        $lists[$node->displayName] = array_keys(FieldList::ofNode($node)?->fields() ?? []);
                    }
                    if (isset($node->attributes['cases'])) {
                        $cases = EnumCases::ofNode($node);
                        assertNotNull($cases, $node->canonicalName);
                        $lists[$node->displayName] = $cases->cases();
                    }
                }
            }
        }
        ksort($lists);

        return $lists;
    }

    /**
     * Scan every source of `$extensions` under a fixture root through its worker.
     *
     * @param list<string> $extensions
     * @return list<ScanContribution>
     */
    private function scan(string $root, array $extensions): array
    {
        $byLanguage = array_fill_keys([...$extensions, 'toml'], []);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && isset($byLanguage[$file->getExtension()])) {
                $byLanguage[$file->getExtension()][] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        $contributions = [];
        $manifests = array_values(array_filter($byLanguage['toml'], static fn(string $file): bool => basename($file) === 'Cargo.toml'));
        unset($byLanguage['toml']);
        foreach ($byLanguage as $language => $files) {
            if ($files === []) {
                continue;
            }
            sort($files);
            $client = match ($language) {
                'ts' => $this->typescriptWorkerClient(),
                'php' => $this->phpWorkerClient(),
                'py' => $this->pythonWorkerClient(),
                default => $this->rustWorkerClient(),
            };
            $contributions = [...$contributions, ...$this->scanWith($client, $root, $files, $language === 'rs' ? $manifests : [])];
        }

        return $contributions;
    }

    /**
     * @param list<string> $files
     * @param list<string> $manifests the `Cargo.toml` files a Rust scan places its crates by
     * @return list<ScanContribution>
     */
    private function scanWith(ProcessScannerClient $client, string $root, array $files, array $manifests): array
    {
        try {
            return iterator_to_array($client->scan(['limits' => ScanLimits::DEFAULT, 'root' => $root, 'files' => $files, ...($manifests === [] ? [] : ['config_files' => $manifests])]), false);
        } finally {
            $client->shutdown();
        }
    }
}

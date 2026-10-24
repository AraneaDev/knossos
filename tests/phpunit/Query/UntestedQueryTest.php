<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * The quick list of files and components no test reaches through the graph:
 * where a change would go out with nothing checking it.
 */
final class UntestedQueryTest extends KnossosTestCase
{
    /** @var array<string, string> */
    private const FILES = [
        'src/Cart.php' => "<?php\nnamespace App;\nfinal class Cart\n{\n    public function total(): int { return (new Tax())->rate() + (new Rounder(2))->round(); }\n    public function forgotten(): void {}\n}\n",
        'src/Tax.php' => "<?php\nnamespace App;\nfinal class Tax\n{\n    public function rate(): int { return 21; }\n}\n",
        'src/Rounder.php' => "<?php\nnamespace App;\nfinal class Rounder\n{\n    public function __construct(private int \$digits) {}\n    public function round(): int { return \$this->digits; }\n}\n",
        'src/Shipping.php' => "<?php\nnamespace App;\ninterface Shipping\n{\n    public function cost(): int;\n}\n",
        'src/PostNL.php' => "<?php\nnamespace App;\nfinal class PostNL implements Shipping\n{\n    public function cost(): int { return 7; }\n}\n",
        'src/Quote.php' => "<?php\nnamespace App;\nfinal class Quote\n{\n    public function shipping(Shipping \$shipping): int { return \$shipping->cost(); }\n}\n",
        'src/Report.php' => "<?php\nnamespace App;\nfinal class Report\n{\n    public function build(): string { return (new Mailer())->send(); }\n}\n",
        'src/Mailer.php' => "<?php\nnamespace App;\nfinal class Mailer\n{\n    public function send(): string { return 'sent'; }\n}\n",
        'src/Kernel.php' => "<?php\nnamespace App;\nfinal class Kernel\n{\n    public function boot(): array { return [new S1(), new S2(), new S3(), new S4(), new S5(), new S6()]; }\n}\n",
        'src/Services.php' => "<?php\nnamespace App;\nfinal class S1 { public function run(): void {} }\nfinal class S2 {}\nfinal class S3 {}\nfinal class S4 {}\nfinal class S5 {}\nfinal class S6 {}\n",
        // Run by its tool, and declarations only: neither is source a test would reach.
        'vite.config.js' => "export default { build: { outDir: 'dist' } }\n",
        'src/types.d.ts' => "export interface Money { amount: number }\n",
        'tests/CartTest.php' => "<?php\nnamespace App\\Tests;\nuse App\\Cart;\nuse App\\Kernel;\nuse App\\PostNL;\nuse App\\Quote;\nuse PHPUnit\\Framework\\TestCase;\nfinal class CartTest extends TestCase\n{\n    public function testTotal(): void { self::assertSame(23, (new Cart())->total()); }\n    public function testShipping(): void { self::assertSame(7, (new Quote())->shipping(new PostNL())); }\n    public function testBoot(): void { self::assertCount(6, (new Kernel())->boot()); }\n}\n",
    ];

    /**
     * A file is untested when no test reaches any of its components, through
     * any number of production hops. Test files, tool configs and declaration
     * files are never listed, and a
     * test that only builds the application (a composition root constructing
     * the services) reaches none of them.
     */
    #[Group('query')]
    public function testFilesNoTestReachesAreListed(): void
    {
        [$pdo, $projectId, $root] = $this->scan();
        try {
            $result = $this->queries($pdo)->untested($projectId);

            self::assertSame(['src/Mailer.php', 'src/Report.php', 'src/Services.php'], self::sorted(array_column($result->data['files'], 'path')));
            self::assertSame(3, $result->data['total']);
            self::assertStringContainsString('3 of 10 source files', $result->summary);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * A component is untested when no test reaches it: a method of a tested
     * class the tests never call is listed, an implementation a test reaches
     * through its interface is not, and neither is a constructor whose class a
     * test constructs. One nothing references at all says so.
     */
    #[Group('query')]
    public function testComponentsNoTestReachesAreListed(): void
    {
        [$pdo, $projectId, $root] = $this->scan();
        try {
            $result = $this->queries($pdo)->untested($projectId, granularity: 'components', limit: 100);
            $names = array_column($result->data['components'], 'canonical_name');

            foreach (['App\\Cart::forgotten', 'App\\Report', 'App\\Report::build', 'App\\Mailer::send', 'App\\S1::run'] as $untested) {
                self::assertContains($untested, $names);
            }
            foreach (['App\\Cart', 'App\\Cart::total', 'App\\Tax::rate', 'App\\Rounder::__construct', 'App\\PostNL::cost', 'App\\Shipping::cost', 'App\\Kernel::boot'] as $tested) {
                self::assertNotContains($tested, $names);
            }
            $byName = array_column($result->data['components'], null, 'canonical_name');
            self::assertTrue($byName['App\\Cart::forgotten']['no_inbound_edge']);
            self::assertFalse($byName['App\\Mailer::send']['no_inbound_edge']);
            self::assertSame('src/Mailer.php', $byName['App\\Mailer::send']['path']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A path prefix narrows the list, and the total is the narrowed one. */
    #[Group('query')]
    public function testAPathPrefixNarrowsTheList(): void
    {
        [$pdo, $projectId, $root] = $this->scan();
        try {
            $result = $this->queries($pdo)->untested($projectId, pathPrefix: 'src/M');

            self::assertSame(['src/Mailer.php'], array_column($result->data['files'], 'path'));
            self::assertSame(1, $result->data['total']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A walk cut short by its bounds says so: a partial reach would list tested code as untested. */
    #[Group('query')]
    public function testATruncatedWalkSaysSo(): void
    {
        [$pdo, $projectId, $root] = $this->scan();
        try {
            $result = $this->queries($pdo)->untested($projectId, maxNodes: 2);

            self::assertContains('visit_limit', $result->data['bounds']['truncation_reasons']);
            self::assertTrue($result->truncated);
            self::assertStringContainsString('truncated', $result->summary);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** @return array{0: PDO, 1: string, 2: string} */
    private function scan(): array
    {
        $root = sys_get_temp_dir() . '/knossos-stale-untested-' . bin2hex(random_bytes(5));
        foreach (self::FILES as $path => $contents) {
            @mkdir(dirname($root . '/' . $path), 0o777, true);
            file_put_contents($root . '/' . $path, $contents);
        }
        $pdo = $this->freshTestDatabase();
        try {
            $result = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
        } catch (\Throwable $error) {
            $this->removeTempTree($root);
            throw $error;
        }

        return [$pdo, $result->projectId, $root];
    }

    private function queries(PDO $pdo): \Knossos\Query\UntestedQueryService
    {
        return ArchitectureQueryService::forDatabase($pdo)->services()->untestedQueries;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values, SORT_STRING);

        return $values;
    }
}

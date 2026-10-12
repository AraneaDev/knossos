<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\BlastRadiusService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A model's `booted` or `newFactory` is called by the framework whenever the
 * model is used, so nothing names it and no test reached it: the blast radius
 * listed it untested beside a model its tests use all the time. A framework
 * hook is reached by whatever reaches its class, so it is as tested as the
 * class is. A hook of a class no test uses stays untested.
 */
#[Group('query')]
final class FrameworkHookTestReachTest extends KnossosTestCase
{
    private const FILES = [
        'app/Models/Order.php' => <<<'PHP'
            <?php
            namespace App\Models;
            use Database\Factories\OrderFactory;
            use Illuminate\Database\Eloquent\Model;
            final class Order extends Model
            {
                protected static function booted(): void { static::creating(function (Order $order): void {}); }
                protected static function newFactory(): OrderFactory { return OrderFactory::new(); }
                public function total(): int { return 0; }
            }
            PHP,
        'app/Models/Invoice.php' => <<<'PHP'
            <?php
            namespace App\Models;
            use Illuminate\Database\Eloquent\Model;
            final class Invoice extends Model
            {
                protected static function booted(): void { static::creating(function (Invoice $invoice): void {}); }
            }
            PHP,
        'app/Services/Checkout.php' => <<<'PHP'
            <?php
            namespace App\Services;
            use App\Models\Invoice;
            use App\Models\Order;
            final class Checkout
            {
                public function pay(Order $order): int { return $order->total(); }
                public function bill(Invoice $invoice): void {}
            }
            PHP,
        'tests/Unit/OrderTest.php' => <<<'PHP'
            <?php
            namespace Tests\Unit;
            use App\Models\Order;
            use PHPUnit\Framework\TestCase;
            final class OrderTest extends TestCase
            {
                public function testTotal(): void { (new Order())->total(); }
            }
            PHP,
    ];

    public function testAFrameworkHookIsAsTestedAsItsClass(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-hook-reach-' . bin2hex(random_bytes(6));
        foreach (self::FILES as $relative => $contents) {
            if (!is_dir(dirname($root . '/' . $relative))) {
                mkdir(dirname($root . '/' . $relative), 0o777, true);
            }
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $pdo = $this->freshTestDatabase();
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
            $blast = new BlastRadiusService($pdo);
            $tested = static function (string $component) use ($blast, $root): array {
                $marks = [];
                foreach ($blast->rings($root, $component)['rings'] ?? [] as $ring) {
                    foreach ($ring['items'] as $item) {
                        $marks[$item['name']] = $item['tested'];
                    }
                }

                return $marks;
            };
            $order = $tested('App\\Models\\Order');
            self::assertTrue($order['booted'] ?? null, 'booted, of a model a test uses');
            $invoice = $tested('App\\Models\\Invoice');
            self::assertFalse($invoice['booted'] ?? null, 'booted, of a model no test uses');
        } finally {
            $this->removeTempTree($root);
        }
    }
}

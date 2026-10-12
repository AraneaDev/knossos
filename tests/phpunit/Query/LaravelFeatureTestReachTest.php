<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\BlastRadiusService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A Laravel feature test reaches the code it covers through the framework:
 * `$this->postJson('/api/orders')` lands on a route's controller, and
 * `$this->artisan('orders:poll')` runs a command. Neither names the class, so
 * every component such a test covered read as untested. The request is an
 * endpoint the core joins to its route, and the command call reaches the
 * command whose signature it names.
 */
#[Group('query')]
final class LaravelFeatureTestReachTest extends KnossosTestCase
{
    private const FILES = [
        'composer.json' => '{"name":"acme/shop","require":{"php":"^8.3","laravel/framework":"^12.0"},"autoload":{"psr-4":{"App\\\\":"app/"}}}',
        'routes/api.php' => <<<'PHP'
            <?php
            use App\Http\Controllers\OrderController;
            use Illuminate\Support\Facades\Route;
            Route::post('/orders', [OrderController::class, 'store']);
            Route::post('/orders/{number}/submit', [OrderController::class, 'submit']);
            PHP,
        'app/Http/Controllers/OrderController.php' => <<<'PHP'
            <?php
            namespace App\Http\Controllers;
            use App\Services\OrderService;
            final class OrderController
            {
                public function store(OrderService $orders): void { $orders->place(); }
                public function submit(OrderService $orders, string $number): void { $orders->submit($number); }
            }
            PHP,
        'app/Services/OrderService.php' => <<<'PHP'
            <?php
            namespace App\Services;
            final class OrderService
            {
                public function place(): void {}
                public function submit(string $number): void {}
                public function poll(): void {}
            }
            PHP,
        'app/Console/Commands/PollOrders.php' => <<<'PHP'
            <?php
            namespace App\Console\Commands;
            use App\Services\OrderService;
            use Illuminate\Console\Command;
            final class PollOrders extends Command
            {
                protected $signature = 'orders:poll {--dry-run}';
                public function handle(OrderService $orders): int { $orders->poll(); return 0; }
            }
            PHP,
        'tests/Feature/OrderTest.php' => <<<'PHP'
            <?php
            namespace Tests\Feature;
            use Tests\TestCase;
            final class OrderTest extends TestCase
            {
                public function testPlaces(): void
                {
                    $this->withHeaders(['X-Key' => 'k'])->postJson('/api/orders', ['sku' => 'a'])->assertCreated();
                }
                public function testSubmits(): void
                {
                    $number = 'Q-1';
                    $this->actingAs($this->user)->postJson("/api/orders/{$number}/submit")->assertOk();
                }
            }
            PHP,
        'tests/Feature/PollTest.php' => <<<'PHP'
            <?php
            namespace Tests\Feature;
            use Tests\TestCase;
            final class PollTest extends TestCase
            {
                public function testPolls(): void { $this->artisan('orders:poll')->assertSuccessful(); }
            }
            PHP,
    ];

    public function testARequestOrAnArtisanCallInAFeatureTestReachesWhatItRuns(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-feature-reach-' . bin2hex(random_bytes(6));
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
            $tests = static function (string $component) use ($blast, $root): array {
                $paths = [];
                foreach ($blast->rings($root, $component)['rings'] ?? [] as $ring) {
                    foreach ($ring['tests']['items'] ?? [] as $test) {
                        $paths[] = $test['path'];
                    }
                }

                return array_values(array_unique($paths));
            };
            self::assertContains('tests/Feature/OrderTest.php', $tests('App\\Services\\OrderService::place'), 'a literal request');
            self::assertContains('tests/Feature/OrderTest.php', $tests('App\\Services\\OrderService::submit'), 'an interpolated request');
            self::assertContains('tests/Feature/PollTest.php', $tests('App\\Services\\OrderService::poll'), 'an artisan call');
        } finally {
            $this->removeTempTree($root);
        }
    }
}

<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * `Product::factory()` is declared by the vendor `HasFactory` trait, so its
 * return type is not in the graph and a state called on it,
 * `Product::factory()->bundle()`, reached nothing. The factory is the one the
 * model's `newFactory()` declares it returns, or else the one Laravel's naming
 * convention finds under `Database\Factories`.
 */
#[Group('query')]
final class EloquentFactoryStatesTest extends KnossosTestCase
{
    public function testAStateCalledOnAModelsFactoryIsReachedByItsCaller(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-factories-' . bin2hex(random_bytes(6));
        $files = [
            'app/Modules/Shop/Models/Product.php' => <<<'PHP'
                <?php
                namespace App\Modules\Shop\Models;
                use Database\Factories\CatalogueItemFactory;
                use Illuminate\Database\Eloquent\Factories\HasFactory;
                use Illuminate\Database\Eloquent\Model;
                final class Product extends Model
                {
                    use HasFactory;
                    protected static function newFactory(): CatalogueItemFactory { return CatalogueItemFactory::new(); }
                }
                PHP,
            'app/Models/Account/User.php' => <<<'PHP'
                <?php
                namespace App\Models\Account;
                use Illuminate\Database\Eloquent\Factories\HasFactory;
                use Illuminate\Database\Eloquent\Model;
                final class User extends Model
                {
                    use HasFactory;
                }
                PHP,
            'database/factories/CatalogueItemFactory.php' => <<<'PHP'
                <?php
                namespace Database\Factories;
                use Illuminate\Database\Eloquent\Factories\Factory;
                final class CatalogueItemFactory extends Factory
                {
                    public function definition(): array { return []; }
                    public function bundle(): static { return $this; }
                    public function retired(): static { return $this; }
                }
                PHP,
            'database/factories/Account/UserFactory.php' => <<<'PHP'
                <?php
                namespace Database\Factories\Account;
                use Illuminate\Database\Eloquent\Factories\Factory;
                final class UserFactory extends Factory
                {
                    public function definition(): array { return []; }
                    public function admin(): static { return $this; }
                    public function unverified(): static { return $this; }
                }
                PHP,
            'tests/Feature/ShopTest.php' => <<<'PHP'
                <?php
                namespace Tests\Feature;
                use App\Models\Account\User;
                use App\Modules\Shop\Models\Product;
                use PHPUnit\Framework\TestCase;
                final class ShopTest extends TestCase
                {
                    public function testBundles(): void
                    {
                        Product::factory()->bundle()->create();
                        User::factory()->admin()->create();
                    }
                }
                PHP,
        ];
        foreach ($files as $relative => $contents) {
            if (!is_dir(dirname($root . '/' . $relative))) {
                mkdir(dirname($root . '/' . $relative), 0o777, true);
            }
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $pdo = $this->freshTestDatabase();
            $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;
            $data = ArchitectureQueryService::forDatabase($pdo)->architectureHealth($projectId, limit: 100)->data;
        } finally {
            $this->removeTempTree($root);
        }

        $reachability = [];
        foreach ($data['dead_code_candidates'] as $candidate) {
            $reachability[$candidate['component']['display_name']] = $candidate['reachability'];
        }
        self::assertSame('test_only', $reachability['bundle'] ?? null, 'bundle, through newFactory()');
        self::assertSame('test_only', $reachability['admin'] ?? null, 'admin, through the naming convention');
        self::assertSame('unreferenced', $reachability['retired'] ?? null);
        self::assertSame('unreferenced', $reachability['unverified'] ?? null);
    }
}

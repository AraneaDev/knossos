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
 * convention finds under `Database\Factories`. What the factory's `create()`
 * or `make()` returns is the model, so a relation read on it reaches the
 * relation.
 */
#[Group('query')]
final class EloquentFactoryStatesTest extends KnossosTestCase
{
    public function testAFactoryStateAndTheModelAFactoryBuildsAreReachedByTheirCallers(): void
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
                    public function parent() { return $this->belongsTo(Product::class); }
                    public function owner() { return $this->belongsTo(Product::class); }
                    public function siblings() { return $this->hasMany(Product::class); }
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
                        $child = Product::factory()->create(['name' => 'child']);
                        $child->parent;
                        Product::factory()->bundle()->make()->owner;
                        $many = Product::factory()->count(2)->create();
                        $many->siblings;
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
            $queries = ArchitectureQueryService::forDatabase($pdo);
            $usages = static fn(string $symbol): int => count($queries->listUsages($projectId, $symbol)->data['usages'] ?? []);
            self::assertSame(2, $usages('Database\\Factories\\CatalogueItemFactory::bundle'), 'bundle, through newFactory()');
            self::assertSame(1, $usages('Database\\Factories\\Account\\UserFactory::admin'), 'admin, through the naming convention');
            self::assertSame(0, $usages('Database\\Factories\\CatalogueItemFactory::retired'));
            // What create() and make() build is the model, held in a variable or read inline;
            // count() makes a collection, which is no model.
            self::assertSame(1, $usages('App\\Modules\\Shop\\Models\\Product::parent'), 'parent, on a created model');
            self::assertSame(1, $usages('App\\Modules\\Shop\\Models\\Product::owner'), 'owner, on a made model');
            self::assertSame(0, $usages('App\\Modules\\Shop\\Models\\Product::siblings'), 'siblings, on a collection');
        } finally {
            $this->removeTempTree($root);
        }
    }
}

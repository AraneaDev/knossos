<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Eloquent reaches a model's methods by names no call site spells as a call:
 * a relation is read as a property (`$line->product`) or named in a string
 * (`with(['lines', 'creator'])`), and a local scope `scopeActive` is called as
 * `active()`. Each of those uses reaches the method; a relation or scope that
 * nothing names that way stays a dead-code candidate.
 */
#[Group('query')]
final class EloquentMagicReferencesTest extends KnossosTestCase
{
    private const MODELS = [
        'app/Models/Order.php' => <<<'PHP'
            <?php
            namespace App\Models;
            use Illuminate\Database\Eloquent\Model;
            final class Order extends Model
            {
                public function lines() { return $this->hasMany(Line::class); }
                public function creator() { return $this->belongsTo(User::class); }
                public function auditor() { return $this->belongsTo(User::class); }
                public function customer() { return $this->belongsTo(User::class); }
                public function scopeOpen($query) { return $query->where('open', true); }
                public function scopeArchived($query) { return $query->where('archived', true); }
            }
            PHP,
        'app/Models/Line.php' => <<<'PHP'
            <?php
            namespace App\Models;
            use Illuminate\Database\Eloquent\Model;
            final class Line extends Model
            {
                public function order() { return $this->belongsTo(Order::class); }
                public function product() { return $this->belongsTo(Product::class); }
                public function parent() { return $this->belongsTo(Line::class); }
            }
            PHP,
        'app/Models/User.php' => "<?php\nnamespace App\\Models;\nuse Illuminate\\Database\\Eloquent\\Model;\nfinal class User extends Model {}\n",
        'app/Models/Product.php' => "<?php\nnamespace App\\Models;\nuse Illuminate\\Database\\Eloquent\\Model;\nfinal class Product extends Model {}\n",
    ];

    public function testARelationReadAsAPropertyIsReached(): void
    {
        $reported = $this->reportedNames([
            'app/Services/Pricing.php' => <<<'PHP'
                <?php
                namespace App\Services;
                use App\Models\Line;
                final class Pricing
                {
                    public function price(Line $line): mixed { return [$line->product, $line?->order]; }
                }
                PHP,
        ]);

        self::assertNotContains('product', $reported);
        self::assertNotContains('order', $reported);
        self::assertContains('parent', $reported);
    }

    public function testARelationNamedInAnEagerLoadStringIsReached(): void
    {
        $reported = $this->reportedNames([
            'app/Services/Listing.php' => <<<'PHP'
                <?php
                namespace App\Services;
                use App\Models\Order;
                final class Listing
                {
                    public function all(): mixed { return Order::with(['lines.product', 'creator:id,name'])->get(); }
                    public function one(Order $order): mixed { return $order->load('customer'); }
                }
                PHP,
        ]);

        self::assertNotContains('lines', $reported);
        self::assertNotContains('creator', $reported);
        self::assertNotContains('customer', $reported);
        self::assertContains('auditor', $reported);
    }

    public function testAnEagerLoadOnABuilderHeldInAVariableIsReached(): void
    {
        $reported = $this->reportedNames([
            'app/Services/Search.php' => <<<'PHP'
                <?php
                namespace App\Services;
                use App\Models\Order;
                final class Search
                {
                    public function find(bool $mine): mixed
                    {
                        $query = Order::where('a', 1)->where('b', 2);
                        if ($mine) {
                            $query->where('mine', true);
                        }
                        return $query->with(['creator' => fn($q) => $q])->get();
                    }
                }
                PHP,
        ]);

        self::assertNotContains('creator', $reported);
        self::assertContains('auditor', $reported);
    }

    public function testALocalScopeCalledByItsShortNameIsReached(): void
    {
        $reported = $this->reportedNames([
            'app/Services/Board.php' => <<<'PHP'
                <?php
                namespace App\Services;
                use App\Models\Order;
                final class Board
                {
                    public function open(): mixed { return Order::open()->get(); }
                }
                PHP,
        ]);

        self::assertNotContains('scopeOpen', $reported);
        self::assertContains('scopeArchived', $reported);
    }

    /**
     * @param array<string, string> $files
     * @return list<string>
     */
    private function reportedNames(array $files): array
    {
        $root = sys_get_temp_dir() . '/knossos-stale-eloquent-' . bin2hex(random_bytes(6));
        foreach (self::MODELS + $files as $relative => $contents) {
            if (!is_dir(dirname($root . '/' . $relative))) {
                mkdir(dirname($root . '/' . $relative), 0o777, true);
            }
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $pdo = $this->freshTestDatabase();
            $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;
            $data = ArchitectureQueryService::forDatabase($pdo)->services()->healthQueries->architectureHealth($projectId, limit: 100)->data;
        } finally {
            $this->removeTempTree($root);
        }

        return array_map(
            static fn(array $candidate): string => (string) $candidate['component']['display_name'],
            $data['dead_code_candidates'],
        );
    }
}

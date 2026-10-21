<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A Laravel base class calls named hooks on its subclasses: a FormRequest's
 * `rules`, a migration's `up`, a resource's `toArray`. Vendor code is not in
 * the graph, so nothing in the project names those methods and they read as
 * dead in every request, migration and resource there is. Such a hook is
 * excluded with the other inherited members; a method of the same class that
 * the base does not call stays reportable.
 */
#[Group('query')]
final class FrameworkHookMethodsTest extends KnossosTestCase
{
    public function testMethodsALaravelBaseClassCallsAreNotDeadCodeCandidates(): void
    {
        $data = $this->healthOf([
            'app/Requests/StoreRequest.php' => <<<'PHP'
                <?php
                namespace App\Requests;
                use Illuminate\Foundation\Http\FormRequest;
                final class StoreRequest extends FormRequest
                {
                    public function authorize(): bool { return true; }
                    public function rules(): array { return []; }
                    public function withValidator($validator): void {}
                    protected function prepareForValidation(): void {}
                    public function requestHelper(): void {}
                }
                PHP,
            'app/Resources/ItemResource.php' => <<<'PHP'
                <?php
                namespace App\Resources;
                use Illuminate\Http\Resources\Json\JsonResource;
                final class ItemResource extends JsonResource
                {
                    public function toArray($request): array { return []; }
                    public function resourceHelper(): void {}
                }
                PHP,
            'app/Models/Item.php' => <<<'PHP'
                <?php
                namespace App\Models;
                use Illuminate\Database\Eloquent\Model;
                final class Item extends Model
                {
                    protected function casts(): array { return []; }
                    protected static function booted(): void {}
                    protected static function newFactory() { return null; }
                    public function getRouteKeyName(): string { return 'uuid'; }
                    public function modelHelper(): void {}
                }
                PHP,
            'app/Models/Member.php' => <<<'PHP'
                <?php
                namespace App\Models;
                use Illuminate\Foundation\Auth\User as Authenticatable;
                use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
                final class Member extends Authenticatable implements JWTSubject
                {
                    protected function casts(): array { return []; }
                    public function getJWTIdentifier() { return 1; }
                    public function getJWTCustomClaims(): array { return []; }
                    public function memberHelper(): void {}
                }
                PHP,
            'app/Providers/AppProvider.php' => <<<'PHP'
                <?php
                namespace App\Providers;
                use Illuminate\Support\ServiceProvider;
                final class AppProvider extends ServiceProvider
                {
                    public function register(): void {}
                    public function boot(): void {}
                    public function providerHelper(): void {}
                }
                PHP,
            'app/Modules/Health/Commands/RunChecks.php' => <<<'PHP'
                <?php
                namespace App\Modules\Health\Commands;
                use Illuminate\Console\Command;
                final class RunChecks extends Command
                {
                    public function handle(): int { return 0; }
                    public function commandHelper(): void {}
                }
                PHP,
            'database/migrations/2026_01_01_000000_create_items.php' => <<<'PHP'
                <?php
                use Illuminate\Database\Migrations\Migration;
                return new class extends Migration
                {
                    public function up(): void {}
                    public function down(): void {}
                };
                PHP,
            'database/seeders/ItemSeeder.php' => <<<'PHP'
                <?php
                namespace Database\Seeders;
                use Illuminate\Database\Seeder;
                final class ItemSeeder extends Seeder
                {
                    public function run(): void {}
                }
                PHP,
            'database/factories/ItemFactory.php' => <<<'PHP'
                <?php
                namespace Database\Factories;
                use Illuminate\Database\Eloquent\Factories\Factory;
                final class ItemFactory extends Factory
                {
                    public function definition(): array { return []; }
                }
                PHP,
        ]);

        $reported = array_map(
            static fn(array $candidate): string => (string) $candidate['component']['display_name'],
            $data['dead_code_candidates'],
        );
        foreach (['authorize', 'rules', 'withValidator', 'prepareForValidation', 'toArray', 'casts', 'booted', 'newFactory',
            'getRouteKeyName', 'getJWTIdentifier', 'getJWTCustomClaims', 'register', 'boot', 'handle', 'up', 'down', 'run', 'definition'] as $hook) {
            self::assertNotContains($hook, $reported, $hook);
        }
        foreach (['requestHelper', 'resourceHelper', 'modelHelper', 'memberHelper', 'providerHelper', 'commandHelper'] as $helper) {
            self::assertContains($helper, $reported, $helper);
        }
    }

    /**
     * A hook name is only a hook on the base that calls it: `toArray` on a
     * class that extends nothing from the framework is an ordinary method.
     */
    public function testAHookNameOnAPlainClassStaysReportable(): void
    {
        $data = $this->healthOf([
            'app/Plain.php' => "<?php\nnamespace App;\nfinal class Plain\n{\n    public function toArray(): array { return []; }\n    public function rules(): array { return []; }\n}\n",
        ]);

        $reported = array_map(
            static fn(array $candidate): string => (string) $candidate['component']['display_name'],
            $data['dead_code_candidates'],
        );
        self::assertContains('toArray', $reported);
        self::assertContains('rules', $reported);
    }

    /**
     * Once the members a known framework base calls are excluded, the base
     * hides nothing else, so an unused method of its subclass is probably
     * dead. A base nothing knows the hooks of still makes it only possible,
     * and so does a property read by that name on a receiver nobody could
     * type, which is how an Eloquent relation is often reached.
     */
    public function testAKnownFrameworkBaseNoLongerMakesEveryMemberOnlyPossiblyDead(): void
    {
        $data = $this->healthOf([
            'app/Models/Item.php' => <<<'PHP'
                <?php
                namespace App\Models;
                use Illuminate\Database\Eloquent\Concerns\HasUuids;
                use Illuminate\Database\Eloquent\Factories\HasFactory;
                use Illuminate\Database\Eloquent\Model;
                final class Item extends Model
                {
                    use HasFactory, HasUuids;
                    public function scopeRetired($query) { return $query; }
                    public function owner() { return $this->belongsTo(Item::class); }
                    public function keeper() { return $this->belongsTo(Item::class); }
                }
                PHP,
            'app/Services/Reader.php' => "<?php\nnamespace App\\Services;\nfinal class Reader\n{\n    public function read(\$item): mixed { return [\$item->owner, \$item->parent->keeper]; }\n}\n",
            'app/Vendorish.php' => "<?php\nnamespace App;\nuse Acme\\Unknown\\Base;\nfinal class Vendorish extends Base\n{\n    public function helper(): void {}\n}\n",
        ]);

        $confidence = [];
        foreach ($data['dead_code_candidates'] as $candidate) {
            $confidence[$candidate['component']['display_name']] = $candidate['confidence'];
        }
        self::assertSame('probable', $confidence['scopeRetired'] ?? null, 'scopeRetired');
        self::assertSame('possible', $confidence['owner'] ?? null, 'owner, read on an untyped receiver');
        self::assertSame('possible', $confidence['keeper'] ?? null, 'keeper, read at the end of an untyped chain');
        self::assertSame('possible', $confidence['helper'] ?? null, 'helper, under an unknown vendor base');
    }

    /**
     * @param array<string, string> $files
     * @return array<string, mixed>
     */
    private function healthOf(array $files): array
    {
        $root = sys_get_temp_dir() . '/knossos-stale-framework-hooks-' . bin2hex(random_bytes(6));
        foreach ($files as $relative => $contents) {
            if (!is_dir(dirname($root . '/' . $relative))) {
                mkdir(dirname($root . '/' . $relative), 0o777, true);
            }
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $pdo = $this->freshTestDatabase();
            $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;

            return ArchitectureQueryService::forDatabase($pdo)->services()->healthQueries->architectureHealth($projectId, limit: 100)->data;
        } finally {
            $this->removeTempTree($root);
        }
    }
}

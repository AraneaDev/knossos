<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Classification;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Livewire roles, from real scans: the worker's per-file facts, the project
 * class hierarchy and the view join all have to agree for a method to count
 * as reached.
 */
#[Group('classification')]
final class LivewireRoleRuleTest extends KnossosTestCase
{
    private const COMPOSER = '{"require":{"laravel/framework":"^13.0","livewire/livewire":"^4.0"},"autoload":{"psr-4":{"App\\\\":"app/"}}}';

    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->removeTempTree($root);
        }
        parent::tearDown();
    }

    public function testTheFixtureComponentsAndTheirMethodsAreClassified(): void
    {
        $roles = $this->rolesOf($this->scanCopyOfFixture()[0]);

        foreach (['Dashboard', 'EditPost', 'PostTable', 'BaseComponent', 'Counter'] as $class) {
            self::assertSame(['livewire.component'], $roles['App\\Livewire\\' . $class] ?? null, $class);
        }
        foreach (['⚡search.blade.php:6', '⚡create.blade.php:7', 'admin/⚡stats.blade.php:5', '⚡archive/archive.php'] as $anonymous) {
            self::assertNotSame([], $this->matching($roles, $anonymous, 'livewire.component'), $anonymous);
        }
        self::assertArrayNotHasKey('App\\Livewire\\Forms\\PostForm', $roles);
        self::assertArrayNotHasKey('App\\Livewire\\Concerns\\HasSorting', $roles);

        $expected = [
            'hook' => ['Dashboard::mount', 'Dashboard::render', 'PostTable::updatedTitle', 'HasSorting::mountHasSorting'],
            'attribute' => ['Dashboard::reload', 'Dashboard::posts'],
            'view_action' => ['Dashboard::loadMore', 'Counter::increment', 'EditPost::save', 'BaseComponent::notify', 'HasSorting::sortBy', '::restore', '::refresh'],
            'view_expression' => ['::results'],
        ];
        foreach ($expected as $reason => $methods) {
            foreach ($methods as $method) {
                self::assertNotSame([], $this->matching($roles, $method, 'livewire.entry_method:' . $reason), "$method as $reason");
            }
        }
        self::assertArrayNotHasKey('App\\Livewire\\PostTable::unusedAction', $roles);
    }

    public function testIncludeCyclesTerminate(): void
    {
        [$pdo] = $this->scanTree([
            'app/Livewire/Loop.php' => "<?php\nnamespace App\\Livewire;\nuse Livewire\\Component;\nclass Loop extends Component\n{\n    public function one(): void {}\n    public function two(): void {}\n    public function idle(): void {}\n    public function render() { return view('livewire.loop'); }\n}\n",
            'resources/views/livewire/loop.blade.php' => "<div>@include('livewire.a')</div>\n",
            'resources/views/livewire/a.blade.php' => "<button wire:click=\"one\">1</button>\n@include('livewire.b')\n",
            'resources/views/livewire/b.blade.php' => "<button wire:click=\"two\">2</button>\n@include('livewire.a')\n",
        ]);
        $roles = $this->rolesOf($pdo);

        self::assertSame(['livewire.entry_method:view_action'], $roles['App\\Livewire\\Loop::one'] ?? null);
        self::assertSame(['livewire.entry_method:view_action'], $roles['App\\Livewire\\Loop::two'] ?? null);
        self::assertArrayNotHasKey('App\\Livewire\\Loop::idle', $roles);
    }

    public function testAViewOnlyReachesItsOwnComponents(): void
    {
        $component = static fn(string $name, string $view): string => "<?php\nnamespace App\\Livewire;\nuse Livewire\\Component;\nclass $name extends Component\n{\n    public function save(): void {}\n    public function render() { return view('$view'); }\n}\n";
        [$pdo] = $this->scanTree([
            'app/Livewire/Left.php' => $component('Left', 'livewire.left'),
            'app/Livewire/Right.php' => $component('Right', 'livewire.right'),
            'resources/views/livewire/left.blade.php' => "<button wire:click=\"save\">x</button>\n",
            'resources/views/livewire/right.blade.php' => "<p>nothing</p>\n",
        ]);
        $roles = $this->rolesOf($pdo);

        self::assertSame(['livewire.entry_method:view_action'], $roles['App\\Livewire\\Left::save'] ?? null);
        self::assertArrayNotHasKey('App\\Livewire\\Right::save', $roles);
    }

    public function testAMissingViewIsAWarning(): void
    {
        [$pdo, $result] = $this->scanTree([
            'app/Livewire/Lost.php' => "<?php\nnamespace App\\Livewire;\nuse Livewire\\Component;\nclass Lost extends Component\n{\n    public function render() { return view('livewire.nowhere'); }\n}\n",
        ]);

        $warnings = array_values(array_filter($result->warnings, static fn(string $warning): bool => str_starts_with($warning, 'LIVEWIRE_VIEW_UNRESOLVED')));

        self::assertSame(
            ['LIVEWIRE_VIEW_UNRESOLVED: App\\Livewire\\Lost renders livewire.nowhere, expected at resources/views/livewire/nowhere.blade.php, which is not in the scan; its view actions are not counted.'],
            $warnings,
        );
        self::assertSame(['livewire.component'], $this->rolesOf($pdo)['App\\Livewire\\Lost'] ?? null);
    }

    public function testANonLiteralRenderGetsNoDefaultViewAndNoWarning(): void
    {
        $component = static fn(string $name, string $render): string => "<?php\nnamespace App\\Livewire;\nuse Livewire\\Component;\nclass $name extends Component\n{\n    public function onlyInDefault(): void {}\n    public function go(): void {}\n    public function render() { $render }\n}\n";
        [$pdo, $result] = $this->scanTree([
            'app/Livewire/Dyn.php' => $component('Dyn', "return view('livewire.dyn-' . \$this->mode);"),
            'app/Livewire/Inline.php' => $component('Inline', "return <<<'blade'\n<div wire:click=\"go\"></div>\nblade;"),
            'resources/views/livewire/dyn.blade.php' => "<button wire:click=\"onlyInDefault\">x</button>\n",
        ]);
        $roles = $this->rolesOf($pdo);

        self::assertArrayNotHasKey('App\\Livewire\\Dyn::onlyInDefault', $roles);
        self::assertArrayNotHasKey('App\\Livewire\\Inline::go', $roles);
        self::assertSame([], array_values(array_filter($result->warnings, static fn(string $warning): bool => str_contains($warning, 'LIVEWIRE_VIEW_UNRESOLVED'))));
    }

    public function testAComponentWithoutRenderJoinsTheViewItsProjectBaseRenders(): void
    {
        [$pdo] = $this->scanTree([
            'app/Livewire/Base.php' => "<?php\nnamespace App\\Livewire;\nuse Livewire\\Component;\nabstract class Base extends Component\n{\n    public function render() { return view('livewire.shared'); }\n}\n",
            'app/Livewire/Child.php' => "<?php\nnamespace App\\Livewire;\nclass Child extends Base\n{\n    public function shout(): void {}\n    public function idle(): void {}\n}\n",
            'app/Livewire/DynBase.php' => "<?php\nnamespace App\\Livewire;\nuse Livewire\\Component;\nabstract class DynBase extends Component\n{\n    public function render() { return view(\$this->v); }\n}\n",
            'app/Livewire/DynChild.php' => "<?php\nnamespace App\\Livewire;\nclass DynChild extends DynBase\n{\n    public function onlyInDefault(): void {}\n}\n",
            'resources/views/livewire/shared.blade.php' => "<button wire:click=\"shout\">x</button>\n",
            'resources/views/livewire/dyn-child.blade.php' => "<button wire:click=\"onlyInDefault\">x</button>\n",
        ]);
        $roles = $this->rolesOf($pdo);

        self::assertSame(['livewire.entry_method:view_action'], $roles['App\\Livewire\\Child::shout'] ?? null);
        self::assertArrayNotHasKey('App\\Livewire\\Child::idle', $roles);
        self::assertArrayNotHasKey('App\\Livewire\\DynChild::onlyInDefault', $roles);
    }

    public function testTheFixtureResolvesEveryView(): void
    {
        [, $result] = $this->scanCopyOfFixture();

        self::assertSame([], array_values(array_filter($result->warnings, static fn(string $warning): bool => str_contains($warning, 'LIVEWIRE_VIEW_UNRESOLVED'))));
    }

    public function testWithoutLivewireNothingIsClassified(): void
    {
        [$pdo, $result] = $this->scanCopyOfFixture(static fn(string $root) => file_put_contents($root . '/composer.json', '{"require":{"laravel/framework":"^13.0"},"autoload":{"psr-4":{"App\\\\":"app/"}}}'));

        $livewire = $pdo->query("SELECT COUNT(*) FROM classifications WHERE role LIKE 'livewire.%'")->fetchColumn();
        self::assertSame(0, (int) $livewire);
        self::assertSame([], array_filter($result->warnings, static fn(string $warning): bool => str_contains($warning, 'LIVEWIRE')));
    }

    /**
     * @param ?callable(string): mixed $modify
     * @return array{0: PDO, 1: \Knossos\Result\ResultEnvelope}
     */
    private function scanCopyOfFixture(?callable $modify = null): array
    {
        $root = sys_get_temp_dir() . '/knossos-stale-lw-' . bin2hex(random_bytes(6));
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/laravel13-livewire', $root);
        $this->roots[] = $root;
        if ($modify !== null) {
            $modify($root);
        }

        return $this->scanRoot($root);
    }

    /**
     * @param array<string, string> $files
     * @return array{0: PDO, 1: \Knossos\Result\ResultEnvelope}
     */
    private function scanTree(array $files): array
    {
        $root = sys_get_temp_dir() . '/knossos-stale-lw-' . bin2hex(random_bytes(6));
        $files['composer.json'] = self::COMPOSER;
        foreach ($files as $path => $content) {
            if (!is_dir(dirname($root . '/' . $path))) {
                mkdir(dirname($root . '/' . $path), 0o777, true);
            }
            file_put_contents($root . '/' . $path, $content);
        }
        $this->roots[] = $root;

        return $this->scanRoot($root);
    }

    /** @return array{0: PDO, 1: \Knossos\Result\ResultEnvelope} */
    private function scanRoot(string $root): array
    {
        $pdo = $this->freshTestDatabase();

        return [$pdo, (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)];
    }

    /**
     * Livewire roles by canonical name; an entry method carries its reason after a colon.
     *
     * @return array<string, list<string>>
     */
    private function rolesOf(PDO $pdo): array
    {
        $roles = [];
        $rows = $pdo->query("SELECT n.canonical_name, c.role, c.attributes_json FROM classifications c JOIN nodes n ON n.id = c.node_id WHERE c.role LIKE 'livewire.%' ORDER BY n.canonical_name, c.role");
        foreach ($rows ? $rows->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $reason = json_decode((string) $row['attributes_json'], true)['reason'] ?? '';
            $roles[$row['canonical_name']][] = $row['role'] === 'livewire.entry_method' ? $row['role'] . ':' . $reason : $row['role'];
        }

        return $roles;
    }

    /**
     * The roles-by-name entries whose name ends with the fragment, narrowed to those holding a role.
     *
     * @param array<string, list<string>> $roles
     * @return list<string>
     */
    private function matching(array $roles, string $fragment, string $role): array
    {
        $found = [];
        foreach ($roles as $name => $held) {
            if (str_contains($name, $fragment) && in_array($role, $held, true)) {
                $found[] = $name;
            }
        }

        return $found;
    }
}

<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * Common healthy Python test idioms, through a real scan of
 * `python/healthy-idioms`. The fixture stubs each kind of member a class can
 * have (a method, a class attribute, an annotation, a nested class, a
 * mangled `__x`, a `self.` or `cls.` write, a literal `setattr`, a slot, a
 * `@property`, `@retry` and `@contextmanager` member) and a member of each
 * class whose names may come from elsewhere (`__getattr__`,
 * `__getattribute__`, `__init_subclass__`, a metaclass, a decorator, attrs,
 * a builtin base, a dynamic `setattr`, a `__dict__` write, a class body loop,
 * a module-level write, a class handed to a helper, a base imported two ways)
 * or of a module with `__getattr__`. A `Generic[T]` base keeps the list
 * closed (Ruling P19).
 *
 * Until the worker reports test facts (Task 3), the scan asserts the
 * production name lists those stubs are judged against: each opener's list
 * is open and each closed list holds every name stubbed. Ruling P4: an empty
 * findings list before any double exists would assert nothing.
 */
#[Group('test-fidelity')]
#[Group('python-scanner')]
final class PythonHealthyIdiomsTest extends KnossosTestCase
{
    public function testFixtureClassesCarryCompleteOrOpenNameLists(): void
    {
        $nodes = $this->scannedNodes();

        assertSame(
            ['Nested', '_Repo__hidden', '__hidden', '_protected', 'annotated', 'attribute', 'build', 'class_written', 'fetch', 'instance_name', 'method', 'opened', 'set_by_name', 'size'],
            $nodes['class members.Repo']['member_names'],
        );
        assertSame(['slot'], $nodes['class members.Slotted']['member_names']);
        assertSame(['item'], $nodes['class openers.Box']['member_names']);
        foreach (['class members.Repo', 'class members.Slotted', 'class openers.Box', 'module members'] as $closed) {
            assertSame([$closed, null, null], [$closed, $nodes[$closed]['member_names_open'] ?? null, $nodes[$closed]['exports_open'] ?? null]);
        }
        assertSame(['Repo', 'Slotted', 'contextmanager', 'retry'], $nodes['module members']['exports']);

        $open = [];
        foreach ($nodes as $name => $attributes) {
            if (($attributes['member_names_open'] ?? $attributes['exports_open'] ?? false) === true) {
                $open[] = $name;
            }
        }
        sort($open);
        assertSame([
            'class openers.Attrs', 'class openers.Bag', 'class openers.Decorated', 'class openers.Failure',
            'class openers.Generated', 'class openers.Guarded', 'class openers.Installed', 'class openers.Lazy',
            'class openers.Meta', 'class openers.Patched', 'class openers.Registered', 'class openers.Settings',
            'class openers.TwoWays', 'class openers.WithMeta', 'module dynamic_module',
        ], $open);
    }

    public function testFixtureMethodsCarryVisibilityAndDecoration(): void
    {
        $nodes = $this->scannedNodes();

        assertSame('protected', $nodes['method members.Repo::_protected']['visibility']);
        assertSame('private', $nodes['method members.Repo::__hidden']['visibility']);
        assertSame(null, $nodes['method members.Repo::__init__']['visibility'] ?? null);
        assertSame(null, $nodes['method members.Repo::method']['visibility'] ?? null);
        foreach (['size', 'fetch', 'opened'] as $decorated) {
            assertSame([$decorated, true], [$decorated, $nodes['method members.Repo::' . $decorated]['decorated'] ?? null]);
        }
        foreach (['method', 'build', '__init__'] as $plain) {
            assertSame([$plain, null], [$plain, $nodes['method members.Repo::' . $plain]['decorated'] ?? null]);
        }
    }

    /**
     * The attributes of each Python class, module and method node of one scan, keyed `<kind> <canonical name>`.
     *
     * @return array<string, array<string, mixed>>
     */
    private function scannedNodes(): array
    {
        $root = self::scratchPath('python-healthy-idioms');
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/test-fidelity/python/healthy-idioms', $root);
        $pdo = $this->freshTestDatabase();
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
        $nodes = [];
        $rows = $pdo->query("SELECT kind, canonical_name, attributes_json FROM nodes WHERE language = 'py' AND kind IN ('class', 'module', 'method')")->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as [$kind, $name, $json]) {
            $attributes = json_decode((string) $json, true);
            $nodes[$kind . ' ' . $name] = is_array($attributes) ? $attributes : [];
        }

        return $nodes;
    }
}

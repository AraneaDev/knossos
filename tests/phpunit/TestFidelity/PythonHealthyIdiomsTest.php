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
 * The scan asserts the production name lists those stubs are judged
 * against (each opener's list is open, each closed list holds every name
 * stubbed), and, now the worker reports the doubles (Task 3), that they give
 * no definite finding beside one planted twin. The fixture also holds the
 * double idioms that must stay silent: `mock_open` on `builtins.open`,
 * `create=True`, `raising=False`, an unspec'd `Mock`, a class-level
 * `autospec=True` asserted with its instance, `mocker.spy` on a class,
 * `patch.dict`, and a class and a function a module imports under an alias,
 * patched where that module uses them (review C1).
 *
 * Now the worker reports the test cases (Task 4), `tests/test_cases.py`
 * holds the assertions that only look as if they cannot fail, each operand
 * written where a literal or a pin does not show it (Review Focus 1): a
 * `nonlocal` and a `global` counter a `side_effect` bumps, a collector and a
 * container counter, a walrus in a comprehension, a frame write, `exec`, a
 * list handed to a call, a yield fixture, `monkeypatch`, a NaN and an
 * `__eq__` under test compared with themselves, `parametrize` arguments, a
 * conditional and a `finally` binding, `side_effect` and `reset_mock` over a
 * pin, an `AsyncMock` member not awaited, and a pin read back through a
 * fixture and through `setUp`. Three more each guard one rule of a local's
 * literal (G4-09): a nested reader run before its local is bound again (one
 * binding), a binding in a branch read after it (the binding that reaches
 * the use), and a pin read back in a nested helper (the same function).
 * Ruling P5: a false definite the rules give
 * before Task 5's gates is listed here as "cleared by Task 5"; none is.
 *
 * Now the rules judge Python (Task 5), `tests/test_planted.py` sets each
 * planted finding beside its healthy twin: a ghost on a closed class and on
 * an `abc.ABC` subclass, a
 * `__x` stubbed as written beside `_C__x`, a matcher with an unknown keyword
 * beside one that binds, a local `Mock`'s echo beside a pin handed to
 * production, a single-bound constant beside one compared with production,
 * `_x` (a warning) beside a public name, and a `str` pin on `-> int` (a
 * warning) beside an `int`. `tests/test_bases.py` holds what must stay
 * silent: `Enum`'s and `NamedTuple`'s own names, an `abc.ABC` subclass's
 * members and `register`, a function and a method their scope rebinds
 * (`functools.partial`, a fallback import), `*args` and `**kwargs`, and a
 * class double that names no test. `tests/test_rewriting.py` adds the
 * rebinds review I1 and I2 found: a class decorator that rewrites a method, a
 * `globals()` write, and an import its module binds again.
 * `tests/test_meta.py` holds the rewrites Ruling P21 covers: a method
 * rewritten by an inherited project metaclass (in its module and from
 * another) or by a base's `__init_subclass__`, beside `test_planted.py`'s
 * twin, a plain base whose subclass's mismatch stays definite.
 * `tests/test_allowed.py` holds the decorators and metaclasses that rewrite
 * nothing: `dataclass`, `total_ordering`, `abc.ABC`, `enum.unique`.
 * `tests/test_foreign.py` patches a module global only another file writes
 * (`flags.VERBOSE = True` in `boot.py`), which that write opens (G4-03), and
 * `tests/test_unbound.py` a matcher that lists the receiver of a call
 * production makes through the class, `Repo.method(repo, key)` (G4-10).
 *
 * `src/probes/` and the `tests/test_probe_*.py` files hold the final review's
 * probes, each passing under real pytest: a member Python's lookup answers
 * with another binding than a breadth-first walk finds (a diamond, a
 * divergent MRO, a base outside the project first, a class-body or `self.`
 * shadow; Ruling P24), a def rebound on `self` through a tuple target or a
 * literal `setattr` (I2), a class written through a loop, registry, alias,
 * display or comprehension (Ruling P23), every binding form a module and a
 * class body has, and pins mutated or reconfigured after they are set.
 * `tests/test_probe_planted.py` holds their twins: an arity mismatch under one
 * base, one beside other `self` writes, and a ghost on a class only annotations
 * and an instance name.
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
            // `total_ordering` and `enum.unique` may add names; they rewrite no method (Ruling P21).
            'class allowed.Color', 'class allowed.Ver',
            'class openers.Attrs', 'class openers.Bag', 'class openers.Decorated', 'class openers.Failure',
            'class openers.Generated', 'class openers.Guarded', 'class openers.Installed', 'class openers.Lazy',
            'class openers.Meta', 'class openers.Patched', 'class openers.Registered', 'class openers.Settings',
            'class openers.TwoWays', 'class openers.WithMeta',
            // Each written through a same-file alias, a loop, a `setattr` or a helper (Ruling P23), or late (`Late.handle = …`).
            'class probes.aliases.Aliased', 'class probes.aliases.Comprehended', 'class probes.aliases.Installed',
            'class probes.aliases.LoopA', 'class probes.aliases.LoopB', 'class probes.aliases.Paired',
            'class probes.aliases.Registered', 'class probes.aliases.Viewish', 'class probes.lookup.Late',
            // A class a decorator rewrites, and a module a `globals()` write reaches (review I2).
            'class rewriting.Store',
            // A metaclass keyword, a `type` base and an `__init_subclass__` each open the list.
            'class samemod.Base', 'class samemod.Wrapping', 'class subhook.Hooked', 'class wrapmeta.Base', 'class wrapmeta.Wrapping',
            // The frame-write idioms' own module, which `exec` and `globals()` there may write; a package, always open.
            'module dynamic_module', 'module probes', 'module rewriting', 'module tests.test_frames',
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

    public function testHealthyIdiomsGiveNoFalseDefinite(): void
    {
        $pdo = $this->scan();
        $rows = $pdo->query(
            'SELECT f.category, f.sub_rule, f.confidence, fl.relative_path, f.start_line FROM test_findings f '
            . 'JOIN projects p ON p.id = f.project_id AND f.last_scan_id = p.active_scan_id JOIN files fl ON fl.id = f.file_id',
        )->fetchAll(PDO::FETCH_NUM);
        $findings = array_map(static fn(array $row): string => vsprintf('%s/%s/%s@%s:%d', $row), $rows);
        sort($findings);
        $doubles = (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE language = 'py' AND kind = 'test_double'")->fetchColumn();

        $cases = (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE language = 'py' AND kind = 'test_case'")->fetchColumn();

        // Every stub, opener and double idiom of the fixture is a double the rules judge, and every test a case, so the list asserts something.
        assertSame(true, $doubles > 25);
        assertSame(true, $cases > 30);
        assertSame([
            // Genuine, each the planted twin of the healthy idiom beside it (Task 5).
            'ARITY_MISMATCH/call_arity/definite@tests/test_planted.py:100',
            'ARITY_MISMATCH/call_arity/definite@tests/test_planted.py:37',
            // The final review's probes' twins: one base, other self writes, a class no alias holds.
            'ARITY_MISMATCH/call_arity/definite@tests/test_probe_planted.py:12',
            'ARITY_MISMATCH/call_arity/definite@tests/test_probe_planted.py:20',
            'GHOST_MEMBER/missing_member/definite@tests/test_planted.py:7',
            'GHOST_MEMBER/missing_member/definite@tests/test_planted.py:92',
            'GHOST_MEMBER/missing_member/definite@tests/test_probe_planted.py:27',
            'RETURN_DRIFT/return_type/warning@tests/test_planted.py:84',
            'TAUTOLOGY/constant/definite@tests/test_planted.py:65',
            'TAUTOLOGY/mock_echo/definite@tests/test_planted.py:53',
            'VISIBILITY_BREACH/visibility/definite@tests/test_planted.py:25',
            'VISIBILITY_BREACH/visibility/warning@tests/test_planted.py:74',
        ], $findings);
    }

    /** Scan a fresh copy of the fixture. */
    private function scan(): PDO
    {
        $root = self::scratchPath('python-healthy-idioms');
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/test-fidelity/python/healthy-idioms', $root);
        $pdo = $this->freshTestDatabase();
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

        return $pdo;
    }

    /**
     * The attributes of each Python class, module and method node of one scan, keyed `<kind> <canonical name>`.
     *
     * @return array<string, array<string, mixed>>
     */
    private function scannedNodes(): array
    {
        $pdo = $this->scan();
        $nodes = [];
        $rows = $pdo->query("SELECT kind, canonical_name, attributes_json FROM nodes WHERE language = 'py' AND kind IN ('class', 'module', 'method')")->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as [$kind, $name, $json]) {
            $attributes = json_decode((string) $json, true);
            $nodes[$kind . ' ' . $name] = is_array($attributes) ? $attributes : [];
        }

        return $nodes;
    }
}

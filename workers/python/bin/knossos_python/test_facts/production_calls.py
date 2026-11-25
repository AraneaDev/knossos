"""What a test runs of the code under test: its calls into production, and the project files they land in.

Ported from Momus's ``extractTestFunctions``, after the PHP worker's
``ProductionCalls``. A call counts when its callee is imported from anything
but a test framework (``unittest``, ``mock``, ``pytest``) or a test module,
or is a method of a value a counted call made, or a method the test class
inherits from a base the file does not show. A function the file declares
counts what it calls, wherever it is called from. A builtin, a class or
function of the test file, and anything that makes, configures or calls a
double runs no code under test. A call on the result of another call
(``Repo().save()``) counts once, for the call it starts from.

A test also runs its class's setup methods and the fixtures it requests: by
parameter, by ``usefixtures`` and by ``autouse``, from its own file or a
``conftest.py``. A fixture the worker cannot read, other than pytest's and
pytest-mock's own, counts as one call into production, landing nowhere it
can name. A subject is the project file a counted callee is declared in.
"""

from __future__ import annotations

import ast
import re
from collections.abc import Iterator, Mapping
from dataclasses import dataclass, field

from ..fixtures import fixture_name
from ..module_index import ProjectModuleIndex
from .bindings import Bindings, parametrized
from .conftests import conftest_autouse, conftest_fixture
from .file_index import FUNCTIONS, Scope, TestFileIndex
from .frozen_names import BUILTINS, MOCKER_FIXTURES, PYTEST_BUILTIN_FIXTURES, SETUP_METHODS
from .imports import NameResolver
from .paths import is_test_path

Function = ast.FunctionDef | ast.AsyncFunctionDef

FRAMEWORK_HEADS = frozenset({"unittest", "mock", "pytest", "_pytest", "pytest_mock"})
"""Top-level packages whose calls define, configure or assert tests rather than run code under test."""

TEST_CASE_HELPERS = re.compile(
    r"^(?:assert|fail|skip|subTest|addCleanup|addClassCleanup|doCleanups|enterContext|addTypeEqualityFunc"
    r"|setUp|tearDown|asyncSetUp|asyncTearDown|setup_method|teardown_method|(?:id|run|debug|shortDescription)$)"
)
"""Methods of ``unittest.TestCase`` that assert, skip, set up or clean up, rather than run code under test."""


@dataclass
class Reach:
    """How many production calls something makes, and the project files they land in."""

    calls: int = 0
    subjects: set[str] = field(default_factory=set)

    def add(self, other: Reach) -> None:
        self.calls += other.calls
        self.subjects |= other.subjects


@dataclass(frozen=True)
class Landed:
    """A call that runs production, and the project file it lands in when the project's."""

    subject: str | None


class ProductionCalls:
    """Count the production calls of the tests, fixtures and helpers of one test file."""

    def __init__(
        self, files: TestFileIndex, names: NameResolver, bindings: Bindings, records: Mapping[int, object]
    ) -> None:
        self.files = files
        self.names = names
        self.bindings = bindings
        self.records = records
        self.index: ProjectModuleIndex = names.index
        self._calls: dict[int, list[ast.Call]] = {}
        for call in files.calls:
            for function in self._functions_around(call):
                self._calls.setdefault(id(function), []).append(call)
        self._files: dict[str, str | None] = {}
        self._fixtures: dict[int, Reach | None] = {}
        self._landings: dict[int, Function | Landed | None] = {}

    def _functions_around(self, node: ast.AST) -> Iterator[Function]:
        current: Scope | None = self.files.scope_of(node)
        while current is not None:
            if isinstance(current.node, FUNCTIONS):
                yield current.node
            current = current.parent

    # -- what one call runs ---------------------------------------------------------------------------------------

    def is_production(self, call: ast.Call) -> bool:
        return isinstance(self.landing(call), Landed)

    def landing(self, call: ast.Call) -> Function | Landed | None:
        """A helper of the file, whose calls count in its place; production; or None when no code under test runs."""
        key = id(call)
        if key not in self._landings:
            self._landings[key] = self._land(call, 0)
        return self._landings[key]

    def _land(self, call: ast.Call, depth: int) -> Function | Landed | None:
        if id(call) in self.records or depth > 8:
            return None
        func = call.func
        root: ast.AST = func
        while isinstance(root, ast.Attribute):
            root = root.value
        if not isinstance(root, ast.Name) or self._doubled(func):
            return None
        if self.bindings.receiver_of(root):
            return self._on_receiver(func, root)
        binding = self.files.binding_of(root)
        if binding is None:
            unbound = self.files.owner_of(root.id, self.files.scope_of(root)) is None
            # A builtin runs nothing of the project; a name bound in several places may be anything.
            return None if unbound and root.id in BUILTINS else Landed(None)
        if binding.kind == "import":
            return self._imported(func, root)
        if binding.kind == "def" and func is root and isinstance(binding.node, FUNCTIONS):
            return binding.node
        if binding.kind in {"assign", "with"} and isinstance(binding.value, ast.Call) and func is not root:
            made = self._land(binding.value, depth + 1)
            return made if isinstance(made, Landed) else None
        return None

    def _doubled(self, func: ast.AST) -> bool:
        """Whether a callee is a double, or reached through one: its mock answers, not production."""
        node: ast.AST = func
        while True:
            if self.bindings.value_of(node) is not None:
                return True
            if not isinstance(node, ast.Attribute):
                return False
            node = node.value

    def _on_receiver(self, func: ast.AST, receiver: ast.Name) -> Function | Landed | None:
        """``self.<name>(...)``: a method of the class or of a base the file declares, ``TestCase``'s, or inherited."""
        klass = self.bindings.receiver_class(receiver)
        if not isinstance(func, ast.Attribute) or func.value is not receiver or klass is None:
            return None
        declared = self._method(klass, func.attr, set())
        if declared is not None:
            return declared
        return None if TEST_CASE_HELPERS.match(func.attr) is not None else Landed(None)

    def _method(self, klass: ast.ClassDef, name: str, seen: set[int]) -> Function | None:
        seen.add(id(klass))
        for statement in klass.body:
            if isinstance(statement, FUNCTIONS) and statement.name == name:
                return statement
        for base in klass.bases:
            binding = self.files.binding_of(base) if isinstance(base, ast.Name) else None
            if binding is not None and isinstance(binding.node, ast.ClassDef) and id(binding.node) not in seen:
                found = self._method(binding.node, name, seen)
                if found is not None:
                    return found
        return None

    def _imported(self, func: ast.AST, root: ast.Name) -> Landed | None:
        resolved = self.names.resolve(root)
        qualified = "" if resolved is None else resolved.qualified
        head = qualified.split(".", 1)[0]
        if (
            not qualified
            or head in FRAMEWORK_HEADS
            or head.startswith("pytest_")
            or qualified.startswith("django.test")
        ):
            return None
        node: ast.AST = func
        while True:
            named = self.names.resolve(node)
            if named is not None and named.symbol is not None:
                subject = self.file_of(named.symbol)
                if subject is not None and is_test_path(subject):
                    return None
                return Landed(subject)
            if not isinstance(node, ast.Attribute):
                return Landed(None)
            node = node.value

    def file_of(self, symbol: str) -> str | None:
        """The project file a ``py:`` symbol is declared in: a module's own, a top-level declaration's module's."""
        kind, _, name = symbol.removeprefix("py:").partition(":")
        module = name if kind == "module" else name.rpartition(".")[0]
        if module not in self._files:
            path = self.index.layout.module_file(module, hash_found=False)
            self._files[module] = None if path is None else path.relative_to(self.index.root).as_posix()
        return self._files[module]

    # -- what a test, a fixture or a helper runs ------------------------------------------------------------------

    def of_function(self, function: Function) -> Reach:
        """The production calls ``function`` makes, through every helper of the file it calls."""
        reach = Reach()
        seen = {id(function)}
        pending = [function]
        while pending:
            current = pending.pop()
            for call in self._calls.get(id(current), []):
                landed = self.landing(call)
                if isinstance(landed, Landed):
                    reach.calls += 1
                    if landed.subject is not None:
                        reach.subjects.add(landed.subject)
                elif landed is not None and id(landed) not in seen:
                    seen.add(id(landed))
                    pending.append(landed)
        return reach

    def of_case(self, function: Function, klass: ast.ClassDef | None) -> Reach:
        """What a test runs: itself, its class's setups, and every fixture it requests or that applies itself."""
        reach = self.of_function(function)
        if klass is not None:
            for statement in klass.body:
                if isinstance(statement, FUNCTIONS) and statement.name in SETUP_METHODS:
                    reach.add(self.of_function(statement))
        requested = [*self.requested(function, klass), *_used_fixtures(function), *_used_fixtures(klass)]
        requested += [name for name in self.autouse(klass) if name not in requested]
        for name in requested:
            reach.add(self.fixture(name, klass))
        return reach

    def requested(self, function: Function, klass: ast.ClassDef | None) -> list[str]:
        """The fixtures ``function`` takes by parameter: neither its receiver, a patch's mock, nor parametrized."""
        positional = [*function.args.posonlyargs, *function.args.args]
        if klass is not None and positional and not _is_static(function):
            positional = positional[1:]
        return [
            argument.arg
            for argument in [*positional, *function.args.kwonlyargs]
            if id(argument) not in self.bindings.injected and not parametrized(function, argument.arg, klass)
        ]

    def autouse(self, klass: ast.ClassDef | None) -> list[str]:
        """The fixtures that apply themselves to a test of ``klass`` (None: of the module): the file's, conftests'."""
        names = [name for name, function in self._local_fixtures(klass).items() if is_autouse(function)]
        found = conftest_autouse(self.index, self.names.relative, self.bindings.conftests)
        return [*names, *(name for name in found if name not in names)]

    def fixture(self, name: str, klass: ast.ClassDef | None) -> Reach:
        """What the fixture ``name`` runs for a test of ``klass``: its nearest definition's calls, and its fixtures'."""
        local = self._local_fixtures(klass).get(name)
        if local is not None:
            return self.local_fixture(local)
        if name in MOCKER_FIXTURES or name in PYTEST_BUILTIN_FIXTURES:
            return Reach()
        found = conftest_fixture(self.index, self.names.relative, name, self.bindings.conftests)
        reach = Reach(1) if found is None else Reach(found.calls, set(found.subjects))
        loaded = self.index.path_loaded_fixtures(self.names.relative).get(name)
        if loaded is not None:
            path = self.index.layout.module_file(loaded, hash_found=False)
            if path is not None:
                reach.subjects.add(path.relative_to(self.index.root).as_posix())
        return reach

    def local_fixture(self, function: Function) -> Reach:
        key = id(function)
        if key in self._fixtures:
            known = self._fixtures[key]
            return Reach() if known is None else Reach(known.calls, set(known.subjects))
        self._fixtures[key] = None  # a fixture that requests itself, through others, counts once
        scope = self.files.scope_of(function).node
        klass = scope if isinstance(scope, ast.ClassDef) else None
        reach = self.of_function(function)
        for name in self.requested(function, klass):
            reach.add(self.fixture(name, klass))
        self._fixtures[key] = reach
        return Reach(reach.calls, set(reach.subjects))

    def _local_fixtures(self, klass: ast.ClassDef | None) -> dict[str, Function]:
        """The file's fixtures a test of ``klass`` sees, by name: the module's, the class's, the last winning."""
        found: dict[str, Function] = {}
        owners = [id(self.files.tree), *([id(klass)] if klass is not None else [])]
        for owner in owners:
            for function in self.files.functions:
                name = fixture_name(function)
                if name is not None and id(self.files.scope_of(function).node) == owner:
                    found[name] = function
        return found


def _is_static(function: Function) -> bool:
    return any(
        isinstance(decorator, ast.Name) and decorator.id == "staticmethod" for decorator in function.decorator_list
    )


def is_autouse(function: Function) -> bool:
    for decorator in function.decorator_list:
        if isinstance(decorator, ast.Call):
            for keyword in decorator.keywords:
                if keyword.arg == "autouse" and not (
                    isinstance(keyword.value, ast.Constant) and keyword.value.value in (False, None)
                ):
                    return True
    return False


def _used_fixtures(node: Function | ast.ClassDef | None) -> list[str]:
    """The names ``@pytest.mark.usefixtures(...)`` on ``node`` requests."""
    names: list[str] = []
    for decorator in [] if node is None else node.decorator_list:
        if isinstance(decorator, ast.Call) and isinstance(decorator.func, ast.Attribute):
            if decorator.func.attr == "usefixtures":
                names += [
                    argument.value
                    for argument in decorator.args
                    if isinstance(argument, ast.Constant) and isinstance(argument.value, str)
                ]
    return names

"""What a ``conftest.py`` hands its tests through fixtures, by fixture name: doubles, and calls into production.

pytest gives a test the fixtures of every ``conftest.py`` from the root down
to its own directory, the nearest winning. A fixture there that returns or
yields a double binds a test's parameter of its name to that double, whose
node is the conftest's own: the test file can name it but not add to it.
What a fixture runs of the code under test counts for every test that
requests it, and an ``autouse`` fixture for every test below it. Each
conftest is read once per request, and what reading it cost is named by
every file that asks, as the index does for a module's declarations.
"""

from __future__ import annotations

import ast
import hashlib
from dataclasses import dataclass
from pathlib import PurePosixPath
from weakref import WeakKeyDictionary

from ..fixtures import fixture_name
from ..module_index import ProjectModuleIndex
from ..safe_io import read_bounded, walk_path
from .paths import is_test_path


@dataclass(frozen=True)
class ConftestFixture:
    """A conftest fixture: the canonical name of the double it hands over (or ``""``), and what it runs."""

    double: str
    calls: int
    subjects: tuple[str, ...]
    autouse: bool


_READ: WeakKeyDictionary[ProjectModuleIndex, dict[str, tuple[dict[str, ConftestFixture], set[str]]]] = (
    WeakKeyDictionary()
)


def conftest_double(index: ProjectModuleIndex, relative: str, name: str) -> str | None:
    """The canonical name of the double the nearest conftest over ``relative`` delivers as fixture ``name``."""
    found = conftest_fixture(index, relative, name)
    return None if found is None else found.double or None


def conftest_fixture(index: ProjectModuleIndex, relative: str, name: str) -> ConftestFixture | None:
    """The fixture ``name`` of the nearest conftest over ``relative`` that declares one."""
    for conftest in _conftests(relative):
        fixtures = _fixtures(index, conftest)
        if name in fixtures:
            return fixtures[name]
    return None


def conftest_autouse(index: ProjectModuleIndex, relative: str) -> list[str]:
    """The names of the ``autouse`` fixtures the conftests over ``relative`` apply, nearest first."""
    names: list[str] = []
    seen: set[str] = set()
    for conftest in _conftests(relative):
        for name, fixture in _fixtures(index, conftest).items():
            if name not in seen and fixture.autouse:
                names.append(name)
            seen.add(name)
    return names


def _conftests(relative: str) -> list[str]:
    directory = PurePosixPath(relative).parent
    folders = [(folder / "conftest.py").as_posix() for folder in [directory, *directory.parents]]
    return [conftest for conftest in folders if conftest != relative]


def _fixtures(index: ProjectModuleIndex, relative: str) -> dict[str, ConftestFixture]:
    """Each fixture of the conftest at ``relative``, by name."""
    read = _READ.setdefault(index, {})
    cached = read.get(relative)
    if cached is None:
        index.open_scope()
        try:
            fixtures = _derive(index, relative)
        finally:
            keys = index.close_scope()
        cached = (fixtures, keys)
        read[relative] = cached
    index._note(cached[1])
    return cached[0]


def _derive(index: ProjectModuleIndex, relative: str) -> dict[str, ConftestFixture]:
    tree = _parse(index, relative)
    if tree is None:
        return {}
    # Imported here: the doubles and calls of a file are read with this module's help.
    from .doubles import TestDoubles
    from .file_index import TestFileIndex
    from .imports import NameResolver
    from .production_calls import ProductionCalls, is_autouse

    files = TestFileIndex(tree)
    doubles = TestDoubles(files, NameResolver(index, files, relative, index.layout.module_for(relative)))
    production = ProductionCalls(files, doubles.names, doubles.bindings, doubles.records)
    fixtures: dict[str, ConftestFixture] = {}
    for function in files.functions:
        name = fixture_name(function)
        if name is None or files.scope_of(function) is not files.module_scope:
            continue
        handle = doubles.bindings.fixture(id(tree), name)
        # A root conftest is no test module, so the core holds no node for its doubles.
        named = handle is not None and is_test_path(relative)
        double = handle.record.canonical(relative) if handle is not None and named else ""
        reach = production.local_fixture(function)
        fixtures[name] = ConftestFixture(double, reach.calls, tuple(sorted(reach.subjects)), is_autouse(function))
    return fixtures


def _parse(index: ProjectModuleIndex, relative: str) -> ast.Module | None:
    """Read and parse the conftest at ``relative``, recording the probe and the read; None when there is none."""
    path = index.root / relative
    if not index.layout.is_project_file(path, hash_found=False):
        return None
    walked = walk_path(path)
    location = index._in_root_file(walked)
    try:
        source = None if location is None else read_bounded(location, index.max_bytes)
    except OSError:
        source = None
    if source is None or len(source) > index.max_bytes:
        index._record_walk(walked, None)
        return None
    index._record_walk(walked, hashlib.sha256(source).hexdigest())
    try:
        return ast.parse(source)
    except (SyntaxError, ValueError, RecursionError):
        return None

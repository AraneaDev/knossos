"""What a ``conftest.py`` hands its tests through fixtures, by fixture name: doubles, and calls into production.

pytest gives a test the fixtures of every ``conftest.py`` from the root down
to its own directory, the nearest winning. A fixture there that returns or
yields a double binds a test's parameter of its name to that double, whose
node is the conftest's own: the test file can name it but not add to it.
What a fixture runs of the code under test counts for every test that
requests it, and an ``autouse`` fixture for every test below it. Each
conftest is read once per request, and what reading it cost is named by
every file that asks, as the index does for a module's declarations.

What a conftest's fixtures are is read by the caller's ``derive``
(:func:`.doubles.read_conftest`), handed down rather than imported, since
reading a conftest's doubles and calls needs the very readers that ask here.
"""

from __future__ import annotations

import ast
import hashlib
from collections.abc import Callable
from dataclasses import dataclass
from pathlib import PurePosixPath
from weakref import WeakKeyDictionary

from ..module_index import ProjectModuleIndex
from ..safe_io import read_bounded, walk_path


@dataclass(frozen=True)
class ConftestFixture:
    """A conftest fixture: the canonical name of the double it hands over (or ``""``), and what it runs."""

    double: str
    calls: int
    subjects: tuple[str, ...]
    autouse: bool


Derive = Callable[[ProjectModuleIndex, str, ast.Module], dict[str, ConftestFixture]]
"""Each fixture of a parsed conftest, by name, given the index and the conftest's project-relative path."""

_READ: WeakKeyDictionary[ProjectModuleIndex, dict[str, tuple[dict[str, ConftestFixture], set[str]]]] = (
    WeakKeyDictionary()
)


def conftest_double(index: ProjectModuleIndex, relative: str, name: str, derive: Derive) -> str | None:
    """The canonical name of the double the nearest conftest over ``relative`` delivers as fixture ``name``."""
    found = conftest_fixture(index, relative, name, derive)
    return None if found is None else found.double or None


def conftest_fixture(index: ProjectModuleIndex, relative: str, name: str, derive: Derive) -> ConftestFixture | None:
    """The fixture ``name`` of the nearest conftest over ``relative`` that declares one."""
    for conftest in _conftests(relative):
        fixtures = _fixtures(index, conftest, derive)
        if name in fixtures:
            return fixtures[name]
    return None


def conftest_autouse(index: ProjectModuleIndex, relative: str, derive: Derive) -> list[str]:
    """The names of the ``autouse`` fixtures the conftests over ``relative`` apply, nearest first."""
    names: list[str] = []
    seen: set[str] = set()
    for conftest in _conftests(relative):
        for name, fixture in _fixtures(index, conftest, derive).items():
            if name not in seen and fixture.autouse:
                names.append(name)
            seen.add(name)
    return names


def _conftests(relative: str) -> list[str]:
    directory = PurePosixPath(relative).parent
    folders = [(folder / "conftest.py").as_posix() for folder in [directory, *directory.parents]]
    return [conftest for conftest in folders if conftest != relative]


def _fixtures(index: ProjectModuleIndex, relative: str, derive: Derive) -> dict[str, ConftestFixture]:
    """Each fixture of the conftest at ``relative``, by name."""
    read = _READ.setdefault(index, {})
    cached = read.get(relative)
    if cached is None:
        index.open_scope()
        try:
            tree = _parse(index, relative)
            fixtures = {} if tree is None else derive(index, relative, tree)
        finally:
            keys = index.close_scope()
        cached = (fixtures, keys)
        read[relative] = cached
    index._note(cached[1])
    return cached[0]


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

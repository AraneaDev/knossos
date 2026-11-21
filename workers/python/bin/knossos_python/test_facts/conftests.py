"""The doubles a ``conftest.py`` hands its tests through fixtures, by fixture name.

pytest gives a test the fixtures of every ``conftest.py`` from the root down
to its own directory, the nearest winning. A fixture there that returns or
yields a double binds a test's parameter of its name to that double, whose
node is the conftest's own: the test file can name it but not add to it.
Each conftest is read once per request, and what reading it cost is named by
every file that asks, as the index does for a module's declarations.
"""

from __future__ import annotations

import ast
import hashlib
from pathlib import PurePosixPath
from weakref import WeakKeyDictionary

from ..fixtures import fixture_name
from ..module_index import ProjectModuleIndex
from ..safe_io import read_bounded, walk_path
from .paths import is_test_path

_READ: WeakKeyDictionary[ProjectModuleIndex, dict[str, tuple[dict[str, str], set[str]]]] = WeakKeyDictionary()


def conftest_double(index: ProjectModuleIndex, relative: str, name: str) -> str | None:
    """The canonical name of the double the nearest conftest over ``relative`` delivers as fixture ``name``."""
    directory = PurePosixPath(relative).parent
    for folder in [directory, *directory.parents]:
        conftest = (folder / "conftest.py").as_posix()
        if conftest == relative:
            continue
        fixtures = _fixtures(index, conftest)
        if name in fixtures:
            return fixtures[name] or None
    return None


def _fixtures(index: ProjectModuleIndex, relative: str) -> dict[str, str]:
    """Each fixture of the conftest at ``relative``, by name, with its double's canonical name or ``""``."""
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


def _derive(index: ProjectModuleIndex, relative: str) -> dict[str, str]:
    tree = _parse(index, relative)
    if tree is None:
        return {}
    # Imported here: the doubles of a file are read with this module's help.
    from .doubles import TestDoubles
    from .file_index import TestFileIndex
    from .imports import NameResolver

    files = TestFileIndex(tree)
    doubles = TestDoubles(files, NameResolver(index, files, relative, index.layout.module_for(relative)))
    fixtures: dict[str, str] = {}
    for function in files.functions:
        name = fixture_name(function)
        if name is None or files.scope_of(function) is not files.module_scope:
            continue
        handle = doubles.bindings.fixture(id(tree), name)
        # A root conftest is no test module, so the core holds no node for its doubles.
        named = handle is not None and is_test_path(relative)
        fixtures[name] = handle.record.canonical(relative) if handle is not None and named else ""
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

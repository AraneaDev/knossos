"""A function or method node carries its signature as the source writes it."""

from __future__ import annotations

from typing import Any

SOURCE = """\
from typing import Iterable


def scan(root: str, limit: int | None = None, *rest: str, strict: bool, mode: int = 1, **options: object) -> None:
    pass


def untyped(a, b=1):
    pass


def positional(a: int, /, b: int = 2):
    pass


async def load(rows: Iterable[dict[str, int]]) -> "list[str]":
    return []


class Store:
    def get(self, key: str) -> int:
        return 0

    @classmethod
    def build(cls, size: int = 1) -> "Store":
        return cls()

    @staticmethod
    def make(self, size: int) -> None:
        pass

    def bare():
        pass


def wide(value: dict[str, tuple[int, int, int, int, int, int, int, int, int, int, int, int, int, int, int, int, int]]):
    pass


def many(a, b, c, d, e, f, g, h, i, j, k, l, m):
    pass


def spread(
    value: dict[
        str,
        int,
    ],
):
    pass
"""


def _signatures(contribution: dict) -> dict[str, Any]:
    return {
        node["canonical_name"]: node["attributes"]["signature"]
        for node in contribution["nodes"]
        if node["kind"] in ("function", "method")
    }


def test_functions_carry_the_signature_they_write(project, scan_collect) -> None:
    [contribution] = scan_collect(project({"api.py": SOURCE}), ["api.py"])
    signatures = _signatures(contribution)

    assert signatures["api.scan"] == {
        "params": [
            {"name": "root", "type": "str"},
            {"name": "limit", "type": "int | None", "optional": True},
            {"name": "*rest", "type": "str", "variadic": True},
            {"name": "strict", "type": "bool", "kind": "keyword_only"},
            {"name": "mode", "type": "int", "optional": True, "kind": "keyword_only"},
            {"name": "**options", "type": "object", "variadic": True},
        ],
        "returns": "None",
    }
    assert signatures["api.untyped"] == {"params": [{"name": "a"}, {"name": "b", "optional": True}]}
    assert signatures["api.positional"] == {
        "params": [
            {"name": "a", "type": "int", "kind": "positional_only"},
            {"name": "b", "type": "int", "optional": True},
        ]
    }
    assert signatures["api.load"] == {
        "params": [{"name": "rows", "type": "Iterable[dict[str, int]]"}],
        "returns": "'list[str]'",
    }
    assert signatures["api.spread"] == {"params": [{"name": "value", "type": "dict[str, int]"}]}


def test_a_method_leaves_out_the_receiver_unless_it_is_static(project, scan_collect) -> None:
    [contribution] = scan_collect(project({"api.py": SOURCE}), ["api.py"])
    signatures = _signatures(contribution)

    assert signatures["api.Store::get"] == {"params": [{"name": "key", "type": "str"}], "returns": "int"}
    assert signatures["api.Store::build"] == {
        "params": [{"name": "size", "type": "int", "optional": True}],
        "returns": "'Store'",
    }
    assert signatures["api.Store::make"] == {
        "params": [{"name": "self"}, {"name": "size", "type": "int"}],
        "returns": "None",
    }
    assert signatures["api.Store::bare"] == {"params": []}


def test_a_signature_is_bounded_and_says_when_it_was_cut(project, scan_collect) -> None:
    [contribution] = scan_collect(project({"api.py": SOURCE}), ["api.py"])
    signatures = _signatures(contribution)

    wide = signatures["api.wide"]
    assert len(wide["params"][0]["type"]) == 80
    assert wide["params"][0]["type"].startswith("dict[str, tuple[int, int,")
    assert wide["params"][0]["type"].endswith("…")
    assert wide["truncated"] is True
    assert [param["name"] for param in signatures["api.many"]["params"]] == list("abcdefghijkl")
    assert signatures["api.many"]["truncated"] is True
    assert "truncated" not in signatures["api.scan"]


def test_parameter_kinds(project, scan_collect) -> None:
    source = "class C:\n    def m(self, /, a, *, b):\n        pass\n\n\ndef f(a, /, b, *, c):\n    pass\n"
    [contribution] = scan_collect(project({"api.py": source}), ["api.py"])
    signatures = _signatures(contribution)

    assert signatures["api.f"] == {
        "params": [{"name": "a", "kind": "positional_only"}, {"name": "b"}, {"name": "c", "kind": "keyword_only"}]
    }
    # The receiver is left out whatever its kind, and the kinds stay with their own parameters.
    assert signatures["api.C::m"] == {"params": [{"name": "a"}, {"name": "b", "kind": "keyword_only"}]}


DECORATED = """\
import functools
from abc import abstractmethod
from contextlib import contextmanager
from functools import lru_cache

from retrying import retry


class Store:
    @property
    def size(self) -> int:
        return 0

    @retry
    def fetch(self, key: str) -> str:
        return key

    @staticmethod
    def make(size: int) -> None:
        pass

    @classmethod
    def build(cls) -> None:
        pass

    @abstractmethod
    def close(self) -> None:
        pass

    @contextmanager
    def opened(self):
        yield self

    @lru_cache(2)
    def cached(self, key: str) -> str:
        return key

    @functools.cache
    def whole(self) -> str:
        return ""

    @registry["x"]
    def keyed(self) -> None:
        pass

    def plain(self) -> None:
        pass


registry: dict = {}
"""


def _attributes(contribution: dict) -> dict[str, dict[str, Any]]:
    return {
        node["canonical_name"]: node["attributes"]
        for node in contribution["nodes"]
        if node["kind"] in ("function", "method")
    }


def test_decorated_signatures(project, scan_collect) -> None:
    [contribution] = scan_collect(project({"api.py": DECORATED}), ["api.py"])
    attributes = _attributes(contribution)

    decorated = sorted(name for name, values in attributes.items() if values.get("decorated") is True)
    assert decorated == ["api.Store::fetch", "api.Store::keyed", "api.Store::opened", "api.Store::size"]
    assert all("decorated" not in values or values["decorated"] is True for values in attributes.values())


def test_a_shadowed_builtin_decorator_counts_as_decorated(project, scan_collect) -> None:
    source = "def staticmethod(f):\n    return f\n\n\nclass C:\n    @staticmethod\n    def m():\n        pass\n"
    [contribution] = scan_collect(project({"api.py": source}), ["api.py"])

    assert _attributes(contribution)["api.C::m"].get("decorated") is True


REBOUND = """\
import functools

try:
    from fast import imported
except ImportError:
    def imported(x):
        return x


def wrapped(x):
    return x


wrapped = functools.partial(wrapped, 1)


def branched(x):
    return x


if True:
    def branched(x, y):
        return x


def rewritten(x):
    return x


def install():
    global rewritten
    rewritten = len


def replaced(x):
    return x


def replaced(x, y):
    return x


def plain(x):
    return x


class C:
    def partial(self, a):
        return a

    partial = functools.partialmethod(partial, 1)

    def shadowed(self, a):
        return a

    def __init__(self):
        self.shadowed = len

    def kept(self, a):
        return a
"""


def test_a_def_its_scope_binds_again_counts_as_decorated(project, scan_collect) -> None:
    # Whatever the name holds may have another signature than the def's.
    [contribution] = scan_collect(project({"api.py": REBOUND}), ["api.py"])
    attributes = _attributes(contribution)

    decorated = sorted(name for name, values in attributes.items() if values.get("decorated") is True)
    assert decorated == [
        "api.C::partial",
        "api.C::shadowed",
        "api.branched",
        "api.imported",
        "api.rewritten",
        "api.wrapped",
    ]
    # A def a later one in the same block replaces binds nothing the name keeps: the later one stands alone.
    assert "decorated" not in attributes["api.replaced"]
    assert "decorated" not in attributes["api.plain"]


OVERLOADS = """\
from typing import overload


@overload
def parse(value: int) -> int: ...


@overload
def parse(value: str) -> str: ...


def parse(value):
    return value


def single(value):
    return value
"""


def test_overload_stubs_mark_the_implementation(project, scan_collect) -> None:
    [contribution] = scan_collect(project({"api.py": OVERLOADS}), ["api.py"])
    attributes = _attributes(contribution)

    assert attributes["api.parse"]["overloaded"] is True
    assert "overloaded" not in attributes["api.single"]

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
            {"name": "strict", "type": "bool"},
            {"name": "mode", "type": "int", "optional": True},
            {"name": "**options", "type": "object", "variadic": True},
        ],
        "returns": "None",
    }
    assert signatures["api.untyped"] == {"params": [{"name": "a"}, {"name": "b", "optional": True}]}
    assert signatures["api.positional"] == {
        "params": [{"name": "a", "type": "int"}, {"name": "b", "type": "int", "optional": True}]
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

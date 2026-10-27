"""A name defined twice in one block: which definition the graph describes."""

from __future__ import annotations

from typing import Any

HELPERS = "def alpha():\n    pass\n\n\ndef beta():\n    pass\n\n\n"


def _node(contribution: dict, local_id: str) -> dict[str, Any]:
    [node] = [node for node in contribution["nodes"] if node["local_id"] == local_id]
    return node


def _calls(contribution: dict, source: str) -> list[str]:
    edges = contribution["edges"]
    return sorted(edge["target"] for edge in edges if edge["kind"] == "calls" and edge["source"] == source)


def test_redefined_method_is_the_later_definition(project, scan_collect) -> None:
    # Python binds the second `m`; the first one's body never runs as K.m.
    root = project(
        {"dup.py": HELPERS + "class K:\n    def m(self):\n        alpha()\n\n    def m(self):\n        beta()\n"}
    )
    [contribution] = scan_collect(root, ["dup.py"])
    node = _node(contribution, "py:method:dup.K::m")
    assert (node["evidence"]["start_line"], node["evidence"]["end_line"]) == (13, 14)
    assert _calls(contribution, "py:method:dup.K::m") == ["py:function:dup.beta"]


def test_redefined_module_function_is_the_later_definition(project, scan_collect) -> None:
    root = project({"dup.py": HELPERS + "def f():\n    alpha()\n\n\ndef f():\n    beta()\n"})
    [contribution] = scan_collect(root, ["dup.py"])
    node = _node(contribution, "py:function:dup.f")
    assert node["evidence"]["start_line"] == 13
    assert _calls(contribution, "py:function:dup.f") == ["py:function:dup.beta"]


def test_property_setter_keeps_both_bodies(project, scan_collect) -> None:
    # `@x.setter` is handed the getter's property, so both bodies stay live.
    root = project(
        {
            "dup.py": HELPERS
            + "class K:\n    @property\n    def x(self):\n        alpha()\n\n"
            + "    @x.setter\n    def x(self, value):\n        beta()\n"
        }
    )
    [contribution] = scan_collect(root, ["dup.py"])
    assert _calls(contribution, "py:method:dup.K::x") == ["py:function:dup.alpha", "py:function:dup.beta"]


def test_definition_used_before_its_redefinition_stays_live(project, scan_collect) -> None:
    # `handlers.append(f)` keeps the first `f` reachable after the name moves on.
    source = "handlers = []\n\n\ndef f():\n    alpha()\n\n\nhandlers.append(f)\n\n\ndef f():\n    beta()\n"
    root = project({"dup.py": HELPERS + source})
    [contribution] = scan_collect(root, ["dup.py"])
    assert _calls(contribution, "py:function:dup.f") == ["py:function:dup.alpha", "py:function:dup.beta"]

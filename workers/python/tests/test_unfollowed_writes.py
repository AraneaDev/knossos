"""A write the worker sees but does not follow marks its target's node with the pattern it matched (U1).

The user's decision of 2026-11-27: the writes `docs/languages/python.md`
lists as residuals (G4-02, G4-05, G4-08) are not followed, so a list stays
as it was, but the node names the pattern in ``unfollowed_writes`` and a core
reports what rests on the list as a warning only.
"""

from __future__ import annotations

from typing import Any

import pytest

HELPERS = "def install(kind):\n    kind.extra = 1\n"


def _nodes(project, scan_collect, source: str, path: str = "m.py") -> dict[str, dict[str, Any]]:
    root = project({"helpers.py": HELPERS, "other.py": "class Far:\n    pass\n", path: source})
    [contribution] = [item for item in scan_collect(root, [path]) if item["owner_key"].endswith(path)]
    return {node["canonical_name"]: node["attributes"] for node in contribution["nodes"]}


@pytest.mark.parametrize(
    ("source", "name", "reason"),
    [
        # G4-02: an alias of the receiver, an instance written outside the class.
        ("class C:\n    def __init__(self):\n        me = self\n        me.extra = 1\n", "m.C", "self_alias"),
        (
            'class C:\n    def __init__(self):\n        me = self\n        setattr(me, "extra", 1)\n',
            "m.C",
            "self_alias",
        ),
        ("class C:\n    pass\n\n\nC().extra = 1\n", "m.C", "instance_write"),
        # G4-05: a nested class written, a class held as a parameter default.
        # A nested class takes its own name in the module (`m.I`).
        ("class O:\n    class I:\n        pass\n\n\nO.I.extra = 1\n", "m.I", "nested_class_write"),
        ("class C:\n    pass\n\n\ndef f(k=C):\n    k.extra = 1\n", "m.C", "default_holder"),
        # A call named like a read-only builtin, bound to something else.
        (
            "from helpers import install as callable\n\n\nclass C:\n    pass\n\n\ncallable(C)\n",
            "m.C",
            "rebound_builtin",
        ),
        ('import builtins\n\n\nclass C:\n    pass\n\n\nbuiltins.exec("C.extra = 1")\n', "m.C", "builtins_exec"),
        (
            "import sys\n\n\nclass C:\n    hook = sys._getframe(0).f_locals.setdefault('extra', 1)\n",
            "m.C",
            "assigned_frame_hack",
        ),
    ],
)
def test_a_class_written_in_a_way_the_worker_does_not_follow_names_the_pattern(
    project, scan_collect, source: str, name: str, reason: str
) -> None:
    attributes = _nodes(project, scan_collect, source)[name]

    assert attributes["unfollowed_writes"] == [reason]
    assert "extra" not in attributes["member_names"]


@pytest.mark.parametrize(
    ("source", "reason"),
    [
        # G4-02: a module that writes itself through an import of itself.
        ("import m\n\n\ndef enable():\n    m.LATE = 1\n", "module_self_write"),
        ('import builtins\n\nbuiltins.exec("LATE = 1")\n', "builtins_exec"),
        # G4-08: code that may write a caller's locals.
        ("import sys\n\n\ndef poke():\n    sys._getframe(1).f_locals['x'] = 2\n", "frame_write"),
        ("import sys\n\n\ndef poke():\n    del sys._getframe(1).f_locals['x']\n", "frame_write"),
        ("import sys\n\n\ndef poke():\n    sys._getframe(1).f_locals.update(x=2)\n", "frame_write"),
        # Bound to a name or handed to a call, it may be written out of sight.
        ("import sys\n\n\ndef poke():\n    seen = sys._getframe(1).f_locals\n    seen['x'] = 2\n", "frame_write"),
        ("import sys\n\n\ndef poke(write):\n    write(sys._getframe(1).f_locals)\n", "frame_write"),
    ],
)
def test_a_module_written_in_a_way_the_worker_does_not_follow_names_the_pattern(
    project, scan_collect, source: str, reason: str
) -> None:
    assert _nodes(project, scan_collect, source)["m"]["unfollowed_writes"] == [reason]


def test_an_instance_written_from_another_file_is_a_foreign_write_of_an_instance(project, scan_collect) -> None:
    attributes = _nodes(project, scan_collect, "from other import Far\n\nFar().extra = 1\n")["m"]

    assert attributes["foreign_writes"] == [{"target": "py:class:other.Far", "name": "extra", "instance": True}]


def test_plain_classes_and_modules_name_no_pattern(project, scan_collect) -> None:
    source = (
        "import sys\n\n\nclass C:\n    level = 1\n\n    def __init__(self):\n"
        "        self.extra = 1\n        other = C\n\n\n"
        "def f(k=1):\n    return isinstance(k, C)\n\n\nprint(sys.argv)\n"
    )
    nodes = _nodes(project, scan_collect, source)

    assert ("unfollowed_writes" in nodes["m.C"], "unfollowed_writes" in nodes["m"]) == (False, False)


@pytest.mark.parametrize(
    "source",
    [
        # A frame's locals read, never written (Fix round 1).
        "import sys\n\n\ndef peek():\n    return sys._getframe(1).f_locals.get('x')\n",
        "import sys\n\n\ndef peek():\n    return sys._getframe(1).f_locals['x']\n",
    ],
)
def test_a_module_that_only_reads_a_frames_locals_names_no_pattern(project, scan_collect, source: str) -> None:
    assert "unfollowed_writes" not in _nodes(project, scan_collect, source)["m"]


@pytest.mark.parametrize(
    "source",
    [
        # An alias of the receiver nothing is written through: a traversal, a builder (Fix round 1).
        "class C:\n    parent = None\n\n    def root(self):\n        node = self\n"
        "        while node.parent:\n            node = node.parent\n        return node\n",
        "class C:\n    def build(self):\n        result = self\n        return result\n",
        # A class held as a parameter default that is only called.
        "class C:\n    pass\n\n\ndef f(k=C):\n    return k()\n",
    ],
)
def test_a_holder_nothing_is_written_through_names_no_pattern(project, scan_collect, source: str) -> None:
    assert "unfollowed_writes" not in _nodes(project, scan_collect, source)["m.C"]

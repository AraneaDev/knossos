"""A class node lists its members and a module node its exports, open whenever a name may come from elsewhere."""

from __future__ import annotations

import sys
from typing import Any

import pytest


def _nodes(contribution: dict, kind: str) -> dict[str, dict[str, Any]]:
    return {node["canonical_name"]: node["attributes"] for node in contribution["nodes"] if node["kind"] == kind}


def _class(project, scan_collect, source: str, name: str = "m.C") -> dict[str, Any]:
    [contribution] = scan_collect(project({"m.py": source}), ["m.py"])
    return _nodes(contribution, "class")[name]


REPO = """\
class Repo:
    __slots__ = ("s",)
    x = 1
    y: int

    def __init__(self) -> None:
        self.z = 0

    def m(self) -> None:
        pass

    def __hide(self) -> None:
        pass
"""


def test_lists_methods_attributes_and_instance_names(project, scan_collect) -> None:
    attributes = _class(project, scan_collect, REPO, "m.Repo")

    # Ruling P3: `__slots__` names are members; dunders (`__init__`, `__slots__`) are never listed.
    assert attributes["member_names"] == ["_Repo__hide", "__hide", "m", "s", "x", "y", "z"]
    assert "member_names_open" not in attributes


def test_lists_nested_classes_class_writes_and_literal_setattr(project, scan_collect) -> None:
    source = """\
class C:
    class Inner:
        pass

    if True:
        flag = True
    else:
        other = False

    @classmethod
    def build(cls):
        cls.made = 1
        self, value = cls(), 2
        self.__secret = value
        setattr(self, "named", value)
        object.__setattr__(self, "frozen", value)

    def keep(this, other):
        this.held = 1
        other.elsewhere = 1
"""
    attributes = _class(project, scan_collect, source)

    assert attributes["member_names"] == [
        "Inner",
        "_C__secret",
        "__secret",
        "build",
        "flag",
        "frozen",
        "held",
        "keep",
        "made",
        "named",
        "other",
    ]
    assert "member_names_open" not in attributes


OPENERS = {
    "__getattr__": "class C:\n    def __getattr__(self, name):\n        return name\n",
    "__getattr__ assigned": "def look(self, name):\n    return name\n\n\nclass C:\n    __getattr__ = look\n",
    "__getattribute__": "class C:\n    def __getattribute__(self, name):\n        return name\n",
    "__init_subclass__": "class C:\n    def __init_subclass__(cls, **kw):\n        cls.extra = 1\n",
    "metaclass": "class Meta(type):\n    pass\n\n\nclass C(metaclass=Meta):\n    pass\n",
    "class keyword": "class C(total=False):\n    pass\n",
    "decorator": "def register(cls):\n    return cls\n\n\n@register\nclass C:\n    pass\n",
    "attrs": "import attr\n\n\n@attr.s\nclass C:\n    x = attr.ib()\n",
    "unresolved dataclass": "@dataclass\nclass C:\n    x: int\n",
    "subscripted project base": "class Base:\n    pass\n\n\nclass C(Base[int]):\n    pass\n",
    "unresolvable base": "class C(Unknown):\n    pass\n",
    "builtin base": "class C(Exception):\n    pass\n",
    "called base": "def make():\n    return object\n\n\nclass C(make()):\n    pass\n",
    "dynamic setattr": "class C:\n    def __init__(self, key, value):\n        setattr(self, key, value)\n",
    "dynamic __setattr__": "class C:\n    def __init__(self, key):\n        object.__setattr__(self, key, 1)\n",
    "__dict__ write": "class C:\n    def __init__(self, **kw):\n        self.__dict__.update(kw)\n",
    "vars write": "class C:\n    def __init__(self, **kw):\n        vars(self).update(kw)\n",
    "body for": "class C:\n    for name in ('a', 'b'):\n        pass\n",
    "body while": "class C:\n    while False:\n        pass\n",
    "body with": "import contextlib\n\n\nclass C:\n    with contextlib.suppress():\n        pass\n",
    "body match": "class C:\n    match 1:\n        case _:\n            pass\n",
    "body exec": "class C:\n    exec('x = 1')\n",
    "body locals": "class C:\n    locals()['x'] = 1\n",
    "non-literal slots": "NAMES = ('a',)\n\n\nclass C:\n    __slots__ = NAMES\n",
    "extended slots": "class C:\n    __slots__ = ('a',)\n    __slots__ += ('b',)\n",
    "module-level write": "class C:\n    pass\n\n\nC.extra = 1\n",
    "write in a function": "class C:\n    pass\n\n\ndef install():\n    C.extra = 1\n",
    "module-level setattr": "class C:\n    pass\n\n\nsetattr(C, 'extra', 1)\n",
    "rebound": "def wrap(cls):\n    return cls\n\n\nclass C:\n    pass\n\n\nC = wrap(C)\n",
    "imported instead": "try:\n    from fast import C\nexcept ImportError:\n\n    class C:\n        pass\n",
    # Review I2: dynamic module code may write any class it names.
    "module exec": "class C:\n    a = 1\n\n\nexec('C.b = 2')\n",
    "module eval": "class C:\n    a = 1\n\n\neval('setattr(C, \"b\", 2)')\n",
    "module globals write": "class C:\n    a = 1\n\n\nglobals()['C'].b = 2\n",
    "module vars write": "class C:\n    a = 1\n\n\nvars()['C'].b = 2\n",
    "sys.modules write": "import sys\n\n\nclass C:\n    a = 1\n\n\nsys.modules[__name__].C.b = 2\n",
    # Review I3a: a class handed to a call may be written by it.
    "helper writes param": "def install(t):\n    t.b = 2\n\n\nclass C:\n    a = 1\n\n\ninstall(C)\n",
    "helper setattr param": "def install(t, n):\n    setattr(t, n, 2)\n\n\nclass C:\n    a = 1\n\n\ninstall(C, 'b')\n",
    "keyword argument": "import registry\n\n\nclass C:\n    a = 1\n\n\nregistry.add(target=C)\n",
    "call in a function": "import registry\n\n\nclass C:\n    a = 1\n\n\ndef setup():\n    registry.add(C)\n",
    "starred argument": "import registry\n\n\nclass C:\n    a = 1\n\n\nregistry.add(*[C])\n",
    # Review I3b: a base bound twice may be either class.
    "base imported twice": (
        "try:\n    from a import B\nexcept ImportError:\n    from b import B\n\n\nclass C(B):\n    z = 1\n"
    ),
    "base under TYPE_CHECKING": (
        "from typing import TYPE_CHECKING\n\nif TYPE_CHECKING:\n    from a import B\nelse:\n    from b import B\n\n\n"
        "class C(B):\n    z = 1\n"
    ),
    "base module imported twice": (
        "try:\n    import a\nexcept ImportError:\n    import b as a\n\n\nclass C(a.B):\n    z = 1\n"
    ),
    "generic of a base bound twice": (
        "try:\n    from a import B\nexcept ImportError:\n    from b import B\n\n\nclass C(B[int]):\n    z = 1\n"
    ),
    # Review M1: a bare call in the class body may write the class through its frame.
    "class body call": "from zope.interface import implements\n\n\nclass C:\n    implements(object)\n    a = 1\n",
}


@pytest.mark.parametrize("source", OPENERS.values(), ids=OPENERS.keys())
def test_opening_constructs(project, scan_collect, source: str) -> None:
    attributes = _class(project, scan_collect, source)

    assert attributes["member_names_open"] is True
    assert isinstance(attributes["member_names"], list)


@pytest.mark.skipif(sys.version_info < (3, 12), reason="type parameter syntax is Python 3.12")
def test_type_parameters_keep_the_list_closed_as_a_generic_base_does(project, scan_collect) -> None:
    # Ruling P19: `class C[T]` is `class C(Generic[T])`, which adds no member.
    attributes = _class(project, scan_collect, "class C[T]:\n    pass\n")

    assert "member_names_open" not in attributes


CLOSED = {
    "no base": "class C:\n    pass\n",
    "object": "class C(object):\n    pass\n",
    "abc.ABC": "import abc\n\n\nclass C(abc.ABC):\n    pass\n",
    "ABC": "from abc import ABC\n\n\nclass C(ABC):\n    pass\n",
    "Protocol": "from typing import Protocol\n\n\nclass C(Protocol):\n    pass\n",
    "project base": "class Base:\n    pass\n\n\nclass C(Base):\n    pass\n",
    "dataclass": "from dataclasses import dataclass\n\n\n@dataclass\nclass C:\n    x: int\n",
    "dataclass call": "import dataclasses\n\n\n@dataclasses.dataclass(frozen=True)\nclass C:\n    x: int\n",
    "final": "from typing import final\n\n\n@final\nclass C:\n    pass\n",
    "typing.final": "import typing\n\n\n@typing.final\nclass C:\n    pass\n",
    "literal slots": "class C:\n    __slots__ = {'a': 'doc'}\n",
    "single slot": "class C:\n    __slots__ = 'a'\n",
    # Ruling P19: Generic[...] and Protocol[...] add no member and give no edge.
    "generic base": "from typing import Generic, TypeVar\n\nT = TypeVar('T')\n\n\nclass C(Generic[T]):\n    pass\n",
    "typing.Generic": "import typing\n\nT = typing.TypeVar('T')\n\n\nclass C(typing.Generic[T]):\n    pass\n",
    "protocol base": "from typing import Protocol, TypeVar\n\nT = TypeVar('T')\n\n\nclass C(Protocol[T]):\n    pass\n",
    "typing_extensions.Generic": (
        "from typing_extensions import Generic, TypeVar\n\nT = TypeVar('T')\n\n\nclass C(Generic[T]):\n    pass\n"
    ),
    "typing_extensions.Protocol": (
        "import typing_extensions\n\nT = typing_extensions.TypeVar('T')\n\n\n"
        "class C(typing_extensions.Protocol[T]):\n    pass\n"
    ),
    # Review I3a: a read-only builtin reads the class it is handed.
    "read-only builtins": (
        "class C:\n    a = 1\n\n\ndef check(x):\n    return isinstance(x, C) or issubclass(type(x), C) or repr(C)\n"
    ),
    "a call in the class body that binds a name": "import attr\n\n\nclass C:\n    a = attr.ib()\n",
}


@pytest.mark.parametrize("source", CLOSED.values(), ids=CLOSED.keys())
def test_known_empty_bases_keep_the_list_closed(project, scan_collect, source: str) -> None:
    attributes = _class(project, scan_collect, source)

    assert "member_names_open" not in attributes
    assert isinstance(attributes["member_names"], list)


def test_a_class_declared_twice_offers_the_names_of_either(project, scan_collect) -> None:
    source = "import sys\n\nif sys.platform:\n\n    class C:\n        a = 1\nelse:\n\n    class C:\n        b = 2\n"
    attributes = _class(project, scan_collect, source)

    assert attributes["member_names"] == ["a", "b"]
    assert "member_names_open" not in attributes


EXPORTS = """\
import os.path
from a import b as c

try:
    import y
except ImportError:
    y = None


def f():
    pass


class Cls:
    pass


X = 1
__all__ = ["f"]
"""


def test_module_exports(project, scan_collect) -> None:
    [contribution] = scan_collect(project({"m.py": EXPORTS}), ["m.py"])
    attributes = _nodes(contribution, "module")["m"]

    assert attributes["exports"] == ["Cls", "X", "c", "f", "os", "y"]
    assert "exports_open" not in attributes


def test_module_exports_take_globals_and_every_branch(project, scan_collect) -> None:
    source = """\
import sys

if sys.version_info >= (3, 12):
    from a import fast as impl
else:
    impl = None

for index in range(2):
    pass

with open("f") as handle:
    pass

if (found := 1):
    pass

try:
    pass
except ValueError as error:
    pass

match sys.argv:
    case [first, *rest]:
        pass
    case {"k": value, **others}:
        pass

handler = lambda event=None: event


def setup():
    global CONFIG
    CONFIG = {}

    def inner():
        local = 1
        return local

    return inner
"""
    [contribution] = scan_collect(project({"m.py": source}), ["m.py"])
    attributes = _nodes(contribution, "module")["m"]

    assert attributes["exports"] == [
        "CONFIG",
        "error",
        "first",
        "found",
        "handle",
        "handler",
        "impl",
        "index",
        "others",
        "rest",
        "setup",
        "sys",
        "value",
    ]
    assert "exports_open" not in attributes


MODULE_OPENERS = {
    "star import": "from os.path import *\n",
    "module __getattr__": "def __getattr__(name):\n    return name\n",
    "globals write": "globals()['x'] = 1\n",
    "globals in a function": "def define(name):\n    globals()[name] = 1\n",
    "module locals": "locals()['x'] = 1\n",
    "module vars": "vars()['x'] = 1\n",
    "exec": "exec('x = 1')\n",
    "eval": "eval('(x := 1)')\n",
    "sys.modules": "import sys\n\nsetattr(sys.modules[__name__], 'x', 1)\n",
}


@pytest.mark.parametrize("source", MODULE_OPENERS.values(), ids=MODULE_OPENERS.keys())
def test_star_import_and_module_getattr_open_exports(project, scan_collect, source: str) -> None:
    [contribution] = scan_collect(project({"m.py": source}), ["m.py"])
    attributes = _nodes(contribution, "module")["m"]

    assert attributes["exports_open"] is True
    assert isinstance(attributes["exports"], list)


def test_a_package_leaves_its_submodules_out_and_stays_open(project, scan_collect) -> None:
    root = project({"pkg/__init__.py": "X = 1\n", "pkg/sub.py": "Y = 2\n"})
    [package, submodule] = scan_collect(root, ["pkg/__init__.py", "pkg/sub.py"])

    # Ruling P7: a submodule is never listed, and the package's list stays open for the ones it may gain.
    assert _nodes(package, "module")["pkg"]["exports"] == ["X"]
    assert _nodes(package, "module")["pkg"]["exports_open"] is True
    assert _nodes(submodule, "module")["pkg.sub"]["exports"] == ["Y"]
    assert "exports_open" not in _nodes(submodule, "module")["pkg.sub"]


VISIBILITY = """\
class C:
    def _p(self):
        pass

    def __q(self):
        pass

    def __eq__(self, other):
        return True

    def pub(self):
        pass


def _h():
    pass
"""


def test_visibility_by_name(project, scan_collect) -> None:
    [contribution] = scan_collect(project({"m.py": VISIBILITY}), ["m.py"])
    methods = _nodes(contribution, "method")

    assert methods["m.C::_p"]["visibility"] == "protected"
    assert methods["m.C::__q"]["visibility"] == "private"
    assert "visibility" not in methods["m.C::__eq__"]
    assert "visibility" not in methods["m.C::pub"]
    assert "visibility" not in _nodes(contribution, "function")["m._h"]


REWRITABLE = {
    "a project metaclass": (
        "class Wrapping(type):\n    pass\n\n\nclass C(metaclass=Wrapping):\n    def m(self):\n        pass\n"
    ),
    "an __init_subclass__": "class C:\n    def __init_subclass__(cls, **kwargs):\n        pass\n",
    "an __init_subclass__ assigned": (
        "def hook(cls):\n    pass\n\n\nclass C:\n    __init_subclass__ = classmethod(hook)\n"
    ),
    "a class decorator": "def inject(cls):\n    return cls\n\n\n@inject\nclass C:\n    pass\n",
    "a call handed the class": "class C:\n    pass\n\n\ninstall(C)\n",
}

PLAIN = {
    "no hook": "class C:\n    def m(self):\n        pass\n",
    "type": "class C(metaclass=type):\n    pass\n",
    "ABCMeta": "import abc\n\n\nclass C(metaclass=abc.ABCMeta):\n    pass\n",
    "a dataclass": "from dataclasses import dataclass\n\n\n@dataclass\nclass C:\n    x: int = 0\n",
    "an external base": "from enum import Enum\n\n\nclass C(Enum):\n    A = 1\n",
}


@pytest.mark.parametrize("source", REWRITABLE.values(), ids=REWRITABLE.keys())
def test_a_class_that_may_rewrite_methods_says_so(project, scan_collect, source: str) -> None:
    # Ruling P21: the class's own methods, or a subclass's, may not keep the signature their def writes.
    assert _class(project, scan_collect, source).get("methods_rewritable") is True


@pytest.mark.parametrize("source", PLAIN.values(), ids=PLAIN.keys())
def test_a_plain_class_says_nothing_of_rewriting(project, scan_collect, source: str) -> None:
    assert "methods_rewritable" not in _class(project, scan_collect, source)

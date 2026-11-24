"""The complete name lists a class or a module offers, `member_names` and `exports`, open on any doubt.

A list is a claim that a name missing from it is missing at run time, and a
false claim gives a false ghost later. So a list is closed only where this
worker can see every name that can appear; anything that may add a name it
cannot see (a hook, a metaclass, a decorator, a base it cannot read, a write
through ``setattr``, ``__dict__`` or ``globals()``) marks it open. Dunder names
(``__x__``) are protocol hooks every class and module answers in part, so
neither list records them, and a reader never judges one.
"""

import ast
from collections import Counter
from collections.abc import Callable, Iterable, Iterator
from typing import Any

from .ast_helpers import dotted

Resolve = Callable[[str], str | None]

OPENING_HOOKS = frozenset({"__getattr__", "__getattribute__", "__init_subclass__"})
"""A class that defines one answers names, or hands its subclasses names, that its body never binds."""

CLOSED_DECORATORS = frozenset({"dataclasses.dataclass", "typing.final", "typing_extensions.final"})
"""Class decorators that add no name besides dunders: every other may add any."""

_RUNS_DYNAMIC_CODE = frozenset({"exec", "eval"})

GENERIC_BASES = frozenset(
    {"typing.Generic", "typing.Protocol", "typing_extensions.Generic", "typing_extensions.Protocol"}
)
"""Subscripted, these give no ``extends`` edge and add no member (Ruling P19), as PEP 695 type parameters do not."""

READ_ONLY_CALLS = frozenset(
    {
        "callable",
        "cast",
        "dir",
        "getattr",
        "hasattr",
        "hash",
        "id",
        "isinstance",
        "issubclass",
        "print",
        "repr",
        "str",
        "super",
        "type",
        "typing.cast",
    }
)
"""Calls that only read the class they are handed: any other call may write it (``install(C)``)."""


def is_dunder(name: str) -> bool:
    """Whether ``name`` is spelled ``__x__``, which the compiler never mangles."""
    return name.startswith("__") and name.endswith("__")


def mangled(owner: str, name: str) -> str | None:
    """The name ``__x`` takes inside class ``owner`` (``_Owner__x``), or None when it is not mangled."""
    stripped = owner.lstrip("_")
    if not stripped or not name.startswith("__") or is_dunder(name):
        return None
    return f"_{stripped}{name}"


def visibility(name: str) -> dict[str, Any]:
    """A method's ``visibility`` attribute: ``protected`` for ``_x``, ``private`` for ``__x``, none otherwise."""
    if name.startswith("__"):
        return {} if is_dunder(name) else {"visibility": "private"}
    return {"visibility": "protected"} if name.startswith("_") else {}


def assigned_self_attributes(node: ast.ClassDef) -> frozenset[str]:
    """The `self.<name>` attributes the methods of `node` assign, in any form of assignment."""
    names: set[str] = set()
    for item in node.body:
        if not isinstance(item, (ast.FunctionDef, ast.AsyncFunctionDef)):
            continue
        for statement in ast.walk(item):
            if isinstance(statement, ast.Assign):
                targets = statement.targets
            elif isinstance(statement, (ast.AnnAssign, ast.AugAssign)):
                targets = [statement.target]
            else:
                continue
            for target in targets:
                if isinstance(target, ast.Attribute) and dotted(target.value) == "self":
                    names.add(target.attr)
    return frozenset(names)


def scope_nodes(statements: Iterable[ast.AST]) -> Iterator[ast.AST]:
    """Every node that runs in the scope ``statements`` belong to.

    A nested ``def``, ``class`` or ``lambda`` runs its header here (decorators,
    defaults, annotations, bases) and its body in a scope of its own, which is
    left out. A comprehension's loop names are its own, but listing them is
    harmless: a list may name too much, never too little.
    """
    pending: list[ast.AST] = list(reversed(list(statements)))
    while pending:
        node = pending.pop()
        yield node
        if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef)):
            children: list[ast.AST] = [*node.decorator_list, node.args]
            children += [node.returns] if node.returns is not None else []
        elif isinstance(node, ast.ClassDef):
            children = [*node.decorator_list, *node.bases, *node.keywords]
        elif isinstance(node, ast.Lambda):
            children = [node.args]
        else:
            children = list(ast.iter_child_nodes(node))
        pending.extend(reversed(children))


def scope_bindings(nodes: Iterable[ast.AST]) -> tuple[set[str], bool]:
    """The names the scope's ``nodes`` bind, and whether one is ``import *``."""
    names: set[str] = set()
    star = False
    for node in nodes:
        if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef, ast.ClassDef)):
            names.add(node.name)
        elif isinstance(node, ast.Name) and isinstance(node.ctx, ast.Store):
            names.add(node.id)
        elif isinstance(node, (ast.Import, ast.ImportFrom)):
            for alias in node.names:
                star = star or alias.name == "*"
                if alias.name != "*":
                    names.add(alias.asname or alias.name.split(".")[0])
        elif isinstance(node, (ast.ExceptHandler, ast.MatchAs, ast.MatchStar)) and node.name:
            names.add(node.name)
        elif isinstance(node, ast.MatchMapping) and node.rest:
            names.add(node.rest)
    return names, star


def rebound_definitions(tree: ast.Module, superseded: set[ast.AST]) -> set[ast.AST]:
    """Each module-level or class-level ``def`` whose name its scope binds again: its signature may not be the name's.

    ``f = wrap(f)``, a second ``def f`` in another branch, a ``try`` import
    with a fallback ``def``, ``m = partialmethod(m, 1)`` in a class body, a
    function's ``global f``, a method's ``self.m = …``: any of them may leave
    the name holding something else, with another signature. A ``def`` a
    later one in the same block replaces (``superseded``) is not a binding the
    name keeps, so it is not counted.
    """
    functions = (ast.FunctionDef, ast.AsyncFunctionDef)
    declared = (ast.Global, ast.Nonlocal)
    rewritten = {name for node in ast.walk(tree) if isinstance(node, declared) for name in node.names}
    scopes: list[ast.Module | ast.ClassDef] = [
        tree,
        *(item for item in ast.walk(tree) if isinstance(item, ast.ClassDef)),
    ]
    marked: set[ast.AST] = set()
    for scope in scopes:
        counts: Counter[str] = Counter()
        definitions: list[ast.FunctionDef | ast.AsyncFunctionDef] = []
        for node in scope_nodes(scope.body):
            if node in superseded:
                continue
            if isinstance(node, functions):
                definitions.append(node)
            counts.update(_bound_names(node))
        also = assigned_self_attributes(scope) if isinstance(scope, ast.ClassDef) else rewritten
        marked.update(each for each in definitions if counts[each.name] > 1 or each.name in also)
    return marked


def _bound_names(node: ast.AST) -> list[str]:
    """The names one node of a scope binds, rebinds or deletes."""
    if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef, ast.ClassDef)):
        return [node.name]
    if isinstance(node, ast.Name) and isinstance(node.ctx, (ast.Store, ast.Del)):
        return [node.id]
    if isinstance(node, (ast.Import, ast.ImportFrom)):
        return [alias.asname or alias.name.split(".")[0] for alias in node.names if alias.name != "*"]
    if isinstance(node, (ast.ExceptHandler, ast.MatchAs, ast.MatchStar)) and node.name:
        return [node.name]
    if isinstance(node, ast.MatchMapping) and node.rest:
        return [node.rest]
    return []


def _named_call(node: ast.AST, names: Iterable[str]) -> bool:
    return isinstance(node, ast.Call) and isinstance(node.func, ast.Name) and node.func.id in names


def _literal_name(node: ast.AST | None) -> str | None:
    return node.value if isinstance(node, ast.Constant) and isinstance(node.value, str) else None


def runs_dynamic_code(tree: ast.Module) -> bool:
    """Whether the module binds or writes names no source spells.

    ``globals()``, ``exec``, ``eval`` or ``sys.modules`` anywhere, or
    ``locals()`` or ``vars()`` at module level, can reach any of its names:
    its exports and the attributes of every class it declares.
    """
    if any(_named_call(node, ("locals", "vars")) for node in scope_nodes(tree.body)):
        return True
    return any(
        _named_call(node, {"globals", *_RUNS_DYNAMIC_CODE}) or dotted(node) == "sys.modules" for node in ast.walk(tree)
    )


def module_exports(tree: ast.Module, is_package: bool, dynamic: bool) -> dict[str, Any]:
    """A module node's ``exports``, and ``exports_open`` when names may come from elsewhere.

    The list holds every top-level binding, in every branch of an ``if`` or
    ``try``, and every name a ``global`` declaration hands the module. It is
    open for ``import *``, a module ``__getattr__`` and ``dynamic`` code
    (:func:`runs_dynamic_code`). A package is always open: any submodule, one added
    after this scan included, becomes its attribute once imported, and this
    worker lists no directory, so the list could never be shown complete.
    """
    names, opened = scope_bindings(scope_nodes(tree.body))
    names |= {name for node in ast.walk(tree) if isinstance(node, ast.Global) for name in node.names}
    opened = opened or is_package or dynamic or "__getattr__" in names
    return {"exports": sorted(name for name in names if not is_dunder(name))} | (
        {"exports_open": True} if opened else {}
    )


def slot_names(value: ast.AST | None) -> list[str] | None:
    """The names a ``__slots__`` value lists, or None when it is not literal strings."""
    single = _literal_name(value)
    if single is not None:
        return [single]
    if isinstance(value, ast.Dict):
        elements: list[ast.expr | None] = list(value.keys)
    elif isinstance(value, (ast.Tuple, ast.List, ast.Set)):
        elements = list(value.elts)
    else:
        return None
    names = [_literal_name(element) for element in elements]
    return None if any(name is None for name in names) else [name for name in names if name is not None]


class _ClassScan:
    """The names one class body and its methods bind, and whether anything they do may add a name unseen."""

    def __init__(self, node: ast.ClassDef) -> None:
        self.node = node
        body = list(scope_nodes(node.body))
        self.names, _ = scope_bindings(body)
        self.opened = bool(self.names & OPENING_HOOKS)
        for item in body:
            self.body_node(item)
        for item in body:
            if isinstance(item, (ast.FunctionDef, ast.AsyncFunctionDef)):
                self.method(item)
        for item in ast.walk(node):
            # `self.__dict__[k] = v`, `vars(self).update(...)` and `exec` write names no source spells.
            if (isinstance(item, ast.Attribute) and item.attr == "__dict__") or _named_call(
                item, {"vars", *_RUNS_DYNAMIC_CODE}
            ):
                self.opened = True

    def body_node(self, item: ast.AST) -> None:
        """A node of the class body: a loop, a ``with``, a ``match`` or ``locals()`` binds unseen names."""
        if isinstance(item, (ast.For, ast.AsyncFor, ast.While, ast.With, ast.AsyncWith, ast.Match)):
            self.opened = True
        elif _named_call(item, ("locals",)) or (isinstance(item, ast.AugAssign) and dotted(item.target) == "__slots__"):
            self.opened = True
        elif isinstance(item, ast.Expr) and isinstance(item.value, ast.Call):
            # `implements(I)`: a call made for its effect may write the class through its frame.
            self.opened = self.opened or dotted(item.value.func) not in READ_ONLY_CALLS
        elif isinstance(item, (ast.Assign, ast.AnnAssign)):
            targets = item.targets if isinstance(item, ast.Assign) else [item.target]
            if any(dotted(target) == "__slots__" for target in targets):
                slots = slot_names(item.value)
                self.opened = self.opened or slots is None
                self.names.update(slots or [])

    def method(self, item: ast.FunctionDef | ast.AsyncFunctionDef) -> None:
        """The attributes a method writes on its receiver or its class: ``self.x = v``, ``setattr(self, "x", v)``."""
        receivers = {"self", "cls", self.node.name}
        positional = [*item.args.posonlyargs, *item.args.args]
        if positional:
            receivers.add(positional[0].arg)
        for child in ast.walk(item):
            if isinstance(child, ast.Attribute) and isinstance(child.ctx, ast.Store):
                if isinstance(child.value, ast.Name) and child.value.id in receivers:
                    self.names.add(child.attr)
            elif isinstance(child, ast.Call):
                self.setattr_call(child, receivers)

    def setattr_call(self, call: ast.Call, receivers: set[str]) -> None:
        """``setattr(self, name, v)`` and ``__setattr__(...)``: a literal name is a member, any other opens."""
        if isinstance(call.func, ast.Attribute) and call.func.attr == "__setattr__":
            # `object.__setattr__(self, "x", v)` names it second, `super().__setattr__("x", v)` first.
            spelled = call.args[1] if len(call.args) >= 3 else (call.args[0] if call.args else None)
        elif (
            _named_call(call, ("setattr",))
            and call.args
            and isinstance(call.args[0], ast.Name)
            and call.args[0].id in receivers
        ):
            spelled = call.args[1] if len(call.args) > 1 else None
        else:
            return
        name = _literal_name(spelled)
        if name is None:
            self.opened = True
        else:
            self.names.add(name)


class ClassMembers:
    """The ``member_names`` of each class one module declares, open on any doubt."""

    def __init__(self, tree: ast.Module, resolve: Resolve) -> None:
        self.tree = tree
        self.resolve = resolve
        self._written: set[str] | None = None
        self._bindings: Counter[str] | None = None
        self.dynamic = runs_dynamic_code(tree)

    def rebound(self, superseded: set[ast.AST]) -> set[ast.AST]:
        """The module's defs whose name their scope binds again (:func:`rebound_definitions`)."""
        return rebound_definitions(self.tree, superseded)

    def attributes(self, node: ast.ClassDef, unseen_base: bool) -> dict[str, Any]:
        """``member_names`` for ``node``, with ``member_names_open`` when a name may come from elsewhere.

        ``unseen_base`` says a base may give names this scan cannot see
        (:meth:`unseen_base`). Type parameters (``class C[T]``) add none.
        """
        scan = _ClassScan(node)
        names = scan.names | {name for name in (mangled(node.name, each) for each in scan.names) if name}
        opened = scan.opened or unseen_base or bool(node.keywords) or self.dynamic
        opened = opened or not all(self.closed_decorator(decorator) for decorator in node.decorator_list)
        opened = opened or node.name in self.written_names()
        return {"member_names": sorted(name for name in names if not is_dunder(name))} | (
            {"member_names_open": True} if opened else {}
        )

    def unseen_base(self, base: ast.expr, resolved: bool) -> bool:
        """Whether a base may give the class names this scan cannot see.

        A base with no ``extends`` edge does (``Base[int]``, a call, a
        builtin, a name that does not resolve), except ``object`` and a
        subscripted ``Generic`` or ``Protocol`` (Ruling P19). So does one whose
        name the module binds more than once, as a ``try``/``except
        ImportError`` or ``TYPE_CHECKING``/``else`` pair of imports does: its
        one edge names a class that may not be the one that runs.
        """
        named = base.value if isinstance(base, ast.Subscript) else base
        name = dotted(named)
        if name is not None and self.bindings()[name.split(".")[0]] > 1:
            return True
        if resolved:
            return False
        if isinstance(base, ast.Subscript):
            target = self.resolve(name) if name else None
            return target is None or target.split(":", 2)[-1] not in GENERIC_BASES
        return name != "object"

    def bindings(self) -> Counter[str]:
        """How many times the module scope binds each name."""
        if self._bindings is None:
            counts: Counter[str] = Counter()
            for node in scope_nodes(self.tree.body):
                counts.update(scope_bindings([node])[0])
            self._bindings = counts
        return self._bindings

    def closed_decorator(self, decorator: ast.expr) -> bool:
        """Whether a class decorator adds no member (``dataclass``, ``final``), as its import resolves."""
        name = dotted(decorator.func if isinstance(decorator, ast.Call) else decorator)
        resolved = self.resolve(name) if name else None
        return resolved is not None and resolved.split(":", 2)[-1] in CLOSED_DECORATORS

    def written_names(self) -> set[str]:
        """Names the module writes an attribute on, hands to a call, or binds other than by ``class``.

        A class written (``C.x = v``, ``setattr(C, ...)``) or handed to a call
        that may write it (``install(C)``; :data:`READ_ONLY_CALLS` only read)
        may gain any member, and one rebound (``C = register(C)``, ``from x
        import C`` in a ``try``, a ``def C``) may not be the class its body
        declares.
        """
        if self._written is None:
            written: set[str] = set()
            for node in ast.walk(self.tree):
                if isinstance(node, ast.Attribute) and isinstance(node.ctx, (ast.Store, ast.Del)):
                    written.add(dotted(node.value) or "")
                elif isinstance(node, (ast.Name, ast.FunctionDef, ast.AsyncFunctionDef, ast.Import, ast.ImportFrom)):
                    written |= scope_bindings([node])[0]
                elif isinstance(node, ast.Call) and dotted(node.func) not in READ_ONLY_CALLS:
                    arguments = [*node.args, *(keyword.value for keyword in node.keywords)]
                    for argument in arguments:
                        spelled = argument.value if isinstance(argument, ast.Starred) else argument
                        written.add(dotted(spelled) or "")
                        written |= {dotted(each) or "" for each in getattr(spelled, "elts", [])}
            self._written = written
        return self._written


def merge_member_names(attributes: dict[str, Any], found: dict[str, Any]) -> None:
    """Fold a class's names into the attributes of a node that may already hold another declaration's.

    Two classes of one name (``if``/``else`` branches, a function-local class)
    share a node, so it offers the names of either, open if either is.
    """
    attributes["member_names"] = sorted({*attributes.get("member_names", []), *found["member_names"]})
    if attributes.get("member_names_open") or found.get("member_names_open"):
        attributes["member_names_open"] = True

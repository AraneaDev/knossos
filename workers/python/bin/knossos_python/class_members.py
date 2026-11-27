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
from .unfollowed_writes import UnfollowedWrites

Resolve = Callable[[str], str | None]

OPENING_HOOKS = frozenset({"__getattr__", "__getattribute__", "__init_subclass__"})
"""A class that defines one answers names, or hands its subclasses names, that its body never binds."""

CLOSED_DECORATORS = frozenset({"dataclasses.dataclass", "typing.final", "typing_extensions.final"})
"""Class decorators that add no name besides dunders: every other may add any."""

PLAIN_CLASS_DECORATORS = CLOSED_DECORATORS | frozenset(
    {
        "enum.unique",
        "functools.total_ordering",
        "typing.runtime_checkable",
        "typing_extensions.runtime_checkable",
    }
)
"""Class decorators that leave every method the class body defines as it is written."""

PLAIN_METACLASSES = frozenset({"abc.ABCMeta", "enum.EnumMeta", "enum.EnumType", "type"})
"""Metaclasses that leave every method the class body defines as it is written."""

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
    """The attributes the methods of ``node`` write on their receiver or class, in any form :class:`_ClassScan` reads.

    ``self.x = v``, ``self.x, self.y = …``, ``with … as self.x``, ``for
    self.x in …`` and a literal ``setattr(self, "x", v)`` alike: one
    definition of a name a class writes on ``self``, shared with
    ``member_names``.
    """
    return frozenset(_ClassScan(node).written)


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


def rebinding_names(tree: ast.Module) -> frozenset[str]:
    """The names a module binds at its top level other than by an import, or any scope declares ``global``.

    A name it imports and then binds again (``kw = partial(kw, 1)``) is no
    longer the import's. An ``except`` fallback for an import its ``try``
    makes (:func:`_import_fallbacks`) does not count.
    """
    imports = (ast.Import, ast.ImportFrom)
    fallbacks = _import_fallbacks(tree)
    bound = {
        name
        for node in scope_nodes(tree.body)
        if not isinstance(node, imports)
        for name in _bound_names(node)
        if (id(node), name) not in fallbacks
    }
    declared = {name for node in ast.walk(tree) if isinstance(node, ast.Global) for name in node.names}
    return frozenset(bound | declared)


def _import_fallbacks(tree: ast.Module) -> set[tuple[int, str]]:
    """The bindings of an ``except`` handler that stand in for a name its ``try`` imports, by node and name.

    ``try: from .impl import X`` / ``except ImportError: X = None`` binds the
    import whenever it succeeds, so the fallback is no binding that replaces it.
    """
    tries = (ast.Try, ast.TryStar)
    imports = (ast.Import, ast.ImportFrom)
    found: set[tuple[int, str]] = set()
    for statement in scope_nodes(tree.body):
        if not isinstance(statement, tries):
            continue
        imported = {name for item in statement.body if isinstance(item, imports) for name in _bound_names(item)}
        for handler in statement.handlers:
            for node in scope_nodes(handler.body):
                found.update((id(node), name) for name in _bound_names(node) if name in imported)
    return found


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
        self.written: set[str] = set()
        # A hook opens the names; only `__init_subclass__` of them may also add a field (Ruling F8).
        self.hooked = bool(self.names & OPENING_HOOKS)
        self.opened = "__init_subclass__" in self.names
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
                    self.written.add(child.attr)
            elif isinstance(child, ast.Call):
                self.setattr_call(child, receivers)
        self.names |= self.written

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
            self.written.add(name)


class ClassMembers:
    """The ``member_names`` of each class one module declares, open on any doubt."""

    def __init__(self, tree: ast.Module, resolve: Resolve) -> None:
        self.tree = tree
        self.resolve = resolve
        self._written: set[str] | None = None
        self._bindings: Counter[str] | None = None
        self._rebound: set[ast.AST] | None = None
        self._owners: dict[int, ast.ClassDef] = {}
        self._unfollowed: UnfollowedWrites | None = None
        self.dynamic = runs_dynamic_code(tree)

    def rebound(self, node: ast.FunctionDef | ast.AsyncFunctionDef, superseded: set[ast.AST]) -> bool:
        """Whether a def may not hold its own signature at run time.

        One whose name its scope binds again (:func:`rebound_definitions`);
        every def when the module runs dynamic code (``globals()["g"] =
        partial(g, 1)``, ``exec``); and every method of a class that something
        may rewrite (:meth:`rewritable`). Asked as each def is visited, so a
        decorator's import has been read.
        """
        if self._rebound is None:
            functions = (ast.FunctionDef, ast.AsyncFunctionDef)
            self._rebound = rebound_definitions(self.tree, superseded)
            classes = [item for item in ast.walk(self.tree) if isinstance(item, ast.ClassDef)]
            self._owners = {
                id(item): klass for klass in classes for item in scope_nodes(klass.body) if isinstance(item, functions)
            }
        owner = self._owners.get(id(node))
        return self.dynamic or node in self._rebound or (owner is not None and self.rewritable(owner))

    def rewritable(self, node: ast.ClassDef) -> bool:
        """Whether something but the class body may replace the class's methods.

        A class decorator outside :data:`PLAIN_CLASS_DECORATORS`
        (``setattr(cls, name, partialmethod(fn, …))``), a metaclass outside
        :data:`PLAIN_METACLASSES`, or a write or call the module hands the
        class to (:meth:`written_names`).
        """
        resolved = [self._resolved(decorator) for decorator in node.decorator_list]
        if not all(name in PLAIN_CLASS_DECORATORS for name in resolved):
            return True
        metaclasses = [self._resolved(keyword.value) for keyword in node.keywords if keyword.arg == "metaclass"]
        return not all(name in PLAIN_METACLASSES for name in metaclasses) or node.name in self.written_names()

    def _resolved(self, expression: ast.expr) -> str | None:
        """What a decorator or a metaclass names, as its import resolves, without the reference's kind."""
        name = dotted(expression.func if isinstance(expression, ast.Call) else expression)
        resolved = self.resolve(name) if name else None
        if resolved is None:
            # The builtin `type`, unless the module binds that name itself.
            return "type" if name == "type" and "type" not in self.bindings() else None
        return resolved.split(":", 2)[-1]

    def attributes(self, node: ast.ClassDef, unseen_base: bool) -> dict[str, Any]:
        """``member_names`` for ``node``, with ``member_names_open`` when a name may come from elsewhere.

        ``unseen_base`` says a base may give names this scan cannot see
        (:meth:`unseen_base`). Type parameters (``class C[T]``) add none.
        """
        scan = _ClassScan(node)
        names = scan.names | {name for name in (mangled(node.name, each) for each in scan.names) if name}
        opened = scan.opened or scan.hooked or unseen_base or bool(node.keywords) or self.dynamic
        opened = opened or not all(self.closed_decorator(decorator) for decorator in node.decorator_list)
        opened = opened or node.name in self.written_names()
        if self._unfollowed is None:
            self._unfollowed = UnfollowedWrites(self.tree, self.bindings(), READ_ONLY_CALLS)
        unfollowed = self._unfollowed.reasons(node)
        return (
            {"member_names": sorted(name for name in names if not is_dunder(name))}
            | ({"member_names_open": True} if opened else {})
            | ({"methods_rewritable": True} if self.methods_rewritable(node) else {})
            | ({"unfollowed_writes": unfollowed} if unfollowed else {})
        )

    def opens_fields(self, node: ast.ClassDef) -> bool:
        """Whether a field may come from outside the class body, by the causes that open :meth:`attributes`.

        Those of the body and the module count, ``__init_subclass__``
        included. A ``__getattr__`` or ``__getattribute__`` answers names no
        field holds, so it leaves the fields as they are (Ruling F8), and the
        header (bases, keywords, decorators) is the field list's own to judge.
        """
        return _ClassScan(node).opened or self.dynamic or node.name in self.written_names()

    def methods_rewritable(self, node: ast.ClassDef) -> bool:
        """Whether the class's methods, or a subclass's, may not keep the signature their ``def`` writes (Ruling P21).

        So it is for a class something may rewrite (:meth:`rewritable`), in a
        module that runs dynamic code, or with an ``__init_subclass__``, which
        runs on every subclass and may replace its methods. A metaclass applies
        to subclasses too, so a reader carries the flag down the lineage.
        """
        hooked = "__init_subclass__" in {name for item in scope_nodes(node.body) for name in _bound_names(item)}
        return hooked or self.dynamic or self.rewritable(node)

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
        """Names the module writes an attribute on, hands on, or binds other than by ``class``.

        A class written (``C.x = v``, ``setattr(C, ...)``) or handed to a call
        that may write it (``install(C)``; :data:`READ_ONLY_CALLS` only read)
        may gain any member, and one rebound (``C = register(C)``, ``from x
        import C`` in a ``try``, a ``def C``) may not be the class its body
        declares. So may one whose name escapes into another holder (Ruling
        P23): bound to another name, put in a display, looped over or
        returned (:func:`_holders`), where a write through that holder
        (``Alias.x = 1``, ``for k in (C, D): k.x = 1``) reaches it.
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
                        written |= set(_escaping(spelled))
                written |= {name for held in _holders(node) for name in _escaping(held)}
            self._written = written
        return self._written


def _holders(node: ast.AST) -> list[ast.expr]:
    """The expressions whose value a node stores, loops over or hands back: where a class's name escapes."""
    if isinstance(
        node, (ast.Assign, ast.AnnAssign, ast.AugAssign, ast.NamedExpr, ast.Return, ast.Yield, ast.YieldFrom)
    ):
        return [node.value] if node.value is not None else []
    if isinstance(node, (ast.For, ast.AsyncFor, ast.comprehension)):
        return [node.iter]
    if isinstance(node, (ast.ListComp, ast.SetComp, ast.GeneratorExp)):
        return [node.elt]
    if isinstance(node, ast.DictComp):
        return [node.key, node.value]
    return [node.body] if isinstance(node, ast.Lambda) else []


def _escaping(value: ast.expr | None) -> Iterator[str]:
    """The names a held value is, or holds in a display: ``C``, ``(C, 1)``, ``{"c": C}``, ``A if x else B``."""
    pending: list[ast.expr | None] = [value]
    while pending:
        node = pending.pop()
        if isinstance(node, ast.Name):
            yield node.id
        elif isinstance(node, (ast.Tuple, ast.List, ast.Set)):
            pending.extend(node.elts)
        elif isinstance(node, ast.Dict):
            pending.extend([*node.keys, *node.values])
        elif isinstance(node, (ast.Starred, ast.NamedExpr)):
            pending.append(node.value)
        elif isinstance(node, ast.IfExp):
            pending.extend([node.body, node.orelse])
        elif isinstance(node, ast.BoolOp):
            pending.extend(node.values)


def merge_member_names(attributes: dict[str, Any], found: dict[str, Any]) -> None:
    """Fold a class's names into the attributes of a node that may already hold another declaration's.

    Two classes of one name (``if``/``else`` branches, a function-local class)
    share a node, so it offers the names of either, open if either is,
    rewritable if either is, and the unfollowed writes of both.
    """
    attributes["member_names"] = sorted({*attributes.get("member_names", []), *found["member_names"]})
    for flag in ("member_names_open", "methods_rewritable"):
        if attributes.get(flag) or found.get(flag):
            attributes[flag] = True
    unfollowed = {*attributes.get("unfollowed_writes", []), *found.get("unfollowed_writes", [])}
    if unfollowed:
        attributes["unfollowed_writes"] = sorted(unfollowed)

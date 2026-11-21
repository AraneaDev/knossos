"""One walk of a test file: its scopes, the names each binds, and the nodes its test facts are read from.

The test facts of a file (its doubles here, its cases in Task 4) read this
index rather than walking the tree again, so a test file is walked once for
them all (Ruling P11). Every node keeps its parent and the innermost scope it
sits in, and every statement its place in the block that holds it, which is
how a use of a name is matched to the binding that reaches it.
"""

from __future__ import annotations

import ast
from dataclasses import dataclass, field

Block = tuple[int, str]
"""A statement list: the node that holds it and the field it is held in."""

FUNCTIONS = (ast.FunctionDef, ast.AsyncFunctionDef)


@dataclass(eq=False)
class Binding:
    """One place a name is bound in a scope.

    ``kind`` is ``assign`` (``name = value``), ``with`` (``with value as
    name``), ``param``, ``import``, ``def``, ``class`` or ``other`` (any
    binding whose value is not traced: a loop target, an unpacking, a walrus,
    ``del``). ``block`` and ``index`` place the binding statement; a
    parameter sits before the first statement of its function's body.
    """

    name: str
    kind: str
    node: ast.AST
    value: ast.AST | None
    block: Block | None
    index: int


@dataclass(eq=False)
class Scope:
    """A module, class, function or lambda scope and the names bound in it."""

    node: ast.AST
    parent: Scope | None
    kind: str
    bindings: dict[str, list[Binding]] = field(default_factory=dict)
    # Names a `global` or `nonlocal` declaration hands to another scope, here
    # or in a scope nested inside: bound where this walk cannot follow.
    poisoned: set[str] = field(default_factory=set)
    declared_elsewhere: set[str] = field(default_factory=set)

    def bind(self, binding: Binding) -> None:
        self.bindings.setdefault(binding.name, []).append(binding)


def position(node: ast.AST) -> tuple[int, int]:
    return (getattr(node, "lineno", 0), getattr(node, "col_offset", 0))


class TestFileIndex:
    """Everything the test facts read from one walk of a test file's tree."""

    def __init__(self, tree: ast.Module) -> None:
        self.tree = tree
        self.parents: dict[int, ast.AST] = {}
        self.scopes: dict[int, Scope] = {}
        self.slots: dict[int, tuple[Block, int]] = {}
        self.calls: list[ast.Call] = []
        self.names: list[ast.Name] = []
        self.attributes: list[ast.Attribute] = []
        self.functions: list[ast.FunctionDef | ast.AsyncFunctionDef] = []
        self.classes: list[ast.ClassDef] = []
        # By the function or lambda node, the values its own `return`, `yield` and `yield from` hand back.
        self.returns: dict[int, list[ast.expr]] = {}
        self.module_scope = Scope(tree, None, "module")
        self._walk()
        for listed in (self.calls, self.names, self.attributes, self.functions, self.classes):
            listed.sort(key=position)

    def scope_of(self, node: ast.AST) -> Scope:
        return self.scopes.get(id(node), self.module_scope)

    def parent(self, node: ast.AST) -> ast.AST | None:
        return self.parents.get(id(node))

    def _walk(self) -> None:
        pending: list[tuple[ast.AST, ast.AST | None, Scope, ast.stmt | None]] = [
            (self.tree, None, self.module_scope, None)
        ]
        while pending:
            node, parent, scope, statement = pending.pop()
            if parent is not None:
                self.parents[id(node)] = parent
            self.scopes[id(node)] = scope
            if isinstance(node, ast.stmt):
                statement = node
            inner = self._record(node, parent, scope, statement)
            for name, value in ast.iter_fields(node):
                child_scope = inner if inner is not None and self._inside(node, name) else scope
                items = value if isinstance(value, list) else [value]
                for index, item in enumerate(items):
                    if not isinstance(item, ast.AST):
                        continue
                    if isinstance(value, list) and isinstance(item, ast.stmt):
                        self.slots[id(item)] = ((id(node), name), index)
                    pending.append((item, node, child_scope, statement))

    @staticmethod
    def _inside(node: ast.AST, name: str) -> bool:
        """Whether field ``name`` of a scope-making ``node`` is evaluated in the scope it makes."""
        if isinstance(node, ast.ClassDef):
            return name == "body"
        return name in {"body", "args"}

    def _record(self, node: ast.AST, parent: ast.AST | None, scope: Scope, statement: ast.stmt | None) -> Scope | None:
        """Note what ``node`` is and binds; return the scope it makes, if any."""
        if isinstance(node, ast.Call):
            self.calls.append(node)
        elif isinstance(node, ast.Attribute):
            self.attributes.append(node)
        elif isinstance(node, ast.Name):
            if isinstance(node.ctx, ast.Load):
                self.names.append(node)
            else:
                self._bind_name(node, parent, scope, statement)
        elif isinstance(node, (ast.Return, ast.Yield, ast.YieldFrom)) and node.value is not None:
            self.returns.setdefault(id(scope.node), []).append(node.value)
        elif isinstance(node, (ast.Import, ast.ImportFrom)):
            for alias in node.names:
                if alias.name != "*":
                    bound = alias.asname or alias.name.split(".")[0]
                    self._bind(scope, Binding(bound, "import", node, alias, None, 0), node)
        elif isinstance(node, (ast.Global, ast.Nonlocal)):
            self._declare_elsewhere(scope, node.names)
        elif isinstance(node, ast.arg):
            self._bind_param(node, scope)
        else:
            self._bind_other(node, scope, statement)
        return self._open(node, scope)

    def _bind_other(self, node: ast.AST, scope: Scope, statement: ast.stmt | None) -> None:
        """An exception's name and a pattern's captures: bound, to a value this walk does not trace."""
        name = None
        if isinstance(node, (ast.ExceptHandler, ast.MatchAs, ast.MatchStar)):
            name = node.name
        elif isinstance(node, ast.MatchMapping):
            name = node.rest
        if name:
            self._bind(scope, Binding(name, "other", node, None, None, 0), statement)

    def _open(self, node: ast.AST, scope: Scope) -> Scope | None:
        if isinstance(node, FUNCTIONS):
            self.functions.append(node)
            self._bind(scope, Binding(node.name, "def", node, node, None, 0), node)
            return Scope(node, scope, "function")
        if isinstance(node, ast.ClassDef):
            self.classes.append(node)
            self._bind(scope, Binding(node.name, "class", node, node, None, 0), node)
            return Scope(node, scope, "class")
        if isinstance(node, ast.Lambda):
            return Scope(node, scope, "lambda")
        return None

    def _bind(self, scope: Scope, binding: Binding, statement: ast.stmt | None) -> None:
        slot = None if statement is None else self.slots.get(id(statement))
        if slot is not None:
            binding.block, binding.index = slot
        scope.bind(binding)

    def _bind_name(self, node: ast.Name, parent: ast.AST | None, scope: Scope, statement: ast.stmt | None) -> None:
        kind, value = "other", None
        if isinstance(node.ctx, ast.Store):
            if isinstance(parent, ast.Assign) and any(target is node for target in parent.targets):
                kind, value = "assign", parent.value
            elif isinstance(parent, ast.AnnAssign) and parent.target is node and parent.value is not None:
                kind, value = "assign", parent.value
            elif isinstance(parent, ast.withitem) and parent.optional_vars is node:
                kind, value = "with", parent.context_expr
        self._bind(scope, Binding(node.id, kind, statement or node, value, None, 0), statement)

    def _bind_param(self, node: ast.arg, scope: Scope) -> None:
        owner = scope.node
        block: Block | None = (id(owner), "body") if isinstance(owner, FUNCTIONS) else None
        scope.bind(Binding(node.arg, "param", node, None, block, -1))

    @staticmethod
    def _declare_elsewhere(scope: Scope, names: list[str]) -> None:
        """A `global` or `nonlocal` name is bound where this walk does not trace it: poison it all the way out."""
        scope.declared_elsewhere.update(names)
        current: Scope | None = scope
        while current is not None:
            current.poisoned.update(names)
            current = current.parent

    def binding_of(self, name: ast.Name) -> Binding | None:
        """The binding a load of ``name`` reads, or None when it cannot be told for certain.

        A name its own scope binds is read from the latest of those bindings
        that comes before the use in the same statement list; bindings spread
        over different blocks (two branches of an `if`) give None, as does a
        use the bindings do not precede. A name read from an enclosing scope
        must be bound there exactly once, since the enclosing code may run on
        after the nested one is made; imports that all agree count as once.
        """
        scope = self.scope_of(name)
        owner = self.owner_of(name.id, scope)
        if owner is None:
            return None
        bindings = owner.bindings.get(name.id, [])
        if owner is not scope:
            return self._sole(bindings)
        return self._latest(bindings, name)

    def owner_of(self, name: str, scope: Scope) -> Scope | None:
        current: Scope | None = scope
        while current is not None:
            if name in current.poisoned or name in current.declared_elsewhere:
                return None
            if name in current.bindings:
                return current
            current = current.parent
            # A class body's names are not seen from the functions inside it.
            while current is not None and current.kind == "class" and current is not scope:
                current = current.parent
        return None

    @staticmethod
    def _sole(bindings: list[Binding]) -> Binding | None:
        if len(bindings) == 1:
            return bindings[0]
        if bindings and all(binding.kind == "import" for binding in bindings):
            return bindings[0]
        return None

    def _latest(self, bindings: list[Binding], use: ast.AST) -> Binding | None:
        blocks = {binding.block for binding in bindings}
        if len(blocks) != 1:
            return self._sole(bindings) if all(binding.kind == "import" for binding in bindings) else None
        block = blocks.pop()
        if block is None:
            # A lambda's parameters: one binding, and every use inside it reads it.
            return bindings[0] if len(bindings) == 1 else None
        index = self._index_in(block, use)
        if index is None:
            return None
        best: Binding | None = None
        for binding in bindings:
            reaches = binding.index < index or (
                binding.index == index and binding.kind == "with" and self._in_body(binding.node, use)
            )
            if reaches and (best is None or binding.index >= best.index):
                best = binding
        return best

    def _index_in(self, block: Block, node: ast.AST) -> int | None:
        current: ast.AST | None = node
        while current is not None:
            slot = self.slots.get(id(current))
            if slot is not None and slot[0] == block:
                return slot[1]
            current = self.parents.get(id(current))
        return None

    def _in_body(self, statement: ast.AST, node: ast.AST) -> bool:
        """Whether ``node`` sits in the body of the `with` statement ``statement``, past its items."""
        current: ast.AST | None = node
        while current is not None:
            parent = self.parents.get(id(current))
            if parent is statement:
                return isinstance(statement, (ast.With, ast.AsyncWith)) and any(
                    item is current for item in statement.body
                )
            current = parent
        return False

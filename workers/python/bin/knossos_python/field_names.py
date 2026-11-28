"""Whether a name a field's annotation or value uses may hold an alias the field list cannot see.

``user_id: AliasedId`` with ``AliasedId = Annotated[int, Field(alias=…)]``, or
``user_id: int = ID_FIELD`` with ``ID_FIELD = Field(alias=…)``, renames the
key through a name. So a name is trusted only when it is a type: a class,
a ``TypeVar`` or ``NewType`` of this file, a class of another project module,
a builtin, or a name imported from outside the project (``typing``,
``datetime``). Any other binding of this file (an assignment, a ``type``
statement, a function), any other symbol of a project module, and a name
that resolves to nothing may hold one.
"""

import ast
import builtins
from collections.abc import Iterator

from .ast_helpers import dotted
from .class_members import Resolve, scope_bindings, scope_nodes
from .module_index import ProjectModuleIndex

BUILTINS = frozenset(dir(builtins))

TYPE_FACTORIES = frozenset(
    f"{module}.{name}"
    for module in ("typing", "typing_extensions")
    for name in ("TypeVar", "ParamSpec", "TypeVarTuple", "NewType")
)
"""Calls whose result, bound to a name, is a type that carries no field metadata."""

LITERALS = frozenset({"typing.Literal", "typing_extensions.Literal"})

ANNOTATED = frozenset({"typing.Annotated", "typing_extensions.Annotated"})

UNREADABLE = "<unreadable>"
"""What :func:`names` yields for a string annotation that does not parse."""


class FieldNames:
    """The names one module binds, read once, and the judgement of each name a field uses."""

    def __init__(self, tree: ast.Module, module: str, resolve: Resolve, index: ProjectModuleIndex) -> None:
        self.tree = tree
        self.module = module
        self.resolve = resolve
        self.index = index
        self._read: tuple[set[str], set[str]] | None = None

    def bindings(self) -> tuple[set[str], set[str]]:
        """The module's names that are types, and those that may hold anything else.

        Read on first use, once the module's imports resolve: a ``TypeVar`` or
        ``NewType`` is told apart by what its call names.
        """
        if self._read is None:
            statements = list(scope_nodes(self.tree.body))
            bound, _ = scope_bindings(statements)
            imports = (ast.Import, ast.ImportFrom)
            imported, _ = scope_bindings(item for item in statements if isinstance(item, imports))
            classes = {item.name for item in statements if isinstance(item, ast.ClassDef)}
            aliases = getattr(ast, "TypeAlias", ())  # `type X = …`, from 3.12
            targets: list[ast.expr] = []
            for item in statements:
                if isinstance(item, ast.Assign):
                    targets += item.targets
                elif isinstance(item, (ast.AnnAssign, ast.AugAssign)):
                    targets.append(item.target)
                elif aliases and isinstance(item, aliases):
                    targets.append(item.name)
            others = {each.id for target in targets for each in ast.walk(target) if isinstance(each, ast.Name)}
            others |= {item.name for item in statements if isinstance(item, (ast.FunctionDef, ast.AsyncFunctionDef))}
            factories = {
                target.id
                for item in statements
                if isinstance(item, ast.Assign) and self.qualified(item.value) in TYPE_FACTORIES
                for target in item.targets
                if isinstance(target, ast.Name)
            }
            # A class also bound otherwise (`C = wrap(C)`) may hold anything.
            suspect = (others - factories) | (bound - imported - classes - factories - others)
            self._read = ((classes - suspect) | factories, suspect)
        return self._read

    def qualified(self, expression: ast.expr | None) -> str | None:
        """What a name, or the callee of a call, names as its import resolves, without the reference's kind."""
        if isinstance(expression, ast.Call):
            expression = expression.func
        name = dotted(expression) if expression is not None else None
        target = self.resolve(name) if name else None
        return target.split(":", 2)[-1] if target else None

    def opens(self, annotation: ast.expr, value: ast.expr | None, params: set[str]) -> bool:
        """Whether a name in the field's annotation, or a value that is a bare name, may hold an alias.

        ``params`` are the class's own type parameters (``class C[T]``).
        """
        spelled = list(self.names(annotation, True))
        if isinstance(value, (ast.Name, ast.Attribute)):
            spelled.append(dotted(value) or UNREADABLE)
        return any(name not in params and self.may_alias(name) for name in spelled)

    def names(self, node: ast.expr, typed: bool) -> Iterator[str]:
        """The dotted names an annotation uses; ``typed`` says a string there is a forward reference.

        A ``Literal``'s strings are values, and so is an ``Annotated``
        metadata string; only the callee of a call is a name it uses.
        """
        text = node.value if isinstance(node, ast.Constant) and isinstance(node.value, str) else None
        if text is not None:
            if typed:
                try:
                    yield from self.names(ast.parse(text, mode="eval").body, True)
                except SyntaxError:
                    yield UNREADABLE
            return
        name = dotted(node) if isinstance(node, (ast.Name, ast.Attribute)) else None
        if name is not None:
            yield name
        elif isinstance(node, ast.Subscript):
            yield from self.names(node.value, typed)
            head = self.qualified(node.value)
            if head in LITERALS:
                return
            parts = node.slice.elts if isinstance(node.slice, ast.Tuple) else [node.slice]
            for position, part in enumerate(parts):
                yield from self.names(part, typed and (head not in ANNOTATED or position == 0))
        elif isinstance(node, ast.Call):
            yield from self.names(node.func, False)
        elif not isinstance(node, ast.Constant):
            for child in ast.iter_child_nodes(node):
                if isinstance(child, ast.expr):
                    yield from self.names(child, typed)

    def may_alias(self, name: str) -> bool:
        """Whether one dotted name may hold field metadata rather than name a type."""
        if name == UNREADABLE:
            return True
        types, suspect = self.bindings()
        head = name.split(".", 1)[0]
        if head in suspect:
            return True
        target = self.resolve(name)
        if target is None:
            # A builtin, or an attribute of a class of this file (`Color.RED`, `Outer.Inner`).
            return not ((head in BUILTINS and "." not in name) or head in types)
        _, kind, qualified = target.split(":", 2)
        if qualified.startswith(f"{self.module}."):
            return kind != "class" or qualified.removeprefix(f"{self.module}.").split(".")[0] not in types
        module, _, symbol = qualified.rpartition(".")
        if not module:
            return True  # A module, or a name no module holds, is no type.
        if self.index.layout.module_file(module) is None:
            return False  # Outside the project: `typing`, `datetime`, `pydantic`.
        return kind != "class" or self.index.module_declarations(module).get(symbol) != target

"""The fields a dataclass, pydantic model or TypedDict declares, and the cases of an Enum, open on any doubt.

A list is a claim about the keys a deserialiser reads, and a list that looks
whole but is not (a key read by another name, a field from a base this file
cannot see) gives false drift later. So a class sends a list only when this
file shows what makes its annotations fields: the ``dataclass`` decorator, or
a base that is ``BaseModel``, ``TypedDict`` or a class of this file that is
one. A plain class, and a subclass of a class in another file (it may be a
dataclass, whose subclass's annotations are no fields), sends none. The list
holds the class's own fields; the core composes inherited ones along the
``extends`` edges, as it composes ``member_names``.

``fields_open`` is set where a wire name may differ or a field may come from
elsewhere: an alias, an alias generator, a ``model_config`` or ``class
Config``, a hook that may rewrite the input or the constructor, a decorator
other than ``dataclass``, a class keyword, a base this scan cannot read, and
:meth:`ClassMembers.opens_fields`'s causes (``__getattr__`` leaves it closed:
the fixture check reads it as a method of its own). An ``Enum`` sends
``cases`` instead, never ``fields``, with each case's literal value when every
case has one.
"""

import ast
from collections import Counter
from typing import Any

from .ast_helpers import dotted
from .class_members import ClassMembers, Resolve, is_dunder
from .field_names import FieldNames
from .module_index import ProjectModuleIndex

MAX_ENTRIES = 64
"""Fields or cases listed at most; a longer list is cut and marked truncated."""

DATACLASS = "dataclasses.dataclass"

ROOTS = {
    "pydantic.BaseModel": "pydantic",
    "pydantic.main.BaseModel": "pydantic",
    "typing.TypedDict": "typeddict",
    "typing_extensions.TypedDict": "typeddict",
    "enum.Enum": "enum",
    "enum.IntEnum": "enum",
    "enum.StrEnum": "enum",
    "enum.Flag": "enum",
    "enum.IntFlag": "enum",
}
"""The bases outside the project that make a class's annotations fields, or its assignments cases."""

GENERIC_BASES = frozenset({"typing.Generic", "typing_extensions.Generic"})

ENUM_DECORATORS = frozenset({"enum.unique", "enum.verify"})

VALUE_HOOKS = frozenset({"_missing_", "__new__", "__init__", "_generate_next_value_"})
"""Methods that may give an Enum member a value no case is written with, or read one by another."""

ENUM_MIXINS = frozenset({"str", "int"})
"""The builtin data types an Enum may mix in, unchanged in how its cases are named."""

ALIAS_KEYWORDS = frozenset({"alias", "validation_alias", "serialization_alias"})

ALIAS_CALLS = frozenset({"AliasChoices", "AliasPath"})

FIELD_CALLS = frozenset({"pydantic.Field", "pydantic.fields.Field", "dataclasses.field"})

REWRITING_HOOKS = frozenset({"model_validator", "root_validator", "computed_field", "model_serializer"})
"""Pydantic method decorators that may read the input by other keys, or add keys a dump writes."""

CONSTRUCTORS = frozenset({"__init__", "__new__", "__get_pydantic_core_schema__", "__get_validators__"})
"""Methods that take over how the class is built from its input."""

WRAPPERS = {
    **{f"{module}.{name}": name for module in ("typing", "typing_extensions") for name in ("Annotated", "ClassVar")},
    **{f"{module}.{name}": name for module in ("typing", "typing_extensions") for name in ("NotRequired", "ReadOnly")},
    **{f"{module}.Required": "Required" for module in ("typing", "typing_extensions")},
    "dataclasses.InitVar": "InitVar",
    "dataclasses.KW_ONLY": "KW_ONLY",
}
"""The annotation forms that say what a field is rather than what it holds, by their short name."""


class FieldFacts:
    """The ``fields`` or ``cases`` of each class one module declares."""

    def __init__(
        self, tree: ast.Module, module: str, resolve: Resolve, members: ClassMembers, index: ProjectModuleIndex
    ) -> None:
        self.resolve = resolve
        self.members = members
        self.names = FieldNames(tree, module, resolve, index)
        declared = [item for item in ast.walk(tree) if isinstance(item, ast.ClassDef)]
        counts = Counter(f"{module}.{item.name}" for item in declared)
        # A class this file declares once, by the name its references resolve to.
        self.classes = {f"{module}.{item.name}": item for item in declared if counts[f"{module}.{item.name}"] == 1}

    def qualified(self, expression: ast.expr | None) -> str | None:
        """What a name, or the callee of a call, names as its import resolves, without the reference's kind."""
        if isinstance(expression, ast.Call):
            expression = expression.func
        name = dotted(expression) if expression is not None else None
        target = self.resolve(name) if name else None
        return target.split(":", 2)[-1] if target else None

    def of(self, node: ast.ClassDef) -> dict[str, Any]:
        """The class's ``fields`` with ``fields_open``, or an Enum's ``cases``, or nothing."""
        kind = self.kind(node, frozenset())
        if kind == "enum":
            return self.cases(node)
        if kind not in ("pydantic", "dataclass", "typeddict"):
            return {}
        fields, opened = self.fields(node, kind)
        opened = opened or self.header_opens(node, kind) or self.members.opens_fields(node)
        return _listed("fields", fields) | ({"fields_open": True} if opened else {})

    def kind(self, node: ast.ClassDef, seen: frozenset[str]) -> str | None:
        """What makes the class's body fields: ``dataclass``, ``pydantic``, ``typeddict``, ``enum``, ``mixed`` or None.

        A class of this file that is a base passes its kind on, except
        ``dataclass``: a dataclass's subclass is no dataclass unless decorated.
        """
        kinds = {"dataclass"} if any(self.qualified(item) == DATACLASS for item in node.decorator_list) else set()
        for base in node.bases:
            named = self.qualified(base)
            if named in ROOTS:
                kinds.add(ROOTS[named])
            elif named in self.classes and named not in seen:
                inherited = self.kind(self.classes[named], seen | {named})
                kinds |= {inherited} if inherited not in (None, "dataclass") else set()
        if len(kinds) > 1:
            return "mixed"
        return next(iter(kinds), None)

    def header_opens(self, node: ast.ClassDef, kind: str) -> bool:
        """Whether the class's decorators, keywords or bases may add a field or rename a key."""
        for decorator in node.decorator_list:
            if self.qualified(decorator) != DATACLASS:
                return True
            if isinstance(decorator, ast.Call) and any(
                _not_true(each) for each in decorator.keywords if each.arg == "init"
            ):
                return True
        allowed = {"total"} if kind == "typeddict" else set()
        if any(keyword.arg not in allowed or not _is_bool(keyword.value) for keyword in node.keywords):
            return True
        return any(self.base_opens(base) for base in node.bases)

    def base_opens(self, base: ast.expr) -> bool:
        """Whether a base may give fields the scan cannot compose: unread, subscripted or outside the project.

        A root, ``object``, a subscripted ``Generic`` and a class the scan may
        hold (``py:class:``, whose lineage the core walks) do not.
        """
        if isinstance(base, ast.Subscript):
            return self.qualified(base.value) not in GENERIC_BASES
        name = dotted(base)
        target = self.resolve(name) if name else None
        if target is None:
            return name != "object" or self.members.bindings()["object"] > 0
        return target.startswith("py:external_symbol:") and target.split(":", 2)[-1] not in ROOTS

    def fields(self, node: ast.ClassDef, kind: str) -> tuple[list[dict[str, Any]], bool]:
        """The class body's own fields, sorted by name, and whether one of them opens the list."""
        found: dict[str, dict[str, Any]] = {}
        opened = False
        total = not any(keyword.arg == "total" and _not_true(keyword) for keyword in node.keywords)
        params = {param.name for param in getattr(node, "type_params", [])}
        for item in node.body:
            opened = opened or self.body_opens(item, kind)
            if not isinstance(item, ast.AnnAssign) or not isinstance(item.target, ast.Name):
                continue
            name = item.target.id
            annotation = _annotation(item.annotation)
            if annotation is None:
                opened = True
                continue
            wrappers, inner, metadata = self.unwrap(annotation)
            if wrappers & {"ClassVar", "KW_ONLY"} or (kind == "pydantic" and name.startswith("_")):
                continue
            opened = opened or "InitVar" in wrappers or self.renames([annotation, item.value])
            opened = opened or self.names.opens(item.annotation, item.value, params)
            required = self.required(kind, item.value, wrappers, metadata, total)
            found[name] = {"name": name, "type": ast.unparse(inner), "required": required, "line": item.lineno}
        return [found[name] for name in sorted(found)], opened

    def body_opens(self, item: ast.stmt, kind: str) -> bool:
        """Whether one class-body statement may hide a field or rewrite how the input is read."""
        if isinstance(item, (ast.If, ast.Try, ast.TryStar)):
            return True
        if isinstance(item, ast.ClassDef):
            return kind == "pydantic" and item.name == "Config"
        if isinstance(item, (ast.Assign, ast.AnnAssign)):
            targets = item.targets if isinstance(item, ast.Assign) else [item.target]
            return kind == "pydantic" and any(dotted(target) == "model_config" for target in targets)
        if isinstance(item, (ast.FunctionDef, ast.AsyncFunctionDef)):
            hooks = {(self.qualified(each) or "").rsplit(".", 1)[-1] for each in item.decorator_list}
            return item.name in CONSTRUCTORS or bool(hooks & REWRITING_HOOKS)
        return False

    def unwrap(self, annotation: ast.expr) -> tuple[set[str], ast.expr, list[ast.expr]]:
        """The wrapper forms around an annotation's type, the type inside them, and any ``Annotated`` metadata."""
        wrappers: set[str] = set()
        metadata: list[ast.expr] = []
        current = annotation
        while True:
            head = current.value if isinstance(current, ast.Subscript) else current
            wrapper = WRAPPERS.get(self.qualified(head) or "")
            if wrapper is None or not isinstance(current, ast.Subscript):
                return wrappers | ({wrapper} if wrapper else set()), current, metadata
            wrappers.add(wrapper)
            current = current.slice
            if wrapper == "Annotated" and isinstance(current, ast.Tuple) and current.elts:
                metadata += current.elts[1:]
                current = current.elts[0]

    def renames(self, expressions: list[ast.expr | None]) -> bool:
        """Whether a field's annotation or value names another key for it, or hides what its field call says."""
        for expression in expressions:
            for call in (each for each in ast.walk(expression) if isinstance(each, ast.Call)) if expression else ():
                keywords = {keyword.arg for keyword in call.keywords}
                if keywords & ALIAS_KEYWORDS or (dotted(call.func) or "").rsplit(".", 1)[-1] in ALIAS_CALLS:
                    return True
                if self.qualified(call) in FIELD_CALLS and keywords & {None, "init", "metadata"}:
                    return True
        return False

    def required(
        self, kind: str, value: ast.expr | None, wrappers: set[str], metadata: list[ast.expr], total: bool
    ) -> bool:
        """Whether a deserialiser needs the field's key.

        A TypedDict key follows ``Required``, ``NotRequired`` and the class's
        totality. Otherwise a field without a value is required, unless a
        pydantic ``Annotated`` ``Field`` gives it a default; a field call is
        required without ``default`` or ``default_factory``, a pydantic
        ``= ...`` is required, and any other value is a default.
        """
        if kind == "typeddict":
            return "Required" in wrappers or ("NotRequired" not in wrappers and total)
        if value is None:
            calls = [each for each in metadata if kind == "pydantic" and self.qualified(each) in FIELD_CALLS]
            return not any(_defaults(call) for call in calls if isinstance(call, ast.Call))
        if kind == "pydantic" and _is_ellipsis(value):
            return True
        if isinstance(value, ast.Call) and self.qualified(value) in FIELD_CALLS:
            return not _defaults(value)
        return False

    def cases(self, node: ast.ClassDef) -> dict[str, Any]:
        """An Enum's ``cases``, sorted by name, each with ``value`` when every case is a string or integer literal.

        An Enum whose members this body may not show (a decorator, a keyword,
        a base it cannot read, an ``_ignore_``, a tuple target, a statement
        that may bind names, :meth:`ClassMembers.opens_fields`'s causes) sends
        none, and so does one over a class of this file that does. A
        :data:`VALUE_HOOKS` method on the Enum or any class of its lineage,
        mixins included, may give a value no case is written with, so the
        cases then carry none.
        """
        lineage = self.enum_lineage(node)
        if lineage is None:
            return {}
        found: dict[str, str | None] = {}
        defined = {
            item.name
            for each in lineage
            for item in each.body
            if isinstance(item, (ast.FunctionDef, ast.AsyncFunctionDef))
        }
        for item in node.body:
            if isinstance(item, (ast.FunctionDef, ast.AsyncFunctionDef, ast.ClassDef)):
                continue
            if isinstance(item, ast.Pass) or (isinstance(item, ast.Expr) and _string_value(item.value) is not None):
                continue
            if isinstance(item, ast.AnnAssign) and item.value is None:
                continue
            if not isinstance(item, (ast.Assign, ast.AnnAssign)):
                return {}
            targets = item.targets if isinstance(item, ast.Assign) else [item.target]
            if len(targets) != 1 or not isinstance(targets[0], ast.Name) or targets[0].id == "_ignore_":
                return {}
            name = targets[0].id
            if name.startswith("__") and not is_dunder(name):
                return {}  # A private name is a member before 3.11 and not after.
            if not is_dunder(name) and not _is_sunder(name):
                found[name] = _literal(item.value)
        valued = None not in found.values() and not defined & VALUE_HOOKS
        listed = [{"name": name, **({"value": found[name]} if valued else {})} for name in sorted(found)]
        return _listed("cases", listed)

    def enum_lineage(self, node: ast.ClassDef) -> list[ast.ClassDef] | None:
        """The Enum and every class of this file it derives from, mixins included; None when one may hide members.

        Each class's decorators, keywords and :meth:`ClassMembers.opens_fields`
        causes count, and every base must be an Enum root, a ``str`` or
        ``int`` mixin, or a class of this file.
        """
        lineage: list[ast.ClassDef] = []
        pending = [node]
        while pending:
            current = pending.pop()
            if current in lineage:
                continue
            lineage.append(current)
            decorated = any(self.qualified(item) not in ENUM_DECORATORS for item in current.decorator_list)
            if current.keywords or decorated or self.members.opens_fields(current):
                return None
            for base in current.bases:
                named = self.qualified(base)
                mixin = isinstance(base, ast.Name) and base.id in ENUM_MIXINS and self.members.bindings()[base.id] == 0
                if named in self.classes:
                    pending.append(self.classes[named])
                elif not mixin and ROOTS.get(named or "") != "enum":
                    return None
        return lineage


def merge_field_facts(attributes: dict[str, Any], found: dict[str, Any]) -> None:
    """Fold a class's lists into the attributes of a node that may already hold another declaration's.

    Two classes of one name share a node, so a key may be either's: the
    fields are open when either sends some, and no cases are sent. A node is
    already held when it carries ``member_names``, which every declaration
    merges after this.
    """
    if "member_names" not in attributes:
        attributes.update(found)
        return
    for key in ("cases", "cases_truncated"):
        attributes.pop(key, None)
    if "fields" in attributes or "fields" in found:
        attributes.setdefault("fields", found.get("fields", []))
        attributes["fields_open"] = True


def _listed(key: str, entries: list[dict[str, Any]]) -> dict[str, Any]:
    """A list under its key, cut at :data:`MAX_ENTRIES` and marked when it was."""
    return {key: entries[:MAX_ENTRIES]} | ({f"{key}_truncated": True} if len(entries) > MAX_ENTRIES else {})


def _annotation(annotation: ast.expr) -> ast.expr | None:
    """An annotation as an expression: a string one parsed (never run), None when it does not parse."""
    text = _string_value(annotation)
    if text is None:
        return annotation
    try:
        return ast.parse(text, mode="eval").body
    except SyntaxError:
        return None


def _string_value(node: ast.expr | None) -> str | None:
    return node.value if isinstance(node, ast.Constant) and isinstance(node.value, str) else None


def _is_sunder(name: str) -> bool:
    """Whether ``name`` is spelled ``_x_``, a name ``enum`` keeps for itself."""
    return len(name) > 2 and name[0] == name[-1] == "_" and name[1] != "_" and name[-2] != "_"


def _is_bool(node: ast.expr) -> bool:
    return isinstance(node, ast.Constant) and isinstance(node.value, bool)


def _not_true(keyword: ast.keyword) -> bool:
    return not (isinstance(keyword.value, ast.Constant) and keyword.value.value is True)


def _is_ellipsis(node: ast.expr | None) -> bool:
    return isinstance(node, ast.Constant) and node.value is Ellipsis


def _defaults(call: ast.Call) -> bool:
    """Whether a field call gives a default: ``default``, ``default_factory``, or a first argument but ``...``."""
    if any(keyword.arg in ("default", "default_factory") for keyword in call.keywords):
        return True
    return bool(call.args) and not _is_ellipsis(call.args[0])


def _literal(value: ast.expr | None) -> str | None:
    """A case's value as text: a string, or a signed integer; None for anything else."""
    text = _string_value(value)
    if text is not None:
        return text
    sign = ""
    if isinstance(value, ast.UnaryOp) and isinstance(value.op, ast.USub):
        sign, value = "-", value.operand
    if isinstance(value, ast.Constant) and type(value.value) is int:
        return f"{sign}{value.value}"
    return None

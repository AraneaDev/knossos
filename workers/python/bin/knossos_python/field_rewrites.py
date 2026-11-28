"""Which fields a pydantic validator may hand another kind than their type, and which pydantic a file was written for.

A validator that runs before the type check (``field_validator`` with mode
``before``, ``wrap`` or ``plain``, pydantic v1 ``validator(pre=True)``, or a
``BeforeValidator``, ``PlainValidator`` or ``WrapValidator`` in a field's
``Annotated`` metadata) lets the input hold a kind the annotation does not
say: a ``list[str]`` field may take ``"a,b"``. Such a field keeps its name and
requiredness but sends no ``type``, so its kind is never judged.
"""

import ast
from collections.abc import Callable

Qualify = Callable[[ast.expr | None], str | None]
"""What a name, or a call's callee, names as its import resolves."""

ALL = "*"
"""A validator's field name that stands for every field."""

ANNOTATED_VALIDATORS = frozenset({"BeforeValidator", "PlainValidator", "WrapValidator"})

FIELD_VALIDATORS = frozenset({"field_validator", "validator"})

AFTER_MODES = frozenset({"after"})

V2_ONLY = frozenset(
    {
        "AfterValidator",
        "AliasChoices",
        "AliasPath",
        "BeforeValidator",
        "ConfigDict",
        "PlainSerializer",
        "PlainValidator",
        "RootModel",
        "TypeAdapter",
        "WrapSerializer",
        "WrapValidator",
        "computed_field",
        "field_serializer",
        "field_validator",
        "model_serializer",
        "model_validator",
    }
)
"""Names pydantic v1 does not have: a file that imports one runs under pydantic v2."""

NULLABLE = frozenset({"typing.Optional", "typing_extensions.Optional"})

UNIONS = frozenset({"typing.Union", "typing_extensions.Union"})

ANY = frozenset({"typing.Any", "typing_extensions.Any"})


def _short(qualify: Qualify, expression: ast.expr | None) -> str:
    """The last part of what an expression names, by its import or, when it resolves to nothing, as spelled."""
    named = qualify(expression)
    if named is None:
        callee = expression.func if isinstance(expression, ast.Call) else expression
        named = ast.unparse(callee) if callee is not None else ""
    return named.rsplit(".", 1)[-1]


def validated_fields(body: list[ast.stmt], qualify: Qualify) -> set[str] | None:
    """The fields a class body's decorated validators may hand another kind, ``*`` for all; None when unsure which."""
    covered: set[str] = set()
    for item in body:
        if not isinstance(item, (ast.FunctionDef, ast.AsyncFunctionDef)):
            continue
        for decorator in item.decorator_list:
            if _short(qualify, decorator) not in FIELD_VALIDATORS:
                continue
            if not isinstance(decorator, ast.Call):
                return None
            if not _rewrites(decorator):
                continue
            names = [_string(argument) for argument in decorator.args]
            if None in names or not names:
                return None
            covered |= {name for name in names if name is not None}
    return covered


def _rewrites(call: ast.Call) -> bool:
    """Whether a field validator call runs before the type check, or may: any mode but ``after``, or ``pre``."""
    for keyword in call.keywords:
        if keyword.arg is None:
            return True
        if keyword.arg == "mode" and _string(keyword.value) not in AFTER_MODES:
            return True
        if keyword.arg == "pre" and not (isinstance(keyword.value, ast.Constant) and keyword.value.value is False):
            return True
    return False


def annotation_rewrites(annotation: ast.expr, qualify: Qualify) -> bool:
    """Whether an annotation holds, at any depth, a validator that runs before the type check."""
    return any(
        _short(qualify, each) in ANNOTATED_VALIDATORS
        for each in ast.walk(annotation)
        if isinstance(each, (ast.Name, ast.Attribute))
    )


def pydantic_v2(tree: ast.Module, qualify: Qualify) -> bool:
    """Whether the file imports or names a part of pydantic that only v2 has, outside ``pydantic.v1``."""
    for node in ast.walk(tree):
        if isinstance(node, ast.ImportFrom) and _v2_module(node.module or ""):
            if any(alias.name in V2_ONLY for alias in node.names):
                return True
        elif isinstance(node, ast.Attribute):
            named = qualify(node) or ast.unparse(node)
            if _v2_module(named.rsplit(".", 1)[0]) and node.attr in V2_ONLY:
                return True
    return False


def _v2_module(module: str) -> bool:
    return (module == "pydantic" or module.startswith("pydantic.")) and not (
        module == "pydantic.v1" or module.startswith("pydantic.v1.")
    )


def may_be_none(annotation: ast.expr, qualify: Qualify) -> bool:
    """Whether a type admits ``None`` or is ``Any``, which pydantic v1 fills with ``None`` when the key is absent."""
    if isinstance(annotation, ast.Constant) and annotation.value is None:
        return True
    if isinstance(annotation, ast.BinOp) and isinstance(annotation.op, ast.BitOr):
        return may_be_none(annotation.left, qualify) or may_be_none(annotation.right, qualify)
    if isinstance(annotation, ast.Subscript):
        head = qualify(annotation.value)
        if head in NULLABLE:
            return True
        if head in UNIONS:
            parts = annotation.slice.elts if isinstance(annotation.slice, ast.Tuple) else [annotation.slice]
            return any(may_be_none(part, qualify) for part in parts)
        return False
    return qualify(annotation) in ANY


def _string(node: ast.expr | None) -> str | None:
    return node.value if isinstance(node, ast.Constant) and isinstance(node.value, str) else None

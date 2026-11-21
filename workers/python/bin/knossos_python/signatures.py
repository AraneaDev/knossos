"""The signature of a function or method as its source writes it."""

from __future__ import annotations

import ast
import re
from collections.abc import Callable
from typing import Any

from .ast_helpers import dotted

MAX_PARAMS = 12
"""Parameters a signature lists at most; the rest are cut and it says so."""

MAX_TYPE_LENGTH = 80
"""Characters of an annotation's text a signature keeps at most."""

PLAIN_DECORATORS = frozenset(
    {
        "abc.abstractmethod",
        "functools.cache",
        "functools.lru_cache",
        "typing.final",
        "typing.override",
        "typing_extensions.final",
        "typing_extensions.override",
    }
)
"""Decorators, by the name their import resolves to, that hand the function on with its signature unchanged."""

BUILTIN_DECORATORS = frozenset({"classmethod", "staticmethod"})
"""The builtins of the same kind, when nothing in the module shadows them."""

# Control characters, C1 controls and the Unicode line and paragraph
# separators: a signature is shown to an agent as one line of text.
_UNPRINTABLE = re.compile("[\x00-\x1f\x7f-\x9f\u2028\u2029]")


class _Text:
    """Annotation text on one line and within the length kept, remembering whether any was cut."""

    def __init__(self) -> None:
        self.cut = False

    def of(self, annotation: ast.expr) -> str:
        text = " ".join(_UNPRINTABLE.sub(" ", ast.unparse(annotation)).split())
        if len(text) <= MAX_TYPE_LENGTH:
            return text
        self.cut = True
        return text[: MAX_TYPE_LENGTH - 1] + "…"


def signature_of(node: ast.FunctionDef | ast.AsyncFunctionDef, receiver: bool) -> dict[str, Any]:
    """The `signature` attribute of a function or method.

    Types are the annotations written: an unannotated parameter has no `type`
    and nothing is inferred. `optional` marks a parameter with a default,
    `variadic` the `*args` and `**kwargs` parameters, which keep their stars
    in the name so the two stay apart, and `kind` a parameter only a position
    (before `/`) or only a keyword (after `*` or `*args`) can pass. With `receiver`, the first positional
    parameter (`self`, `cls`) is what the call is made on, not something a
    caller passes, and is left out.
    """
    text = _Text()
    arguments = node.args
    positional = [*arguments.posonlyargs, *arguments.args]
    required = len(positional) - len(arguments.defaults)
    only = len(arguments.posonlyargs)
    entries: list[tuple[str, ast.arg, bool, bool, str | None]] = [
        (argument.arg, argument, index >= required, False, "positional_only" if index < only else None)
        for index, argument in enumerate(positional)
    ]
    if receiver and entries:
        entries = entries[1:]
    if arguments.vararg is not None:
        entries.append((f"*{arguments.vararg.arg}", arguments.vararg, False, True, None))
    entries.extend(
        (argument.arg, argument, default is not None, False, "keyword_only")
        for argument, default in zip(arguments.kwonlyargs, arguments.kw_defaults, strict=True)
    )
    if arguments.kwarg is not None:
        entries.append((f"**{arguments.kwarg.arg}", arguments.kwarg, False, True, None))

    params: list[dict[str, Any]] = []
    for name, argument, optional, variadic, kind in entries[:MAX_PARAMS]:
        param: dict[str, Any] = {"name": name}
        if argument.annotation is not None:
            param["type"] = text.of(argument.annotation)
        if optional:
            param["optional"] = True
        if variadic:
            param["variadic"] = True
        if kind is not None:
            param["kind"] = kind
        params.append(param)
    signature: dict[str, Any] = {"params": params}
    if node.returns is not None:
        signature["returns"] = text.of(node.returns)
    if text.cut or len(entries) > MAX_PARAMS:
        signature["truncated"] = True
    return signature


def decoration(
    node: ast.FunctionDef | ast.AsyncFunctionDef, resolve: Callable[[str], str | None], overloaded: bool
) -> dict[str, Any]:
    """The `decorated` and `overloaded` attributes of a function or method.

    `decorated` is set for any decorator but those that keep the signature
    (:data:`PLAIN_DECORATORS`, :data:`BUILTIN_DECORATORS`), each judged by
    what its import resolves to: a decorator may replace the function, so its
    signature says nothing certain about the call. A decorator this worker
    cannot name, `@registry[key]`, counts. `overloaded` marks a def in a run of
    same-named defs that `@overload` stubs belong to.
    """
    attributes: dict[str, Any] = {}
    for decorator in node.decorator_list:
        name = dotted(decorator.func if isinstance(decorator, ast.Call) else decorator)
        resolved = resolve(name) if name else None
        if resolved is not None:
            plain = resolved.split(":", 2)[-1] in PLAIN_DECORATORS
        else:
            plain = name in BUILTIN_DECORATORS
        if not plain:
            attributes["decorated"] = True
    if overloaded:
        attributes["overloaded"] = True
    return attributes


def overloaded_definitions(tree: ast.Module) -> set[ast.AST]:
    """Each def that shares its name, in one block, with a def decorated `@overload`.

    The worker keeps the first def of a name as the node, which is a stub
    when stubs come first, so every def of the run is marked, the
    implementation included.
    """
    functions = (ast.FunctionDef, ast.AsyncFunctionDef)
    marked: set[ast.AST] = set()
    for parent in ast.walk(tree):
        for _, block in ast.iter_fields(parent):
            if not isinstance(block, list):
                continue
            runs: dict[str, list[ast.FunctionDef | ast.AsyncFunctionDef]] = {}
            for statement in block:
                if isinstance(statement, functions):
                    runs.setdefault(statement.name, []).append(statement)
            for run in runs.values():
                if any(_is_overload(definition) for definition in run):
                    marked.update(run)
    return marked


def _is_overload(node: ast.FunctionDef | ast.AsyncFunctionDef) -> bool:
    for decorator in node.decorator_list:
        name = dotted(decorator.func if isinstance(decorator, ast.Call) else decorator) or ""
        if name == "overload" or name.endswith(".overload"):
            return True
    return False

"""The signature of a function or method as its source writes it."""

from __future__ import annotations

import ast
import re
from typing import Any

MAX_PARAMS = 12
"""Parameters a signature lists at most; the rest are cut and it says so."""

MAX_TYPE_LENGTH = 80
"""Characters of an annotation's text a signature keeps at most."""

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
    in the name so the two stay apart. With `receiver`, the first positional
    parameter (`self`, `cls`) is what the call is made on, not something a
    caller passes, and is left out.
    """
    text = _Text()
    arguments = node.args
    positional = [*arguments.posonlyargs, *arguments.args]
    required = len(positional) - len(arguments.defaults)
    entries: list[tuple[str, ast.arg, bool, bool]] = [
        (argument.arg, argument, index >= required, False) for index, argument in enumerate(positional)
    ]
    if receiver and entries:
        entries = entries[1:]
    if arguments.vararg is not None:
        entries.append((f"*{arguments.vararg.arg}", arguments.vararg, False, True))
    entries.extend(
        (argument.arg, argument, default is not None, False)
        for argument, default in zip(arguments.kwonlyargs, arguments.kw_defaults, strict=True)
    )
    if arguments.kwarg is not None:
        entries.append((f"**{arguments.kwarg.arg}", arguments.kwarg, False, True))

    params: list[dict[str, Any]] = []
    for name, argument, optional, variadic in entries[:MAX_PARAMS]:
        param: dict[str, Any] = {"name": name}
        if argument.annotation is not None:
            param["type"] = text.of(argument.annotation)
        if optional:
            param["optional"] = True
        if variadic:
            param["variadic"] = True
        params.append(param)
    signature: dict[str, Any] = {"params": params}
    if node.returns is not None:
        signature["returns"] = text.of(node.returns)
    if text.cut or len(entries) > MAX_PARAMS:
        signature["truncated"] = True
    return signature

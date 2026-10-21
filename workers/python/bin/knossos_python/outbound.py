"""Outbound calls: HTTP client requests, App Engine tasks and Celery submissions."""

import ast
import re
from collections.abc import Callable
from typing import Any

from .accumulator import PythonFactAccumulator
from .ast_helpers import positional_string, ref
from .module_index import ProjectModuleIndex

HTTP_METHODS = {"get", "post", "put", "patch", "delete", "head", "options"}
# The Celery task methods that queue the task, and those that build a signature of it.
CELERY_SUBMISSIONS = {"delay", "apply_async"}
CELERY_SIGNATURES = {"s", "si", "signature"}
ABSOLUTE_URL = re.compile(r"^([a-z][a-z0-9+.-]*:|//)", re.IGNORECASE)


def keyword_value(node: ast.Call, name: str) -> ast.expr | None:
    return next((item.value for item in node.keywords if item.arg == name), None)


def static_method(node: ast.AST) -> str:
    """An HTTP method written as a string literal, upper-cased; ``ANY`` when it is computed."""
    if isinstance(node, ast.Constant) and isinstance(node.value, str):
        return node.value.strip().upper()
    return "ANY"


def request_url(node: ast.AST | None) -> str | None:
    """A URL as written, each f-string substitution as ``{}``; None for anything else or only substitutions."""
    if isinstance(node, ast.Constant) and isinstance(node.value, str):
        return node.value
    if isinstance(node, ast.JoinedStr):
        uri = "".join(
            part.value if isinstance(part, ast.Constant) and isinstance(part.value, str) else "{}"
            for part in node.values
        )
        return uri if uri.replace("{}", "") else None
    return None


def url_template(uri: str, prefix_unknown: bool) -> str:
    """The path template a URL stands for, as the core normalises it."""
    path = re.sub(r"[?#].*$", "", uri, flags=re.DOTALL)
    segments = ["{}" if "{}" in segment else segment for segment in path.split("/") if segment]
    return "/" + "/".join(segments[1:] if prefix_unknown else segments)


class OutboundCallRecorder:
    """Record the endpoints a call requests and the Celery tasks it queues.

    The collector shares its facts and import aliases, and supplies name
    resolution and the declaration a call is made from (``current``).
    """

    def __init__(
        self,
        facts: PythonFactAccumulator,
        index: ProjectModuleIndex,
        aliases: dict[str, str],
        resolve_name: Callable[[str, str], str | None],
        current: Callable[[], str],
    ) -> None:
        self.facts = facts
        self.index = index
        self.aliases = aliases
        self.resolve_name = resolve_name
        self.current = current

    def submitted_task(self, name: str | None) -> tuple[str, bool] | None:
        """The function a Celery submission queues, and whether it is only a signature.

        ``<task>.delay(...)`` and ``<task>.apply_async(...)`` queue the task.
        A signature, ``<task>.s(...)``, ``.si(...)`` or ``.signature(...)``,
        queues it once something applies it: a chain, a chord, a group, or
        the caller it is returned to. Nearly every signature is built to be
        applied, so it counts too, as a probable dispatch. Only a receiver
        that resolves to a function of the project counts.
        """
        if not name or "." not in name:
            return None
        receiver, method = name.rsplit(".", 1)
        if method not in CELERY_SUBMISSIONS and method not in CELERY_SIGNATURES:
            return None
        target = self.resolve_name(receiver, "function")
        if target is None or not target.startswith("py:function:"):
            return None
        return target, method in CELERY_SIGNATURES

    def request_endpoint(self, node: ast.Call, name: str | None) -> None:
        """Record the URL an HTTP client call or an App Engine task names as an endpoint."""
        if not name or name.count(".") != 1:
            return
        head, member = name.split(".")
        bound = (self.aliases.get(head) or "").split(":", 2)[-1]
        if bound in {"requests", "httpx"}:
            if member in HTTP_METHODS:
                url, method = node.args[0] if node.args else keyword_value(node, "url"), member.upper()
            elif member == "request":
                given = node.args[0] if node.args else keyword_value(node, "method")
                if given is None:
                    return
                url = node.args[1] if len(node.args) > 1 else keyword_value(node, "url")
                method = static_method(given)
            else:
                return
            uri = request_url(url)
            if uri is not None:
                self.add_endpoint("calls_endpoint", bound, method, uri, node)
        elif bound == "google.appengine.api.taskqueue" and member in {"add", "Task"}:
            uri = request_url(keyword_value(node, "url"))
            method_node = keyword_value(node, "method")
            method = "POST" if method_node is None else static_method(method_node)
            if uri is not None:
                self.add_endpoint("dispatches", "appengine", method, uri, node)

    def add_endpoint(self, edge_kind: str, framework: str, method: str, uri: str, node: ast.Call) -> None:
        unknown = uri.startswith("{}")
        endpoint = ref("endpoint", f"{method} {uri}")
        self.facts.add_node(
            endpoint,
            "endpoint",
            f"{method} {uri}",
            f"{method} {uri}",
            node,
            {
                "framework": framework,
                "method": method,
                "uri": uri,
                "template": url_template(uri, unknown),
                "prefix": "unknown" if unknown else "known",
                "external": bool(ABSOLUTE_URL.match(uri)),
            },
            "framework_convention",
        )
        self.facts.add_edge(edge_kind, self.current(), endpoint, node, None, "framework_convention")

    def task_by_name(self, node: ast.Call, name: str | None) -> bool:
        """Record a Celery task queued by its name as a dispatch; whether the call was one.

        ``<app>.send_task("shop.tasks.deliver")`` queues the task registered
        under that name, and ``celery.signature("shop.tasks.deliver")`` builds
        a signature of it. Celery names a task after its module and function
        unless the decorator says otherwise, so the name is read that way: a
        function the named module declares is the target; any other name is a
        speculative target the core keeps only when it exists. A name that is
        not a literal names nothing.
        """
        if not name:
            return False
        if name.endswith(".send_task"):
            signature = False
        elif name.rsplit(".", 1)[-1] == "signature" and self.aliases.get(name, name).endswith("celery.signature"):
            signature = True
        else:
            return False
        task = positional_string(node, 0)
        module, _, function = (task or "").rpartition(".")
        if not module or not function:
            return False
        found = (
            self.index.layout.canonical_module(module) if self.index.layout.module_file(module) is not None else None
        )
        declared = self.index.module_declarations(found).get(function, "") if found else ""
        attributes: dict[str, Any] = {"celery": "signature"} if signature else {}
        if declared.startswith("py:function:"):
            confidence = "probable" if signature else "certain"
            self.facts.add_edge("dispatches", self.current(), declared, node, attributes, confidence=confidence)
        else:
            attributes = {"celery": "signature" if signature else "by_name", "speculative": True}
            self.facts.add_edge(
                "dispatches", self.current(), ref("function", task or ""), node, attributes, confidence="probable"
            )
        return True

    def record_submission(self, node: ast.Call, name: str | None) -> bool:
        """Record a Celery submission or signature as a dispatch; whether the call was one.

        `deliver.delay(order_id)` queues `deliver` to run later: a dispatch,
        not a call, and `delay` is no function of the project.
        """
        if self.task_by_name(node, name):
            return True
        submitted = self.submitted_task(name)
        if submitted is None:
            return False
        task, signature = submitted
        if signature:
            attributes = {"celery": "signature"}
            self.facts.add_edge("dispatches", self.current(), task, node, attributes, confidence="probable")
        else:
            self.facts.add_edge("dispatches", self.current(), task, node)
        return True

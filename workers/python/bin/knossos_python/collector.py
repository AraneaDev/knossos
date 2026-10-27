"""The AST visitor that collects one Python file's facts."""

import ast
from pathlib import PurePosixPath
from typing import Any

from .accumulator import PythonFactAccumulator
from .ast_helpers import (
    absolute_import,
    bound_names,
    calls_own_super_member,
    declared_protocols,
    dotted,
    is_protocol_base,
    is_type_checking_guard,
    names_main_guard,
    ref,
    superseded_definitions,
)
from .frameworks import DjangoFactEnricher, FastApiFactEnricher, FlaskFactEnricher, PythonFrameworkRoleEnricher
from .launches import LaunchRecorder
from .module_index import ProjectModuleIndex
from .outbound import OutboundCallRecorder
from .receiver_types import ReceiverTypeInference


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


class PythonAstFactCollector(ast.NodeVisitor):
    """Coordinate one AST traversal and delegate fact enrichment."""

    def __init__(
        self,
        relative: str,
        tree: ast.Module,
        index: ProjectModuleIndex,
        module_collision: bool = False,
        has_shebang: bool = False,
        name: str | None = None,
        owner: str | None = None,
    ) -> None:
        self.relative = relative
        self.has_shebang = has_shebang
        # How many `if TYPE_CHECKING:` bodies the walk is inside: an import
        # there is read by the type checker and skipped at runtime.
        self.type_checking_depth = 0
        self.executable = has_shebang or names_main_guard(tree)
        # `029_seed.py` names no module an import statement can reach, so a
        # loader reads it by path and calls the public names it exposes.
        self.loaded_by_path = not PurePosixPath(relative).stem.isidentifier()
        self.index = index
        # Where the file sits, which relative imports climb from, and the id
        # its symbols take: the same unless ``owner``, the file an import of
        # this location finds, is another file (see ModuleLayout.file_identity).
        self.module = index.layout.module_for(relative)
        self.name = name or self.module
        self.owner = owner
        self.is_package = PurePosixPath(relative).stem == "__init__"
        self.module_collision = module_collision
        self.tree = tree
        self.superseded = superseded_definitions(tree)
        self.aliases: dict[str, str] = {}
        self.containers: list[tuple[str, str, str]] = []
        self.local_function_scopes: list[dict[str, str]] = []
        # Methods each class declares in its own body, keyed by the class's
        # canonical name, so `self.<name>` read as a value can be told apart
        # from a data attribute before the method's own definition is visited.
        self.class_methods: dict[str, frozenset[str]] = {}
        # The `self.<name>` attributes a class's methods assign that name no
        # method of it: `self.declarations = declarations` stores a callable,
        # and `self.declarations(m)` calls that, not a method of the class.
        self.stored_attributes: dict[str, frozenset[str]] = {}
        # `self.<name>` nodes that are the callee of a call: those are `calls`.
        self.called_attributes: set[int] = set()
        # Member names called on a receiver no type was inferred for.
        self.untyped_calls: set[str] = set()
        # Structural protocols this file declares: a call through one may
        # reach any class with the member, declared or not.
        self.protocols: set[str] = set()
        # Classes deriving from the standard library's AST visitor, whose
        # `visit_<Node>` hooks it dispatches to by name.
        self.ast_visitors: set[str] = set()
        # The modules the conftest fixtures over a test module load by path, read when a test function first asks.
        self.fixture_modules: dict[str, str] | None = None
        self.serves_app = False
        self.module_id = ref("module", self.name)
        self.facts = PythonFactAccumulator(relative)
        routing = (self.facts, self.name, self.module_id, self.aliases, self.resolve_name, index.module_declarations)
        self.fastapi = FastApiFactEnricher(*routing)
        self.django = DjangoFactEnricher(
            self.facts,
            self.name,
            self.module_id,
            self.aliases,
            self.resolve_name,
            lambda module: (
                index.layout.canonical_module(module) if index.layout.module_file(module) is not None else None
            ),
        )
        self.flask = FlaskFactEnricher(*routing)
        self.roles = PythonFrameworkRoleEnricher(django=self.django.imports_django(tree))
        # What a receiver holds, so a call on it names the method that runs.
        self.receivers = ReceiverTypeInference(
            index, self.name, self.aliases, self.containers, self.django, self.resolve_name
        )
        self.outbound = OutboundCallRecorder(self.facts, index, self.aliases, self.resolve_name, self.current)
        self.launches = LaunchRecorder(self.facts, self.aliases, self.current)

    def collect(self) -> dict[str, Any]:
        self.facts.add_node(
            self.module_id,
            "module",
            self.name,
            self.name,
            self.tree,
            {
                "stub": self.relative.endswith(".pyi"),
                "executable": self.executable,
                # Run by the import system whenever a module inside the package is imported.
                "package_init": self.is_package,
            }
            | ({"runtime_invoked": True} if self.loaded_by_path else {}),
        )
        if self.is_package:
            package = self.name
            # Displayed by where it sits: a path-qualified id ends in its path.
            display = self.module.split(".")[-1]
            self.facts.add_node(ref("package", package), "package", package, display, self.tree)
            self.facts.add_edge("contains", ref("package", package), self.module_id, self.tree)
        self.collision_diagnostic()
        # Known before any call is visited: a call may precede the class.
        self.protocols |= declared_protocols(self.tree, self.name)
        self.visit(self.tree)
        if self.serves_app:
            self.facts.nodes[self.module_id]["attributes"]["executable"] = True
        if self.untyped_calls:
            # A method by one of these names may be what such a call reaches,
            # so the core reports it as only possibly dead.
            self.facts.nodes[self.module_id]["attributes"]["unresolved_member_calls"] = sorted(self.untyped_calls)
        if self.relative.endswith(".pyi"):
            # A stub describes code rather than being it: its symbols are
            # reached through the module they describe, never by a call.
            for fact in self.facts.nodes.values():
                fact["attributes"]["declaration_file"] = True
        return self.facts.result()

    def collision_diagnostic(self) -> None:
        """Report a module id this file shares with another file, and the id it takes instead.

        A stub beside its own module shares the id by design and is named
        apart without a warning.
        """
        renamed = f" This file's symbols are named '{self.name}'." if self.name != self.module else ""
        if self.module_collision:
            message = (
                f"Module id '{self.module}' is shared by a module file and a package; "
                f"the package (__init__.py) owns it.{renamed}"
            )
        elif self.owner is not None and not (self.relative.endswith(".pyi") and self.owner == self.relative[:-1]):
            message = (
                f"Module id '{self.module}' is also the id of '{self.owner}', which an import of it finds.{renamed}"
            )
        else:
            return
        self.facts.add_diagnostic("PY_MODULE_ID_COLLISION", message, self.tree)

    def current(self) -> str:
        return self.containers[-1][0] if self.containers else self.module_id

    def is_protocol(self, owner: str) -> bool:
        """Whether ``owner`` is a structural protocol, declared here or imported."""
        return owner in self.protocols or self.index.is_protocol(owner)

    def resolve_name(self, name: str, hint: str = "class") -> str | None:
        if "." not in name:
            for scope in reversed(self.local_function_scopes):
                if name in scope:
                    return scope[name]
        if name in self.aliases:
            return self.aliases[name]
        local = self.index.module_declarations(self.name).get(name)
        if local:
            return local
        if "." in name:
            first, rest = name.split(".", 1)
            base = self.aliases.get(first)
            if base and base.startswith("py:module:"):
                return self.module_member(base.removeprefix("py:module:"), rest, hint)
        return None

    def module_member(self, module: str, rest: str, hint: str) -> str:
        """What ``<module>.<rest>`` names: a declaration of the module, or of the submodule ``rest`` reaches into.

        ``import app.models.user`` binds ``app``, so ``app.models.user.make()``
        names ``make`` in the longest submodule the import system finds,
        ``app.models.user``, unless ``app`` itself declares ``models``.
        """
        parts = rest.split(".")
        if len(parts) > 1 and parts[0] not in self.index.module_declarations(module):
            for end in range(len(parts) - 1, 0, -1):
                spelled = ".".join([module, *parts[:end]])
                if self.index.layout.module_file(spelled) is not None:
                    module, parts = self.index.layout.canonical_module(spelled), parts[end:]
                    break
        member = ".".join(parts)
        return self.index.module_declarations(module).get(member, ref(hint, f"{module}.{member}"))

    def script_import(self, module: str) -> str:
        """An absolute import, resolved in the importer's own directory when no source root has it.

        Only when that directory is on ``sys.path`` (:meth:`runs_from_own_directory`).
        """
        if self.index.layout.module_file(module) is not None or not self.runs_from_own_directory():
            return module
        return self.index.layout.script_sibling_module(self.relative, module) or module

    def runs_from_own_directory(self) -> bool:
        """Whether this file's own directory is first on ``sys.path`` when it runs.

        A script's is (``python3 tools/run.py``). So is a module's in a
        directory that is no package: pytest puts a test module's directory
        there, and a script run beside it puts it there for the modules it
        imports. Inside a package, a bare import never names a sibling.
        """
        if self.executable:
            return True
        directory = PurePosixPath(self.relative).parent
        return bool(directory.parts) and not self.index.layout.is_package_directory(directory)

    def visit_If(self, node: ast.If) -> None:
        """Walk an ``if TYPE_CHECKING:`` body as type-only; its ``else`` runs."""
        if not is_type_checking_guard(node.test):
            self.generic_visit(node)
            return
        self.visit(node.test)
        self.type_checking_depth += 1
        try:
            for statement in node.body:
                self.visit(statement)
        finally:
            self.type_checking_depth -= 1
        for statement in node.orelse:
            self.visit(statement)

    def import_attributes(self, attributes: dict[str, Any]) -> dict[str, Any]:
        """An import's attributes, marked type-only when only a type checker runs it."""
        return {**attributes, "type_only": True} if self.type_checking_depth else attributes

    def visit_Import(self, node: ast.Import) -> None:
        for alias in node.names:
            spelled = self.script_import(alias.name)
            target = ref("module", self.index.layout.canonical_module(spelled))
            # `import a.b` binds `a`, the spelling less the segments after the
            # first; `import a.b as m` binds `m` to `a.b`.
            depth = 0 if alias.asname else alias.name.count(".")
            bound = (
                target
                if depth == 0
                else ref("module", self.index.layout.canonical_module(spelled.rsplit(".", depth)[0]))
            )
            self.aliases[alias.asname or alias.name.split(".")[0]] = bound
            self.facts.add_edge(
                "imports", self.module_id, target, node, self.import_attributes({"alias": alias.asname})
            )

    def visit_ImportFrom(self, node: ast.ImportFrom) -> None:
        spelled = absolute_import(self.module, node.level, node.module, self.is_package)
        if node.level == 0 and spelled:
            spelled = self.script_import(spelled)
        if not spelled:
            # A relative import that climbs past the top of the project: legal to
            # parse, unrunnable at import time, and nameable by nothing in the
            # graph. Report it rather than emitting `py:module:`, which is not a
            # symbol reference at all.
            self.facts.add_diagnostic(
                "PY_UNRESOLVED_RELATIVE_IMPORT",
                f"Relative import at level {node.level} has no parent package in module "
                f"'{self.module}'; the import edge was skipped.",
                node,
            )
            return
        # Named by the file the import finds, the id that file's own scan declares.
        module = self.index.layout.canonical_module(spelled)
        self.facts.add_edge(
            "imports",
            self.module_id,
            ref("module", module),
            node,
            self.import_attributes({"relative_level": node.level}),
        )
        for alias in node.names:
            if alias.name == "*":
                continue
            target = self.index.module_declarations(spelled).get(alias.name)
            if target is None and self.index.layout.module_file(f"{spelled}.{alias.name}") is not None:
                # `from .tools import cors` names the submodule `tools/cors.py`
                # when the package declares no `cors` of its own.
                target = ref("module", self.index.layout.canonical_module(f"{spelled}.{alias.name}"))
                self.facts.add_edge(
                    "imports", self.module_id, target, node, self.import_attributes({"relative_level": node.level})
                )
            self.aliases[alias.asname or alias.name] = target or ref("external_symbol", f"{module}.{alias.name}")

    def visit_ClassDef(self, node: ast.ClassDef) -> None:
        canonical = f"{self.name}.{node.name}"
        local_id = ref("class", canonical)
        decorators = self.roles.decorators(node)
        roles = self.roles.class_roles(node, decorators)
        self.facts.add_node(
            local_id,
            "class",
            canonical,
            node.name,
            node,
            {"decorators": decorators, "python_framework_roles": roles},
        )
        self.facts.add_edge("contains", self.current(), local_id, node)
        bases: list[str] = []
        for base in node.bases:
            # The `extends` edge below is what a base is; not also a reference.
            self.called_attributes.add(id(base))
            name = dotted(base)
            target = self.resolve_name(name, "class") if name else None
            if target:
                self.facts.add_edge("extends", local_id, target, base)
                if target.startswith("py:class:"):
                    bases.append(target.removeprefix("py:class:"))
        if any(is_protocol_base(base) for base in node.bases):
            self.protocols.add(canonical)
        if any(self.is_ast_visitor_base(base) for base in node.bases):
            self.ast_visitors.add(canonical)
        self.class_methods[canonical] = frozenset(
            item.name for item in node.body if isinstance(item, (ast.FunctionDef, ast.AsyncFunctionDef))
        )
        self.stored_attributes[canonical] = assigned_self_attributes(node) - self.class_methods[canonical]
        self.django.remember_serializer(canonical, node)
        self.containers.append((local_id, canonical, "class"))
        self.receivers.declare_class_attributes(canonical, node, bases)
        self.generic_visit(node)
        self.containers.pop()

    def validated_field(self, node: ast.AST) -> None:
        """A view's read of a serializer's validated field depends on the ``validate_<field>`` that produced it.

        Only a serializer whose class is known counts, and the edge is kept
        only when the class declares the method.
        """
        read = self.django.validated_field(node)
        member = self.receivers.receiver_member(*read) if read is not None else None
        if member is not None:
            self.facts.add_edge(
                "references",
                self.current(),
                member,
                node,
                {"speculative": True, "drf": "validated_field"},
                confidence="probable",
            )

    def visit_Subscript(self, node: ast.Subscript) -> None:
        self.validated_field(node)
        self.generic_visit(node)

    def visit_FunctionDef(self, node: ast.FunctionDef) -> None:
        self.function(node, async_function=False)

    def visit_AsyncFunctionDef(self, node: ast.AsyncFunctionDef) -> None:
        self.function(node, async_function=True)

    def function(self, node: ast.FunctionDef | ast.AsyncFunctionDef, async_function: bool) -> None:
        if node in self.superseded:
            # A later `def` of the same name rebinds it, so this body runs as nothing the graph can name.
            return
        if self.containers and self.containers[-1][2] == "class":
            parent_id, parent_canonical, _ = self.containers[-1]
            kind, canonical = "method", f"{parent_canonical}::{node.name}"
        elif self.containers:
            parent_id, parent_canonical, _ = self.containers[-1]
            kind, canonical = "function", f"{parent_canonical}.<locals>.{node.name}"
        else:
            parent_id, kind, canonical = self.current(), "function", f"{self.name}.{node.name}"
        local_id = ref(kind, canonical)
        decorators = self.roles.decorators(node)
        fastapi_routes = self.fastapi.route_decorators(node)
        flask_routes = self.flask.route_decorators(node)
        roles = self.roles.function_roles(decorators, bool(fastapi_routes), bool(flask_routes))
        attributes: dict[str, Any] = {
            "async": async_function,
            "decorators": decorators,
            "python_framework_roles": roles,
        }
        if self.registered_by_object(decorators) or (
            self.loaded_by_path and not self.containers and not node.name.startswith("_")
        ):
            attributes["runtime_invoked"] = True
        if kind == "method" and self.overrides_supertype_member(node, parent_canonical, decorators):
            attributes["overrides"] = True
        self.facts.add_node(local_id, kind, canonical, node.name, node, attributes)
        self.facts.add_edge("contains", parent_id, local_id, node)
        self.fastapi.enrich_function(node, local_id, canonical, fastapi_routes)
        self.flask.enrich_function(node, local_id, canonical, flask_routes)
        self.containers.append((local_id, canonical, kind))
        self.local_function_scopes.append(self.local_function_declarations(node, canonical, self.name))
        self.receivers.local_variable_types.append({})
        self.receivers.parameter_types.append(self.receivers.annotated_parameters(node))
        self.receivers.bound_names.append(bound_names(node))
        self.fastapi.enter_scope()
        self.flask.enter_scope()
        restore_fastapi = self.fastapi.register_parameters(node)
        # An import inside the function binds its names in the function only.
        # Restored in place: the framework enrichers hold this same mapping.
        outer_aliases = dict(self.aliases)
        self.bind_fixture_modules(node)
        self.generic_visit(node)
        self.aliases.clear()
        self.aliases.update(outer_aliases)
        self.fastapi.restore_parameters(restore_fastapi)
        self.fastapi.leave_scope()
        self.flask.leave_scope()
        self.receivers.parameter_types.pop()
        self.receivers.bound_names.pop()
        self.receivers.local_variable_types.pop()
        self.local_function_scopes.pop()
        self.containers.pop()

    def bind_fixture_modules(self, node: ast.FunctionDef | ast.AsyncFunctionDef) -> None:
        """Bind each parameter of a test function that takes a path-loading fixture to the module it loads.

        Only in a module pytest collects (``test_*.py``, ``*_test.py``):
        there a parameter named after a fixture is that fixture's value, and
        a fixture that loads a file by path hands over that file's module
        (:meth:`ProjectModuleIndex.path_loaded_fixtures`).
        """
        stem = PurePosixPath(self.relative).stem
        if not (stem.startswith("test_") or stem.endswith("_test")):
            return
        if self.fixture_modules is None:
            self.fixture_modules = self.index.path_loaded_fixtures(self.relative)
        arguments = node.args
        for argument in [*arguments.posonlyargs, *arguments.args, *arguments.kwonlyargs]:
            module = self.fixture_modules.get(argument.arg)
            if module is not None:
                self.aliases[argument.arg] = ref("module", module)

    def is_ast_visitor_base(self, base: ast.expr) -> bool:
        """Whether a base names the standard library's ``ast.NodeVisitor`` or ``ast.NodeTransformer``."""
        name = dotted(base)
        target = self.resolve_name(name, "class") if name else None
        if target is None or self.index.layout.module_file("ast") is not None:
            return False
        return target.split(":", 2)[-1] in {"ast.NodeVisitor", "ast.NodeTransformer"}

    def implied_calls(self, target: str | None, node: ast.Call) -> None:
        """The calls a call of ``target`` makes that no source names: a class's ``__init__``, a visitor's hooks."""
        # Calling a class runs the `__init__` it declares.
        initialiser = self.index.initialiser(target) if target and target.startswith("py:class:") else None
        if initialiser is not None:
            self.facts.add_edge("calls", self.current(), initialiser, node)
        for hook in self.dispatched_hooks(target):
            self.facts.add_edge(
                "calls", self.current(), hook, node, {"dispatch": "ast.NodeVisitor"}, confidence="probable"
            )

    def dispatched_hooks(self, target: str | None) -> list[str]:
        """The ``visit_<Node>`` hooks a call of an AST visitor's inherited ``visit`` reaches.

        ``ast.NodeVisitor.visit`` looks the hook up by the node's type through
        ``getattr``, so no call names one: the call may reach each hook the
        visitor declares. A visitor that declares its own ``visit`` decides
        itself what it calls.
        """
        if target is None or not target.startswith("py:method:") or not target.endswith("::visit"):
            return []
        owner = target.removeprefix("py:method:").rsplit("::", 1)[0]
        methods = self.class_methods.get(owner, frozenset())
        if owner not in self.ast_visitors or "visit" in methods:
            return []
        return [ref("method", f"{owner}::{name}") for name in sorted(methods) if name.startswith("visit_")]

    def overrides_supertype_member(
        self, node: ast.FunctionDef | ast.AsyncFunctionDef, owner: str, decorators: list[str]
    ) -> bool:
        """Whether a method fulfils a supertype's member, by the source's word or by dispatch.

        ``@override`` from ``typing`` or ``typing_extensions`` is the source
        saying so, and so is a ``super()`` call of the method's own name,
        which only works when a supertype has that member: a dependency's
        base is not scanned, so this is the one sign of an override of it.
        An AST visitor's ``visit``, ``generic_visit`` and ``visit_<Node>``
        hooks are called by ``NodeVisitor.visit`` through ``getattr``, so no
        call names them. A same-named decorator from anywhere else says
        nothing.
        """
        name = node.name
        if owner in self.ast_visitors and (name in {"visit", "generic_visit"} or name.startswith("visit_")):
            return True
        if calls_own_super_member(node):
            return True
        typing_modules = {"typing", "typing_extensions"}
        imported = {f"{module}.override" for module in typing_modules}
        for decorator in decorators:
            head, _, member = decorator.rpartition(".")
            if member != "override":
                continue
            if head:
                if (self.aliases.get(head) or "").removeprefix("py:module:") in typing_modules:
                    return True
            elif (self.aliases.get("override") or "").split(":", 2)[-1] in imported:
                return True
        return False

    def registered_by_object(self, decorators: list[str]) -> bool:
        """Whether a decorator hands the function to an object that calls it.

        ``@tq.register("scan")`` and ``@bus.on`` register the function with a
        queue or a bus, which calls it at runtime, so no call names it. A
        decorator reached through an imported module, ``@functools.cache``,
        wraps the function without registering it anywhere.
        """
        for name in decorators:
            head, _, member = name.partition(".")
            if member and not (self.aliases.get(head) or "").startswith("py:module:"):
                return True
        return False

    @staticmethod
    def local_function_declarations(
        node: ast.FunctionDef | ast.AsyncFunctionDef, parent_canonical: str, module: str
    ) -> dict[str, str]:
        declarations: dict[str, str] = {}
        pending: list[ast.AST] = list(reversed(node.body))
        while pending:
            child = pending.pop()
            if isinstance(child, (ast.FunctionDef, ast.AsyncFunctionDef)):
                canonical = f"{parent_canonical}.<locals>.{child.name}"
                declarations[child.name] = ref("function", canonical)
                continue
            if isinstance(child, ast.ClassDef):
                # A class defined in a function is named at module level.
                declarations[child.name] = ref("class", f"{module}.{child.name}")
                continue
            if isinstance(child, ast.Lambda):
                continue
            pending.extend(reversed(list(ast.iter_child_nodes(child))))
        return declarations

    def emit_value_references(self, value: ast.AST | None) -> None:
        """Record a declaration named as a value rather than called.

        A dispatch table is the common shape::

            DERIVATIONS = {"adr_files": (derive_adr_files, "ADR files")}

        Nothing calls `derive_adr_files` anywhere, so with only `calls` edges
        it, and every function reached the same way, read as unreferenced dead
        code. This worker emitted no reference edges at all, which made the
        whole registry-reached class invisible.

        Deliberately narrow: an assignment's right-hand side, descending only
        through literal containers. Walking into calls, comprehensions or
        lambdas would turn every mention of a symbol into an edge and inflate
        the in-degree the hub ranking is built on, which is a different claim
        from "something holds a handle to this".
        """
        pending: list[ast.AST] = [] if value is None else [value]
        while pending:
            item = pending.pop()
            if isinstance(item, (ast.List, ast.Tuple, ast.Set)):
                pending.extend(item.elts)
                continue
            if isinstance(item, ast.Dict):
                pending.extend(key for key in item.keys if key is not None)
                pending.extend(item.values)
                continue
            if not isinstance(item, (ast.Name, ast.Attribute)):
                continue
            name = dotted(item)
            target = self.resolve_name(name, "function") if name else None
            if target is not None and target != self.current():
                self.facts.add_edge("references", self.current(), target, item)

    SERVED_APPS = (
        "fastapi.FastAPI",
        "flask.Flask",
        "starlette.applications.Starlette",
        "quart.Quart",
        "litestar.Litestar",
        "django.core.wsgi.get_wsgi_application",
        "django.core.asgi.get_asgi_application",
    )

    def note_served_app(self, value: ast.AST | None) -> None:
        """Mark the module executable when it builds an app a server serves.

        ``app = FastAPI()`` at module level is what ``uvicorn main:app`` or a
        WSGI server loads by name; nothing imports the module for it.
        """
        if self.containers or not isinstance(value, ast.Call):
            return
        called = dotted(value.func) or ""
        resolved = self.aliases.get(called) or self.resolve_name(called, "class") or ""
        # `py:<kind>:<name>`: the name alone, whichever kind the import resolved to.
        if resolved.split(":", 2)[-1] in self.SERVED_APPS:
            self.serves_app = True

    def visit_With(self, node: ast.With | ast.AsyncWith) -> None:
        """``with Lock() as lock:`` binds ``lock`` to the ``Lock`` the statement builds.

        Only a project class built right there types the name; ``with open(p)
        as f`` and a context manager a function returns hold something this
        worker does not follow, so the name drops whatever it held before.
        """
        for item in node.items:
            if isinstance(item.optional_vars, ast.Name):
                built = item.context_expr
                callee = dotted(built.func) if isinstance(built, ast.Call) else None
                resolved = self.resolve_name(callee, "class") if callee else None
                constructs = resolved is not None and resolved.startswith("py:class:")
                self.receivers.remember_local(item.optional_vars.id, built if constructs else None)
        self.generic_visit(node)

    visit_AsyncWith = visit_With

    def visit_Assign(self, node: ast.Assign) -> None:
        self.note_served_app(node.value)
        self.emit_value_references(node.value)
        if len(node.targets) == 1 and isinstance(node.targets[0], ast.Name):
            variable = node.targets[0].id
            self.fastapi.register_assignment(variable, node.value, not self.containers)
            self.django.enrich_assignment(variable, node.value, node, not self.containers)
            self.flask.register_assignment(variable, node.value, not self.containers)
            self.receivers.remember_local(variable, node.value)
        if len(node.targets) == 1:
            attribute = self.self_attribute(node.targets[0])
            if attribute is not None:
                self.receivers.remember_attribute(attribute, node.value)
        self.generic_visit(node)

    def visit_AugAssign(self, node: ast.AugAssign) -> None:
        # `urlpatterns += [...]` adds patterns as the assignment does.
        if isinstance(node.target, ast.Name) and node.target.id == "urlpatterns" and isinstance(node.op, ast.Add):
            self.django.url_patterns(node.value)
        self.generic_visit(node)

    def visit_List(self, node: ast.List) -> None:
        self.launches.launched_file(node.elts, node)
        self.generic_visit(node)

    def visit_Tuple(self, node: ast.Tuple) -> None:
        self.launches.launched_file(node.elts, node)
        self.generic_visit(node)

    def visit_AnnAssign(self, node: ast.AnnAssign) -> None:
        self.note_served_app(node.value)
        self.emit_value_references(node.value)
        attribute = self.self_attribute(node.target)
        if attribute is not None:
            # An annotation states the type outright, which beats inferring it.
            self.receivers.remember_attribute(attribute, node.value, node.annotation)
        elif isinstance(node.target, ast.Name):
            self.fastapi.register_assignment(node.target.id, node.value, not self.containers)
            self.flask.register_assignment(node.target.id, node.value, not self.containers)
            if not (self.containers and self.containers[-1][2] == "class"):
                # In a class body the name is the class's attribute, which
                # `declare_class_attributes` read, not a local of a function around it.
                self.receivers.remember_local(node.target.id, node.value, node.annotation)
        self.generic_visit(node)

    @staticmethod
    def self_attribute(target: ast.AST) -> str | None:
        """The attribute name when a target is `self.<name>`, else None."""

        return (
            target.attr
            if isinstance(target, ast.Attribute) and isinstance(target.value, ast.Name) and target.value.id == "self"
            else None
        )

    def visit_Name(self, node: ast.Name) -> None:
        """A function or class named as a value: returned, registered, passed.

        `def make_tool(): def handler(): ...; return handler` and
        `REGISTRY = [ping]` never call what they name, so without this every
        handler built or listed that way read as dead. Only a name that
        resolves to a function or class counts: a local closure first, then an
        import, then the module's own top-level declarations. A call's callee
        is the `calls` edge's, and a class base the `extends` edge's.
        """
        if isinstance(node.ctx, ast.Load) and id(node) not in self.called_attributes:
            target = next(
                (scope[node.id] for scope in reversed(self.local_function_scopes) if node.id in scope),
                None,
            )
            if target is None and not any(node.id in names for names in self.receivers.bound_names):
                # A name this function, or one around it, binds is a local
                # there, whatever the module declares under that name.
                target = self.aliases.get(node.id) or self.index.module_declarations(self.name).get(node.id)
            if target is not None and target.startswith(("py:function:", "py:class:")) and target != self.current():
                self.facts.add_edge("references", self.current(), target, node)
        self.generic_visit(node)

    def visit_Attribute(self, node: ast.Attribute) -> None:
        """A method of this class read as a value: a callback, a slot, a property.

        Only ``self.<name>`` where the enclosing class declares ``<name>``: a
        data attribute is no declaration, and an inherited name belongs to a
        class this file may not know.
        """
        if (
            isinstance(node.ctx, ast.Load)
            and id(node) not in self.called_attributes
            and isinstance(node.value, ast.Name)
            and node.value.id == "self"
        ):
            class_container = next((item for item in reversed(self.containers) if item[2] == "class"), None)
            if class_container is not None and node.attr in self.class_methods.get(class_container[1], ()):
                target = ref("method", f"{class_container[1]}::{node.attr}")
                if target != self.current():
                    self.facts.add_edge("references", self.current(), target, node)
        elif isinstance(node.ctx, ast.Load) and id(node) not in self.called_attributes:
            # `client.prune_cache` read off a receiver whose class is known: a
            # bound method handed on, or a data attribute. Only the class can
            # tell, so the reconciler keeps the edge when the member resolves.
            receiver = dotted(node.value)
            member = self.receivers.receiver_member(receiver, node.attr) if receiver else None
            if member is None and isinstance(node.value, ast.Call):
                # `partial(Repo().rows, 1)`: the class just built types the read.
                held = self.receivers.fresh_instance_class(node.value)
                if held is not None:
                    member = ref("method", f"{held}::{node.attr}")
            if member is not None:
                self.facts.add_edge("references", self.current(), member, node, {"speculative": True})
            elif receiver and (self.aliases.get(receiver) or "").startswith("py:module:"):
                # `tasks.snapshot` read off an imported project module: the
                # function it declares, handed on as a value. Only a name the
                # module declares counts; `os.environ` names nothing here.
                module = self.aliases[receiver].removeprefix("py:module:")
                named = self.index.module_declarations(module).get(node.attr)
                if named is not None and named.startswith(("py:function:", "py:class:")) and named != self.current():
                    self.facts.add_edge("references", self.current(), named, node)
        self.generic_visit(node)

    def visit_Call(self, node: ast.Call) -> None:
        self.called_attributes.add(id(node.func))
        name = dotted(node.func)
        self.validated_field(node)
        self.outbound.request_endpoint(node, name)
        target = None
        if not self.outbound.record_submission(node, name) and name:
            member = name.rsplit(".", 1)
            if len(member) == 2:
                target = self.receivers.receiver_member(member[0], member[1])
            if target is None and name.startswith("self.") and name.count(".") == 1 and self.containers:
                # Only a direct `self.<name>()`. `self.a.b()` is a call on
                # whatever `a` holds, and reading it as a member of this class
                # invents a symbol named `a.b` that nothing declares.
                class_container = next((item for item in reversed(self.containers) if item[2] == "class"), None)
                attribute = name.split(".", 1)[1]
                if class_container and attribute not in self.stored_attributes.get(class_container[1], frozenset()):
                    target = ref("method", f"{class_container[1]}::{attribute}")
            target = target or self.resolve_name(name, "function")
            if target is None and len(member) == 2:
                self.untyped_calls.add(member[1])
        elif isinstance(node.func, ast.Attribute) and isinstance(node.func.value, ast.Call):
            # `Repo().count()`: the class just instantiated types the receiver,
            # and so does the class a call returns (see `returned_class`). Any
            # other call's result has no type this worker follows.
            held = self.receivers.fresh_instance_class(node.func.value)
            if held is None:
                self.untyped_calls.add(node.func.attr)
            else:
                target = ref("method", f"{held}::{node.func.attr}")
        if (
            name == "getattr"
            and not self.receivers.shadows_builtin("getattr")
            and len(node.args) >= 2
            and isinstance(node.args[1], ast.Constant)
            and isinstance(node.args[1].value, str)
        ):
            # `getattr(editor, "narrowed")` looks a member up by name on a
            # receiver the call leaves untyped.
            self.untyped_calls.add(node.args[1].value)
        if (
            target
            and target.startswith("py:method:")
            and self.is_protocol(target.removeprefix("py:method:").split("::")[0])
        ):
            self.untyped_calls.add(target.rsplit("::", 1)[1])
        # Calling an instance (`repo()`) names no declaration of its own.
        if target and not target.startswith("py:instance:"):
            self.facts.add_edge("calls", self.current(), target, node)
        self.implied_calls(target, node)
        self.fastapi.enrich_call(node, name)
        self.flask.enrich_call(node, name)
        self.django.enrich_call(node, name)
        self.generic_visit(node)

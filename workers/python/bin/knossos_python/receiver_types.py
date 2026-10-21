"""Receiver-type inference: the class a receiver holds, so a call on it names the method that runs."""

import ast
from collections.abc import Callable

from .ast_helpers import dotted, is_none, optional_inner, ref
from .frameworks import DjangoFactEnricher
from .module_index import ProjectModuleIndex


class ReceiverTypeInference:
    """Track what attributes, locals and parameters hold, from local flow only.

    The collector shares its import aliases and its container stack, and
    pushes and pops the per-function stacks below as its walk enters and
    leaves functions. Attributes are keyed by the class that owns them;
    locals by the function being walked. Both are inferences from local flow,
    so a reassignment to anything untracked drops the entry rather than
    letting a stale type attribute a call to the wrong class.
    """

    def __init__(
        self,
        index: ProjectModuleIndex,
        module: str,
        aliases: dict[str, str],
        containers: list[tuple[str, str, str]],
        django: DjangoFactEnricher,
        resolve_name: Callable[[str, str], str | None],
    ) -> None:
        self.index = index
        self.name = module
        self.aliases = aliases
        self.containers = containers
        self.django = django
        self.resolve_name = resolve_name
        self.attribute_types: dict[str, dict[str, str]] = {}
        # What a class body annotates its attributes as (`index: Index`): the
        # declared type, which answers for `self.<name>` whenever no
        # assignment in the class's methods tracked a type of its own.
        self.declared_attributes: dict[str, dict[str, str]] = {}
        self.local_variable_types: list[dict[str, str]] = []
        self.parameter_types: list[dict[str, str]] = []
        # The names each function being walked binds itself (parameters and
        # assignments), which shadow a module-level instance of the same name.
        self.bound_names: list[frozenset[str]] = []

    def annotated_parameters(self, node: ast.FunctionDef | ast.AsyncFunctionDef) -> dict[str, str]:
        """The class each annotated parameter declares it holds."""

        annotated: dict[str, str] = {}
        arguments = node.args
        for argument in [*arguments.posonlyargs, *arguments.args, *arguments.kwonlyargs]:
            if argument.annotation is None:
                continue
            held = self.held_class(None, argument.annotation)
            if held is not None:
                annotated[argument.arg] = held
        return annotated

    def remember_attribute(self, attribute: str, value: ast.AST | None, annotation: ast.AST | None = None) -> None:
        """Record the class an attribute of the enclosing class holds."""

        class_container = next((item for item in reversed(self.containers) if item[2] == "class"), None)
        if class_container is None:
            return
        held = self.held_class(value, annotation)
        owned = self.attribute_types.setdefault(class_container[1], {})
        if held is None:
            owned.pop(attribute, None)
        else:
            owned[attribute] = held

    def declare_class_attributes(self, canonical: str, node: ast.ClassDef) -> None:
        """Record the class each attribute annotated in a class body holds.

        Read before the body is walked, so a method above the annotation
        sees it too. ``index: Index`` and ``index: Index = default`` both
        declare what an instance's ``index`` holds.
        """
        declared: dict[str, str] = {}
        for item in node.body:
            if isinstance(item, ast.AnnAssign) and isinstance(item.target, ast.Name):
                held = self.held_class(item.value, item.annotation)
                if held is not None:
                    declared[item.target.id] = held
        self.declared_attributes[canonical] = declared

    def remember_local(self, variable: str, value: ast.AST | None, annotation: ast.AST | None = None) -> None:
        """Record the class a local variable holds, for the function being walked."""

        if not self.local_variable_types:
            return
        held = self.held_class(value, annotation)
        if held is None:
            self.local_variable_types[-1].pop(variable, None)
            # A parameter reassigned to something untracked no longer holds what
            # its annotation declared. Dropping only the local would leave the
            # annotation to answer for the name and attribute a later call to a
            # class it has lost.
            self.parameter_types[-1].pop(variable, None)
        else:
            self.local_variable_types[-1][variable] = held

    def held_class(self, value: ast.AST | None, annotation: ast.AST | None = None) -> str | None:
        """The class reference an assigned value or annotation names, if any."""

        if isinstance(value, ast.Call) and dotted(value.func) == "self.get_serializer":
            # A REST framework view builds the serializer its class declares.
            view = next((item for item in reversed(self.containers) if item[2] == "class"), None)
            if view is not None and view[1] in self.django.serializer_classes:
                return self.django.serializer_classes[view[1]]
        if isinstance(value, ast.BoolOp) and isinstance(value.op, ast.Or):
            # `injected or Default()`: the attribute holds one class when every
            # side that can be truthy names the same one.
            sides = {self.held_class(side) for side in value.values if not is_none(side)}
            return sides.pop() if len(sides) == 1 else None
        for candidate in (optional_inner(annotation), value.func if isinstance(value, ast.Call) else None):
            name = dotted(candidate) if candidate is not None else None
            resolved = self.resolve_name(name, "class") if name else None
            if resolved and resolved.startswith("py:class:"):
                return resolved.removeprefix("py:class:")
        if isinstance(value, ast.Call):
            returned = self.returned_class(value)
            if returned is not None:
                return returned
        # A name passed straight through carries whatever it is tracked as, from
        # the same two stacks a receiver is resolved against and in the same
        # order, so `other = local` propagates as `other = parameter` does.
        if isinstance(value, ast.Name):
            if self.local_variable_types and value.id in self.local_variable_types[-1]:
                return self.local_variable_types[-1][value.id]
            if self.parameter_types:
                return self.parameter_types[-1].get(value.id)
        return None

    def returned_class(self, call: ast.Call) -> str | None:
        """The project class a call hands back, as the code states it.

        ``load(pk)`` returns what ``def load(pk) -> Document`` annotates, and
        ``repo.find(pk)`` what the method of ``repo``'s class annotates. A
        model manager's lookup returns its model: ``Document.objects.get(...)``.
        """
        model = self.manager_lookup_class(call)
        if model is not None:
            return model
        func = call.func
        if isinstance(func, ast.Attribute) and isinstance(func.value, ast.Call):
            # `Repo().find(pk)` or `load(pk).owner()`: the inner call's class is
            # one it builds or one it returns, read here rather than through
            # `held_class`, which reads it through this.
            held = self.constructed_class(func.value) or self.returned_class(func.value)
            return None if held is None else self.index.return_type(ref("method", f"{held}::{func.attr}"))
        name = dotted(func)
        if name is None:
            return None
        member = name.rsplit(".", 1)
        target = self.receiver_member(member[0], member[1]) if len(member) == 2 else None
        target = target or self.resolve_name(name, "function")
        return None if target is None else self.index.return_type(target)

    def constructed_class(self, call: ast.Call) -> str | None:
        """The project class ``Repo()`` builds, when its name means that class here."""
        callee = dotted(call.func)
        return None if callee is None else self.named_class(callee)

    def manager_lookup_class(self, call: ast.Call) -> str | None:
        """The project model ``<Model>.objects.<lookup>(...)`` returns an instance of, if any."""
        named = self.django.manager_model(call)
        if named is None or self.is_local_name(named.split(".")[0]):
            return None
        resolved = self.resolve_name(named, "class")
        held = resolved.removeprefix("py:class:") if resolved and resolved.startswith("py:class:") else None
        return held if held is not None and self.declares_class(held) else None

    def fresh_instance_class(self, call: ast.Call) -> str | None:
        """The project class ``Repo()`` builds, when its name means that class here.

        A name off a module outside the project (``requests.get(url)``) reads
        as a class only because nothing says otherwise, and a parameter or
        local named like an imported class holds whatever was passed in. A
        call on another call's result, ``Repo().find(pk)``, has no name of its
        own; what the inner call holds types it (see `returned_class`).
        """
        callee = dotted(call.func)
        if callee is not None and self.is_local_name(callee.split(".")[0]):
            return None
        held = self.held_class(call)
        return held if held is not None and self.declares_class(held) else None

    def shadows_builtin(self, name: str) -> bool:
        """Whether this scope, an import or the module binds ``name`` over the builtin."""
        return self.is_local_name(name) or name in self.aliases or name in self.index.module_declarations(self.name)

    def declares_class(self, held: str) -> bool:
        """Whether a project module declares ``held`` as a top-level class."""
        module, _, name = held.rpartition(".")
        return bool(module) and self.index.module_declarations(module).get(name) == ref("class", held)

    def is_local_name(self, name: str) -> bool:
        """Whether the function being walked binds ``name`` itself."""
        return bool(self.bound_names) and name in self.bound_names[-1]

    def receiver_member(self, receiver: str, member: str) -> str | None:
        """The method a call names when its receiver's class is known."""

        held = None
        if receiver.startswith("self.") and receiver.count(".") == 1:
            class_container = next((item for item in reversed(self.containers) if item[2] == "class"), None)
            if class_container is not None:
                attribute = receiver.split(".", 1)[1]
                # An assignment that tracked a type wins over the class body's
                # annotation; one that tracked none leaves the annotation to say.
                held = self.attribute_types.get(class_container[1], {}).get(attribute)
                held = held or self.declared_attributes.get(class_container[1], {}).get(attribute)
        elif "." not in receiver:
            held = self.local_variable_types[-1].get(receiver) if self.local_variable_types else None
            if held is None and self.parameter_types:
                held = self.parameter_types[-1].get(receiver)
            if held is None and not self.is_local_name(receiver):
                # A module-level instance, this module's own or imported.
                instance = self.aliases.get(receiver) or self.index.module_declarations(self.name).get(receiver)
                if instance is not None and instance.startswith("py:instance:"):
                    held = instance.removeprefix("py:instance:")
        if held is None and not receiver.startswith("self."):
            held = self.named_class(receiver)
        return ref("method", f"{held}::{member}") if held else None

    def named_class(self, receiver: str) -> str | None:
        """The project class a receiver names itself: ``Repo`` in ``Repo.create()``.

        A static, class or plain method called through the class, imported
        or this module's own. A name the function binds holds whatever was
        assigned to it, whatever class it is named like.
        """
        if self.is_local_name(receiver.split(".")[0]):
            return None
        resolved = self.resolve_name(receiver, "class")
        held = resolved.removeprefix("py:class:") if resolved and resolved.startswith("py:class:") else None
        return held if held is not None and self.declares_class(held) else None

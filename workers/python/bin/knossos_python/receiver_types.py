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

    # How many generations of bases a class-body annotation is looked up through.
    MAX_BASE_DEPTH = 16

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
        # The project classes each class of this module derives from, nearest
        # first, so a base's or a mixin's annotation types a subclass's
        # `self.<name>` too.
        self.class_bases: dict[str, list[str]] = {}
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

    def declare_class_attributes(self, canonical: str, node: ast.ClassDef, bases: list[str]) -> None:
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
        self.class_bases[canonical] = bases

    def declared_attribute(self, owner: str, attribute: str) -> str | None:
        """The class ``owner`` or the nearest of its bases annotates ``attribute`` as, in its class body.

        A class of this module answers from its own walk; any other from what
        the module index read of its module. Breadth first, nearest base first,
        and bounded so a cycle of bases cannot hang the walk.
        """
        frontier: list[str] = [owner]
        seen: set[str] = set()
        for _ in range(self.MAX_BASE_DEPTH):
            following: list[str] = []
            for held in frontier:
                if held in seen:
                    continue
                seen.add(held)
                if held in self.declared_attributes:
                    declared, bases = self.declared_attributes[held], self.class_bases.get(held, [])
                else:
                    declared, bases = self.index.class_attributes(held)
                if attribute in declared:
                    return declared[attribute]
                following.extend(bases)
            if not following:
                return None
            frontier = following
        return None

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

        parts = receiver.split(".")
        chained = len(parts) > (2 if parts[0] == "self" else 1)
        held = self.attribute_chain_class(parts) if chained else self.receiver_class(receiver)
        if held is None and not receiver.startswith("self."):
            held = self.named_class(receiver)
        return ref("method", f"{held}::{member}") if held else None

    def receiver_class(self, receiver: str) -> str | None:
        """The class ``self.<name>`` or a plain name holds, from the walk's tracking, if any."""

        held = None
        if receiver.startswith("self.") and receiver.count(".") == 1:
            class_container = next((item for item in reversed(self.containers) if item[2] == "class"), None)
            if class_container is not None:
                # An assignment that tracked a type wins over the class body's
                # annotation; one that tracked none leaves the annotation to say.
                held = self.attribute_class(class_container[1], receiver.split(".", 1)[1])
        elif "." not in receiver:
            held = self.local_variable_types[-1].get(receiver) if self.local_variable_types else None
            if held is None and self.parameter_types:
                held = self.parameter_types[-1].get(receiver)
            if held is None and not self.is_local_name(receiver):
                # A module-level instance, this module's own or imported.
                instance = self.aliases.get(receiver) or self.index.module_declarations(self.name).get(receiver)
                if instance is not None and instance.startswith("py:instance:"):
                    held = instance.removeprefix("py:instance:")
        return held

    def attribute_chain_class(self, parts: list[str]) -> str | None:
        """The class ``self.a.b`` or ``a.b`` holds: the head's class, then each attribute's in turn.

        Every link has to be known, from the walk's tracking for a class of
        this module or from what a class declares (its body's annotations, or
        what its ``__init__`` assigns from a constructor call); one link
        unknown leaves the whole chain untyped.
        """
        head = 2 if parts[0] == "self" else 1
        held = self.receiver_class(".".join(parts[:head]))
        for attribute in parts[head:]:
            if held is None:
                return None
            held = self.attribute_class(held, attribute)
        return held

    def attribute_class(self, owner: str, attribute: str) -> str | None:
        """The class ``attribute`` of an ``owner`` instance holds: as tracked in this module, else as declared."""

        return self.attribute_types.get(owner, {}).get(attribute) or self.declared_attribute(owner, attribute)

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

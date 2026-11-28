"""The doubles a test file makes, each with its target, read from the call that makes it.

Each construct names its target its own way: a ``Mock``'s ``spec``, a patch
form's dotted string or object and attribute name, ``monkeypatch.setattr``'s
object or string. Where the double replaces one named member, that member is
recorded whatever the spec (each form raises ``AttributeError`` on a missing
name); ``create=True``, ``raising=False`` and a builtin patched on a module
leave the target unknown. ``new=`` and ``new_callable=`` change what the
double is, and a ``Mock`` passed as ``new=`` becomes the double of the member
it replaces.
"""

from __future__ import annotations

import ast

from ..fixtures import fixture_name
from ..module_index import ProjectModuleIndex
from .bindings import Bindings
from .configuration import Configuration
from .conftests import ConftestFixture, Derive
from .factories import Arguments, Construct, arguments_of, construct_of, is_given, is_true, literal_string
from .file_index import TestFileIndex
from .frozen_names import BUILTINS, MOCK_CLASSES, is_dunder
from .imports import NameResolver
from .paths import is_test_path
from .production_calls import ProductionCalls, is_autouse
from .records import DoubleRecord
from .targets import Located, Owner, TargetResolver


class TestDoubles:
    """Read every double of one test file."""

    def __init__(self, files: TestFileIndex, names: NameResolver, conftests: Derive) -> None:
        self.files = files
        self.names = names
        self.targets = TargetResolver(names, files)
        self.records: dict[int, DoubleRecord] = {}
        self._new: dict[int, ast.expr] = {}
        self.configuration = Configuration(files, names)
        for call in files.calls:
            construct = construct_of(call, files, names)
            if construct is not None:
                record = self._make(call, construct)
                if record is not None:
                    self.records[id(call)] = record
        self.bindings = Bindings(files, names, self.records, conftests)
        self.configuration.bindings = self.bindings
        for record in self.records.values():
            replacement = self._new.get(id(record))
            if replacement is not None:
                self._replace(record, replacement)
        self.configuration.read(self.records)

    def doubles(self) -> list[DoubleRecord]:
        """The file's doubles in source order, those merged into another left out."""
        return [record for record in self.records.values() if record.merged_into is None]

    def _make(self, call: ast.Call, construct: Construct) -> DoubleRecord | None:
        arguments = arguments_of(construct, call)
        record = DoubleRecord(call, construct)
        kind = construct.kind
        if kind in {"mock", "autospec"}:
            self._spec(record, arguments, kind == "autospec")
        elif kind == "patch.multiple":
            self._multiple(record, arguments)
        elif kind == "patch.dict":
            text = literal_string(arguments.owner)
            if text is None:
                return None  # Only the string form names a target: `patch.dict(os.environ, ...)` is a dict.
            self._located(record, arguments, self.targets.dotted(text), "patch.dict")
            record.handle_less = True
            arguments.extra.clear()  # its keywords are the dict's entries
        else:
            self._patch(record, arguments)
        if arguments.spread:
            record.names_open = True
        if record.self_member is not None and is_dunder(record.self_member):
            # Ruling P3: a dunder is no member, and a target without its member would read as the whole owner.
            record.owner = None
        self.configuration.keywords(record, arguments.extra, None)
        return record

    def _spec(self, record: DoubleRecord, arguments: Arguments, autospec: bool) -> None:
        """A ``Mock``'s or ``create_autospec``'s target: its spec, the names it is bound to."""
        keywords = arguments.keywords
        spec = keywords.get("spec_set") if not autospec and "spec_set" in keywords else keywords.get("spec")
        if spec is not None and not (isinstance(spec, ast.Constant) and spec.value is None):
            owner, member = self._spec_owner(spec)
            record.owner, record.self_member = owner, member
            spec_set = (not autospec and "spec_set" in keywords) or (autospec and is_given(keywords.get("spec_set")))
            record.spec = "spec_set" if spec_set else "autospec" if autospec else "spec"
            if member is not None:
                record.name(member, spec.lineno, "attribute")
            if autospec:
                record.automock = record.autospec = True
                record.instance_spec = (
                    owner is not None and owner.kind == "class" and not is_true(keywords.get("instance"))
                )
        record.spy = is_given(keywords.get("wraps"))

    def _spec_owner(self, spec: ast.expr) -> tuple[Owner | None, str | None]:
        """What a spec object is: a class, module or instance, or a function, named as a member of its module."""
        resolved = self.names.resolve(spec)
        if resolved is not None and resolved.symbol is not None and resolved.symbol.startswith("py:function:"):
            module, _, name = resolved.symbol.removeprefix("py:function:").rpartition(".")
            return Owner("module", module, module), name
        if isinstance(spec, (ast.List, ast.Tuple, ast.Constant)):
            return None, None
        return self.targets.of_object(spec), None

    def _patch(self, record: DoubleRecord, arguments: Arguments) -> None:
        """``patch``, ``patch.object``, ``mocker.spy`` and ``monkeypatch.setattr``: one member replaced."""
        kind = record.construct.kind
        text = literal_string(arguments.owner)
        if kind == "patch" or (kind == "setattr" and arguments.member is None and text is not None):
            located = None if text is None else self.targets.dotted(text)
        else:
            owner = None if arguments.owner is None else self.targets.of_object(arguments.owner)
            member = literal_string(arguments.member)
            located = None if owner is None or member is None else self.targets.attribute(owner, member)
            if located is None and member is not None:
                record.self_member = member
                record.name(member, arguments.member.lineno if arguments.member else record.node.lineno, _api(kind))
        api = _api(kind)
        self._located(record, arguments, located, api)
        keywords = arguments.keywords
        if kind == "spy":
            record.automock = record.autospec = record.spy = True
            record.receiver = record.owner is not None and record.owner.kind == "class"
            return
        if kind == "setattr":
            if isinstance(keywords.get("raising"), ast.Constant) and not is_given(keywords.get("raising")):
                record.owner = None
            record.handle_less = True
        else:
            self._patch_spec(record, keywords, located)
        if arguments.new is not None:
            self._new[id(record)] = arguments.new
            record.handle_less = True

    def _patch_spec(self, record: DoubleRecord, keywords: dict[str, ast.expr], located: Located | None) -> None:
        if is_given(keywords.get("create")):
            record.owner = None
        if (
            located is not None
            and record.owner is not None
            and record.owner.kind == "module"
            and located.member in BUILTINS
            and located.declared is None
        ):
            # `patch` creates a builtin's name on a module (`mock._builtins`), as `create=True` would.
            record.owner = None
        autospec, spec, spec_set = (keywords.get(key) for key in ("autospec", "spec", "spec_set"))
        if is_given(autospec):
            record.automock = record.autospec = True
        whole = is_true(autospec) or is_true(spec) or is_true(spec_set)
        if whole or is_given(autospec):
            record.spec = "spec_set" if is_true(spec_set) else "autospec" if is_given(autospec) else "spec"
        declared = None if located is None else located.declared
        if whole and record.owner is not None and declared is not None and declared.startswith("py:class:"):
            # `patch("m.Repo", autospec=True)`: the mock is the class itself, with its names and an instance's.
            record.members.pop(record.self_member or "", None)
            record.owner = Owner("class", declared.removeprefix("py:class:"), record.owner.specifier)
            record.self_member = None
            record.instance_spec = True
        record.receiver = is_true(autospec) and record.owner is not None and record.owner.kind == "class"
        callable_ = keywords.get("new_callable")
        if callable_ is not None:
            resolved = self.names.resolve(callable_)
            name = "" if resolved is None else resolved.qualified.rsplit(".", 1)[-1]
            record.handle_less = resolved is None or name not in MOCK_CLASSES

    def _located(self, record: DoubleRecord, arguments: Arguments, located: Located | None, api: str) -> None:
        if located is None:
            return
        record.owner = located.owner
        record.self_member = located.member
        if located.member is not None:
            line = (arguments.member or arguments.owner or record.node).lineno
            record.name(located.member, line, api)

    def _multiple(self, record: DoubleRecord, arguments: Arguments) -> None:
        """``patch.multiple(target, a=DEFAULT, b=2)``: each keyword names a member of the target."""
        text = literal_string(arguments.owner)
        if text is not None:
            record.owner = self.targets.whole(text)
        elif arguments.owner is not None:
            record.owner = self.targets.of_object(arguments.owner)
        keywords = arguments.keywords
        if is_given(keywords.get("create")):
            record.owner = None
        if is_given(keywords.get("autospec")):
            record.automock = record.autospec = True
        record.handle_less = True
        configuration: list[ast.keyword] = []
        for keyword in arguments.extra:
            if keyword.arg is None:
                record.names_open = True
                continue
            if record.owner is not None and record.owner.kind == "module" and keyword.arg in BUILTINS:
                continue
            record.name(keyword.arg, keyword.value.lineno, "patch.multiple")
            configuration.append(keyword)
        self.configuration.replacements(record, configuration)
        arguments.extra.clear()

    def _replace(self, record: DoubleRecord, replacement: ast.expr) -> None:
        """What ``new=`` (or ``monkeypatch.setattr``'s value) puts in place of the member.

        An unspec'd ``Mock``, made inline or bound, becomes this double's mock
        of the member; a literal or a replacement callable pins its value.
        """
        value = self.bindings.value_of(replacement)
        if value is not None and not value.patcher:
            double = value.handle.record
            if double is not record and double.spec == "none" and double.owner is None and double.self_member is None:
                record.merged_into = double
                double.owner, double.self_member = record.owner, record.self_member
                double.members = {**record.members, **double.members}
                double.handle_less = False
            return
        self.configuration.replacement(record, None, replacement)


def _api(kind: str) -> str:
    return {"spy": "spy", "setattr": "setattr"}.get(kind, kind)


def read_conftest(index: ProjectModuleIndex, relative: str, tree: ast.Module) -> dict[str, ConftestFixture]:
    """Each fixture of the conftest at ``relative``, by name: the double it hands over, and what it runs.

    Handed down to :mod:`.conftests` through :class:`TestDoubles` and
    :class:`Bindings`, whose readers are the ones a conftest is read with.
    """
    files = TestFileIndex(tree)
    names = NameResolver(index, files, relative, index.layout.module_for(relative))
    doubles = TestDoubles(files, names, read_conftest)
    production = ProductionCalls(files, doubles.names, doubles.bindings, doubles.records)
    fixtures: dict[str, ConftestFixture] = {}
    for function in files.functions:
        name = fixture_name(function)
        if name is None or files.scope_of(function) is not files.module_scope:
            continue
        handle = doubles.bindings.fixture(id(tree), name)
        # A root conftest is no test module, so the core holds no node for its doubles.
        named = handle is not None and is_test_path(relative)
        double = handle.record.canonical(relative) if handle is not None and named else ""
        reach = production.local_fixture(function)
        fixtures[name] = ConftestFixture(double, reach.calls, tuple(sorted(reach.subjects)), is_autouse(function))
    return fixtures

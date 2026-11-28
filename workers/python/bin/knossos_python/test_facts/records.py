"""One test double as the worker collects it, and its ``test_double`` attributes in the wire shape.

What the test configures is kept as it is found, each pin, matcher and
replacement signature naming its member, or no member for the double's own
call. The wire members are built from them at the end, once every double has
its final target: a ``Mock`` passed as ``new=`` only learns the member it
stands for after its own keywords were read.
"""

from __future__ import annotations

import ast
from dataclasses import dataclass, field
from typing import Any

from .factories import Construct
from .frozen_names import is_dunder
from .targets import Owner


@dataclass(eq=False)
class Member:
    """A member of the double's target the test names, with the api it is named through."""

    name: str
    line: int
    api: str


@dataclass(eq=False)
class Pin:
    """A value configured on ``member`` (None for the double's own call)."""

    member: str | None
    api: str
    line: int
    once: bool
    type: str
    value: ast.AST | None
    # Configured through a handle held in `self.` or a fixture, which code the file does not show may swap:
    # the pin is a type, never a value a test case may read back (the R14 analog, Decision 5).
    held: bool = False

    def wire(self) -> dict[str, Any]:
        return {
            "api": self.api,
            "line": self.line,
            "once": self.once,
            "assignable": "unknown",
            "typed": self.type != "unknown",
            "type": self.type,
        }


@dataclass(eq=False)
class DoubleRecord:
    """A double made at ``node``.

    ``owner`` is what it stands in for, ``self_member`` the member it
    replaces when it replaces one (a patch form's name), which a handle's
    own ``return_value`` and call assertions configure. ``spec`` says how
    closely the double is bound to its owner's names: only a spec'd double
    records the names read through it (Decision 2), and only ``spec_set``
    those written. ``instance_spec`` is whether ``.return_value`` is an
    instance with the same spec (an autospec'd class). ``receiver`` marks a
    double whose recorded calls include the instance (Decision 4), and
    ``autospec`` one whose replacement signatures say nothing, since the
    real signature is enforced instead.
    """

    node: ast.Call
    construct: Construct
    owner: Owner | None = None
    self_member: str | None = None
    spec: str = "none"
    instance_spec: bool = False
    receiver: bool = False
    automock: bool = False
    autospec: bool = False
    spy: bool = False
    # The call hands back no mock: `new=` a value, `new_callable=` something else, a dict.
    handle_less: bool = False
    # Merged into another record, which stands for both (a Mock passed as `new=`).
    merged_into: DoubleRecord | None = None
    # Names a plain `spec` double answers that are not its owner's: written on it in the test.
    written: set[str] = field(default_factory=set)
    # Names may be written on it out of sight (`configure_mock(**kw)`, `setattr(m, name, v)`).
    names_open: bool = False
    members: dict[str, Member] = field(default_factory=dict)
    pins: list[Pin] = field(default_factory=list)
    matchers: list[tuple[str | None, dict[str, Any]]] = field(default_factory=list)
    params: list[tuple[str | None, list[dict[str, Any]]]] = field(default_factory=list)
    sites: int = 0

    def resolved(self) -> DoubleRecord:
        record = self
        while record.merged_into is not None:
            record = record.merged_into
        return record

    @property
    def records_names(self) -> bool:
        """Whether names read through this double are its owner's members (Decision 2)."""
        return self.spec != "none" and not self.names_open

    def name(self, name: str, line: int, api: str) -> None:
        """Record ``name`` as a member on first sight; never a dunder (Ruling P3)."""
        if not is_dunder(name) and name not in self.members:
            self.members[name] = Member(name, line, api)

    def canonical(self, relative: str) -> str:
        return f"{relative}#double:{self.node.lineno}:{self.node.col_offset + 1}"

    def target(self) -> dict[str, str]:
        if self.owner is None:
            return {"kind": "unknown"}
        return self.owner.wire(None if self.self_member is None or is_dunder(self.self_member) else self.self_member)

    def display(self) -> str:
        subject = "" if self.owner is None else self.owner.name
        if self.self_member is not None:
            subject = f"{subject}.{self.self_member}" if subject else self.self_member
        return f"{self.construct.pattern} {subject}".rstrip()

    def attributes(self) -> dict[str, Any]:
        return {
            "framework": self.construct.framework,
            "pattern": self.construct.pattern,
            "target": self.target(),
            "automock": self.automock,
            "invocations": self.sites,
            "members": self._wire_members(),
        }

    def _wire_members(self) -> list[dict[str, Any]]:
        returns: dict[str, list[dict[str, Any]]] = {}
        matchers: dict[str, list[dict[str, Any]]] = {}
        params: dict[str, list[dict[str, Any]]] = {}
        for pin in self.pins:
            named = pin.member or self.self_member
            if named is not None and named in self.members:
                returns.setdefault(named, []).append(pin.wire())
        for configured, matcher in self.matchers:
            named = configured or self.self_member
            if named is not None and named in self.members:
                matchers.setdefault(named, []).append(matcher)
        for configured, signature in self.params:
            named = configured or self.self_member
            if named is not None and named in self.members:
                params.setdefault(named, signature)
        members: list[dict[str, Any]] = []
        for name, member in self.members.items():
            if member.api in {"attribute", "assigned"} and (not self.records_names or name in self.written):
                # A name read through a double whose names are open, or written on it first, says nothing of the owner.
                continue
            wire: dict[str, Any] = {"name": name, "line": member.line, "api": member.api}
            if name in params:
                wire["params"] = params[name]
            if name in matchers:
                wire["called_with"] = matchers[name]
            wire["returns"] = returns.get(name, [])
            members.append(wire)
        return members

"""A dataclass, pydantic model or TypedDict lists its fields and an Enum its cases, open when a key may differ."""

from __future__ import annotations

import sys
from typing import Any

import pytest

PYDANTIC = "from pydantic import BaseModel, ConfigDict, Field\n"
DATACLASS = "from dataclasses import InitVar, KW_ONLY, dataclass, field\nfrom typing import ClassVar\n"
TYPED = "from typing import NotRequired, Required, TypedDict\n"
ENUM = "import enum\nfrom enum import Enum, IntEnum, auto\n"


def _class(project, scan_collect, source: str, name: str = "C", files: dict[str, str] | None = None) -> dict[str, Any]:
    contributions = scan_collect(project({**(files or {}), "m.py": source}), [*(files or {}), "m.py"])
    [contribution] = [each for each in contributions if each["owner_key"].endswith(":m.py")]
    nodes = {node["canonical_name"]: node["attributes"] for node in contribution["nodes"] if node["kind"] == "class"}
    return nodes[f"m.{name}"]


def _fields(attributes: dict[str, Any]) -> dict[str, tuple[str | None, bool]]:
    return {field["name"]: (field.get("type"), field["required"]) for field in attributes["fields"]}


def test_a_pydantic_model_lists_its_annotations_sorted_with_type_line_and_requiredness(project, scan_collect) -> None:
    source = (
        PYDANTIC
        + 'class C(BaseModel):\n    """Doc."""\n    name: str\n'
        + "    age: int = 3\n    tags: list[str] = Field(default_factory=list)\n"
    )
    attributes = _class(project, scan_collect, source)

    assert attributes["fields"] == [
        {"name": "age", "type": "int", "required": False, "line": 5},
        {"name": "name", "type": "str", "required": True, "line": 4},
        {"name": "tags", "type": "list[str]", "required": False, "line": 6},
    ]
    assert "fields_open" not in attributes


def test_a_pydantic_field_call_without_a_default_is_required(project, scan_collect) -> None:
    source = (
        PYDANTIC
        + "from typing import Annotated, Optional\n"
        + "class C(BaseModel):\n"
        + "    a: int = Field(...)\n"
        + "    b: int = Field()\n"
        + "    c: int = Field(5)\n"
        + "    d: int = ...\n"
        + "    e: Annotated[int, Field(gt=0)]\n"
        + "    f: Annotated[int, Field(default=1)]\n"
        + "    g: Optional[int]\n"
        + "    h: Optional[int] = None\n"
    )
    fields = _fields(_class(project, scan_collect, source))

    assert {name: required for name, (_, required) in fields.items()} == {
        "a": True,
        "b": True,
        "c": False,
        "d": True,
        "e": True,
        "f": False,
        "g": True,
        "h": False,
    }


def test_a_pydantic_private_attribute_and_class_var_are_not_fields(project, scan_collect) -> None:
    source = (
        PYDANTIC
        + "from typing import ClassVar\nclass C(BaseModel):\n"
        + "    _cache: dict = {}\n    kind: ClassVar[str] = 'c'\n    id: int\n"
    )
    attributes = _class(project, scan_collect, source)

    assert _fields(attributes) == {"id": ("int", True)}
    assert "fields_open" not in attributes


@pytest.mark.parametrize(
    "line",
    [
        "    id: int = Field(alias='ID')",
        "    id: int = Field(validation_alias='ID')",
        "    id: int = Field(serialization_alias='ID')",
        "    id: Annotated[int, Field(alias='ID')]",
        "    id: int = Field(validation_alias=AliasChoices('id', 'ID'))",
        "    id: int = Field(**options)",
        "    model_config = ConfigDict(populate_by_name=True)\n    id: int",
        "    model_config = ConfigDict(alias_generator=to_camel)\n    id: int",
        "    class Config:\n        allow_population_by_field_name = True\n    id: int",
        "    id: int\n    @model_validator(mode='before')\n    def rename(cls, data): return data",
        "    id: int\n    @computed_field\n    def total(self) -> int: return 1",
        "    id: int\n    def __init__(self, **data): super().__init__(**data)",
        "    if FLAG:\n        id: int",
        "    id: 'ClassVar[int'",
    ],
)
def test_a_pydantic_model_whose_wire_names_may_differ_is_open(project, scan_collect, line: str) -> None:
    source = (
        PYDANTIC
        + "from typing import Annotated\nfrom pydantic import AliasChoices, computed_field, model_validator\n"
        + "options = {}\nFLAG = True\ndef to_camel(name): return name\n"
        + f"class C(BaseModel):\n{line}\n"
    )

    assert _class(project, scan_collect, source).get("fields_open") is True


@pytest.mark.parametrize(
    "header",
    [
        "class C(BaseModel, extra='forbid'):",
        "class C(BaseModel, metaclass=Meta):",
        "@register\nclass C(BaseModel):",
        "class C(BaseModel, Other):",
        "class C(BaseModel, Base[int]):",
        "class C(BaseModel, Missing):",
    ],
)
def test_a_pydantic_header_that_may_add_fields_or_rename_is_open(project, scan_collect, header: str) -> None:
    source = (
        PYDANTIC
        + "from sqlalchemy import Other\nclass Meta(type): pass\ndef register(c): return c\n"
        + "class Base: pass\n"
        + f"{header}\n    id: int\n"
    )

    assert _class(project, scan_collect, source).get("fields_open") is True


def test_a_pydantic_generic_model_stays_closed(project, scan_collect) -> None:
    source = (
        PYDANTIC
        + "from typing import Generic, TypeVar\nT = TypeVar('T')\nclass C(BaseModel, Generic[T]):\n    item: T\n"
    )
    attributes = _class(project, scan_collect, source)

    assert _fields(attributes) == {"item": ("T", True)}
    assert "fields_open" not in attributes


def test_a_subclass_of_a_same_file_model_sends_its_own_fields_and_the_core_composes(project, scan_collect) -> None:
    source = PYDANTIC + "class Base(BaseModel):\n    id: int\nclass C(Base):\n    name: str\n"
    attributes = _class(project, scan_collect, source)

    assert _fields(attributes) == {"name": ("str", True)}
    assert "fields_open" not in attributes


def test_a_subclass_with_the_config_opens_and_its_base_stays_closed(project, scan_collect) -> None:
    source = (
        PYDANTIC
        + "class Base(BaseModel):\n    user_id: int\n"
        + "class C(Base):\n    model_config = ConfigDict(alias_generator=str.upper)\n"
    )

    assert _class(project, scan_collect, source).get("fields_open") is True
    assert "fields_open" not in _class(project, scan_collect, source, "Base")


def test_a_base_with_an_alias_opens_the_base(project, scan_collect) -> None:
    source = (
        PYDANTIC + "class Base(BaseModel):\n    user_id: int = Field(alias='userId')\nclass C(Base):\n    name: str\n"
    )

    assert _class(project, scan_collect, source, "Base").get("fields_open") is True


def test_a_subclass_of_a_class_in_another_file_sends_no_list(project, scan_collect) -> None:
    # The base may be a dataclass, whose subclass's annotations are no fields: its kind is not known here.
    files = {"base.py": PYDANTIC + "class Base(BaseModel):\n    id: int\n"}
    attributes = _class(project, scan_collect, "from base import Base\nclass C(Base):\n    name: str\n", files=files)

    assert "fields" not in attributes


def test_a_plain_class_sends_no_list(project, scan_collect) -> None:
    attributes = _class(
        project, scan_collect, "class C:\n    id: int\n    def __init__(self):\n        self.extra = 1\n"
    )

    assert "fields" not in attributes
    assert "cases" not in attributes


def test_self_writes_are_not_fields(project, scan_collect) -> None:
    source = DATACLASS + "@dataclass\nclass C:\n    id: int\n    def touch(self):\n        self.seen = True\n"
    attributes = _class(project, scan_collect, source)

    assert _fields(attributes) == {"id": ("int", True)}
    assert "fields_open" not in attributes


def test_an_undecorated_subclass_of_a_dataclass_sends_no_list(project, scan_collect) -> None:
    source = DATACLASS + "@dataclass\nclass Base:\n    id: int\nclass C(Base):\n    name: str\n"

    assert "fields" not in _class(project, scan_collect, source)


def test_a_dataclass_lists_fields_and_its_defaults(project, scan_collect) -> None:
    source = (
        DATACLASS
        + "@dataclass(frozen=True, kw_only=True)\n"
        + "class C:\n"
        + "    a: int\n"
        + "    b: int = 2\n"
        + "    c: list = field(default_factory=list)\n"
        + "    d: int = field(default=4)\n"
        + "    e: int = field()\n"
        + "    kind: ClassVar[str] = 'c'\n"
        + "    _: KW_ONLY\n"
        + "    _private: int = 0\n"
    )
    attributes = _class(project, scan_collect, source)

    assert {name: required for name, (_, required) in _fields(attributes).items()} == {
        "_private": False,
        "a": True,
        "b": False,
        "c": False,
        "d": False,
        "e": True,
    }
    assert "fields_open" not in attributes


@pytest.mark.parametrize(
    "line",
    [
        "    a: int = field(metadata={'name': 'A'})",
        "    a: int = field(init=False)",
        "    a: InitVar[int]",
        "    a: int\n    def __init__(self, a): self.a = a",
    ],
)
def test_a_dataclass_field_the_constructor_may_not_take_by_its_name_opens(project, scan_collect, line: str) -> None:
    source = DATACLASS + f"@dataclass\nclass C:\n{line}\n"

    assert _class(project, scan_collect, source).get("fields_open") is True


@pytest.mark.parametrize(
    "header",
    [
        "@dataclass(init=False)\nclass C:",
        "@dataclass\n@register\nclass C:",
        "@dataclass\nclass C(External):",
    ],
)
def test_a_dataclass_header_that_may_rewrite_the_constructor_opens(project, scan_collect, header: str) -> None:
    source = (
        DATACLASS
        + "from attrs import define\nfrom pydantic.dataclasses import dataclass as pydantic_dataclass\n"
        + "from vendor import External\ndef register(c): return c\n"
        + f"{header}\n    a: int\n"
    )

    assert _class(project, scan_collect, source).get("fields_open") is True


@pytest.mark.parametrize("decorator", ["@define", "@attr.s", "@pydantic_dataclass"])
def test_another_libraries_dataclass_sends_no_list(project, scan_collect, decorator: str) -> None:
    source = (
        "import attr\nfrom attrs import define\nfrom pydantic.dataclasses import dataclass as pydantic_dataclass\n"
        + f"{decorator}\nclass C:\n    a: int\n"
    )

    assert "fields" not in _class(project, scan_collect, source)


def test_a_dataclass_over_a_model_is_no_list(project, scan_collect) -> None:
    source = DATACLASS + PYDANTIC + "@dataclass\nclass C(BaseModel):\n    a: int\n"

    assert "fields" not in _class(project, scan_collect, source)


def test_a_typed_dict_reads_totality_and_its_markers(project, scan_collect) -> None:
    source = (
        TYPED
        + "from typing import Annotated\n"
        + "class C(TypedDict, total=False):\n"
        + "    a: int\n"
        + "    b: Required[int]\n"
        + "    c: Annotated[Required[int], 'doc']\n"
        + "class D(TypedDict):\n"
        + "    a: int\n"
        + "    b: NotRequired[int]\n"
        + "    c: 'NotRequired[str]'\n"
    )
    total_false = _class(project, scan_collect, source)
    total = _class(project, scan_collect, source, "D")

    assert {name: required for name, (_, required) in _fields(total_false).items()} == {
        "a": False,
        "b": True,
        "c": True,
    }
    assert {name: required for name, (_, required) in _fields(total).items()} == {"a": True, "b": False, "c": False}
    assert "fields_open" not in total_false
    assert "fields_open" not in total


def test_a_typed_dict_with_another_class_keyword_is_open(project, scan_collect) -> None:
    source = TYPED + "class C(TypedDict, closed=True):\n    a: int\n"

    assert _class(project, scan_collect, source).get("fields_open") is True


def test_a_functional_typed_dict_and_a_named_tuple_send_no_list(project, scan_collect) -> None:
    source = TYPED + "from typing import NamedTuple\nC = TypedDict('C', {'a': int})\nclass N(NamedTuple):\n    a: int\n"
    [contribution] = scan_collect(project({"m.py": source}), ["m.py"])
    nodes = {node["canonical_name"]: node["attributes"] for node in contribution["nodes"]}

    assert "fields" not in nodes.get("m.C", {})
    assert "fields" not in nodes["m.N"]


@pytest.mark.parametrize(
    "tail",
    [
        "C.extra = 1",
        "register(C)",
        "ALL = [C]",
        "globals()['D'] = 1",
    ],
)
def test_the_phase_4_causes_open_the_list(project, scan_collect, tail: str) -> None:
    source = DATACLASS + "def register(c): pass\n@dataclass\nclass C:\n    a: int\n" + tail + "\n"

    assert _class(project, scan_collect, source).get("fields_open") is True


def test_a_magic_getattr_leaves_the_list_closed(project, scan_collect) -> None:
    source = DATACLASS + "@dataclass\nclass C:\n    a: int\n    def __getattr__(self, name): return None\n"
    attributes = _class(project, scan_collect, source)

    assert _fields(attributes) == {"a": ("int", True)}
    assert "fields_open" not in attributes
    assert attributes["member_names_open"] is True


def test_an_init_subclass_hook_opens_the_list(project, scan_collect) -> None:
    source = DATACLASS + "@dataclass\nclass C:\n    a: int\n    def __init_subclass__(cls): pass\n"

    assert _class(project, scan_collect, source).get("fields_open") is True


def test_two_declarations_of_one_class_open_the_list(project, scan_collect) -> None:
    source = (
        DATACLASS
        + "import sys\nif sys.version_info >= (3, 11):\n    class C:\n        pass\n"
        + "else:\n    @dataclass\n    class C:\n        a: int\n"
    )

    assert _class(project, scan_collect, source).get("fields_open") is True


def test_two_declarations_of_one_enum_send_no_cases(project, scan_collect) -> None:
    source = (
        ENUM
        + "import sys\nif sys.version_info >= (3, 11):\n    class C(Enum):\n        A = 1\n"
        + "else:\n    class C(Enum):\n        B = 2\n"
    )

    assert "cases" not in _class(project, scan_collect, source)


def test_a_long_list_is_cut_and_marked(project, scan_collect) -> None:
    body = "".join(f"    f{index:03}: int\n" for index in range(70))
    attributes = _class(project, scan_collect, DATACLASS + "@dataclass\nclass C:\n" + body)

    assert len(attributes["fields"]) == 64
    assert attributes["fields"][-1]["name"] == "f063"
    assert attributes["fields_truncated"] is True


def test_an_enum_lists_its_cases_with_literal_values(project, scan_collect) -> None:
    source = (
        ENUM
        + 'class C(str, Enum):\n    """Doc."""\n    RED = \'red\'\n    BLUE = \'blue\'\n'
        + "    _order_ = 'RED BLUE'\n    _hidden = 'h'\n    label: str\n    def label(self): return self.value\n"
    )
    attributes = _class(project, scan_collect, source)

    assert attributes["cases"] == [
        {"name": "BLUE", "value": "blue"},
        {"name": "RED", "value": "red"},
        {"name": "_hidden", "value": "h"},
    ]
    assert "fields" not in attributes


def test_an_int_enum_gives_signed_integer_values(project, scan_collect) -> None:
    source = ENUM + "class C(IntEnum):\n    LOW = -1\n    HIGH = 2\n"

    assert _class(project, scan_collect, source)["cases"] == [
        {"name": "HIGH", "value": "2"},
        {"name": "LOW", "value": "-1"},
    ]


@pytest.mark.parametrize(
    "body",
    [
        "    A = auto()\n    B = auto()",
        "    A = 1\n    B = 1 << 1",
        "    A = 1.5\n    B = 2.5",
        "    A = 1\n    B = 2\n    @classmethod\n    def _missing_(cls, value): return cls.A",
    ],
)
def test_an_enum_with_a_value_that_is_not_a_literal_gives_none(project, scan_collect, body: str) -> None:
    attributes = _class(project, scan_collect, ENUM + f"class C(enum.Enum):\n{body}\n")

    assert [case["name"] for case in attributes["cases"]] == ["A", "B"]
    assert all("value" not in case for case in attributes["cases"])


@pytest.mark.parametrize(
    "source",
    [
        ENUM + "@register\nclass C(Enum):\n    A = 1\n",
        ENUM + "class C(Enum):\n    A, B = 1, 2\n",
        ENUM + "class C(Enum):\n    _ignore_ = ['x']\n    A = 1\n",
        ENUM + "class C(Base, Enum):\n    A = 1\n",
        ENUM + "class C(Enum):\n    if FLAG:\n        A = 1\n",
        ENUM + "class C(Enum):\n    __private = 1\n    A = 1\n",
        ENUM + "class C(Enum, boundary=1):\n    A = 1\n",
    ],
)
def test_an_enum_whose_members_may_differ_sends_no_cases(project, scan_collect, source: str) -> None:
    attributes = _class(project, scan_collect, "from x import Base, register\nFLAG = 1\n" + source)

    assert "cases" not in attributes
    assert "fields" not in attributes


ALIASED = "from typing import Annotated\nAliasedId = Annotated[int, Field(alias='userId')]\n"


@pytest.mark.parametrize(
    "body",
    [
        ALIASED + "class C(BaseModel):\n    user_id: AliasedId\n",
        ALIASED + "class C(BaseModel):\n    user_id: 'AliasedId'\n",
        ALIASED + "class C(BaseModel):\n    user_id: list[AliasedId]\n",
        ALIASED + "class C(BaseModel):\n    user_id: 'list[AliasedId] | None' = None\n",
        ALIASED + "class C(BaseModel):\n    user_id: Annotated[int, ID_FIELD]\nID_FIELD = Field(alias='userId')\n",
        "ID_FIELD = Field(alias='userId')\nclass C(BaseModel):\n    user_id: int = ID_FIELD\n",
        "class C(BaseModel):\n    user_id: Missing\n",
        "LIMIT: int = 3\nclass C(BaseModel):\n    user_id: int = LIMIT\n",
        "class C(BaseModel):\n    user_id: list['Owner[']\n",
        "def AliasedId(): pass\nclass C(BaseModel):\n    user_id: AliasedId\n",
    ],
)
def test_a_field_whose_type_or_value_is_a_name_that_may_hold_an_alias_opens(project, scan_collect, body: str) -> None:
    source = PYDANTIC + body

    assert _class(project, scan_collect, source).get("fields_open") is True


@pytest.mark.skipif(sys.version_info < (3, 12), reason="`type X = …` parses from 3.12")
def test_a_type_statement_alias_opens(project, scan_collect) -> None:
    source = PYDANTIC + "from typing import Annotated\ntype AliasedId = Annotated[int, Field(alias='userId')]\n"
    source += "class C(BaseModel):\n    user_id: AliasedId\n"

    assert _class(project, scan_collect, source).get("fields_open") is True


@pytest.mark.parametrize(
    "imported",
    [
        "from aliases import AliasedId\nclass C(BaseModel):\n    user_id: AliasedId\n",
        "import aliases\nclass C(BaseModel):\n    user_id: aliases.AliasedId\n",
        "from aliases import ID_FIELD\nclass C(BaseModel):\n    user_id: int = ID_FIELD\n",
        "import aliases\nclass C(BaseModel):\n    user_id: aliases\n",
    ],
)
def test_an_alias_imported_from_another_project_file_opens(project, scan_collect, imported: str) -> None:
    files = {"aliases.py": PYDANTIC + ALIASED + "ID_FIELD = Field(alias='userId')\nclass Shared:\n    pass\n"}

    assert _class(project, scan_collect, PYDANTIC + imported, files=files).get("fields_open") is True


def test_names_that_are_types_keep_the_list_closed(project, scan_collect) -> None:
    files = {"shared.py": "class Shared:\n    pass\n"}
    source = (
        PYDANTIC
        + "import datetime\nimport typing\nfrom decimal import Decimal\nfrom enum import Enum\n"
        + "from typing import Annotated, Literal, NewType, Optional, TypeVar\nfrom shared import Shared\n"
        + "T = TypeVar('T')\nUserId = NewType('UserId', int)\n"
        + "class Color(Enum):\n    RED = 'r'\n"
        + "class Owner:\n    pass\n"
        + "class C(BaseModel):\n"
        + "    a: Optional[int]\n"
        + "    b: Literal['x', 'y']\n"
        + "    c: list['Owner'] = []\n"
        + "    d: Annotated[int, 'a doc string']\n"
        + "    e: Color = Color.RED\n"
        + "    f: UserId\n"
        + "    g: datetime.datetime\n"
        + "    h: typing.Optional[Decimal] = None\n"
        + "    i: Shared\n"
        + "    j: dict[str, list[T]] = {}\n"
        + "    k: 'Owner | None' = None\n"
    )
    attributes = _class(project, scan_collect, source, files=files)

    assert "fields_open" not in attributes, attributes
    assert len(attributes["fields"]) == 11


@pytest.mark.parametrize(
    ("source", "name"),
    [
        (ENUM + "class C(Enum):\n    A = 'a'\n    def __init__(self, v):\n        self._value_ = v.upper()\n", "C"),
        (
            ENUM
            + "class NewBase(Enum):\n    def __new__(cls, v):\n        obj = object.__new__(cls)\n"
            + "        obj._value_ = v * 2\n        return obj\nclass C(NewBase):\n    A = 1\n",
            "C",
        ),
        (
            ENUM
            + "class MissBase(Enum):\n    @classmethod\n    def _missing_(cls, value):\n        return None\n"
            + "class C(MissBase):\n    A = 'a'\n",
            "C",
        ),
        (
            ENUM
            + "class UpperMixin(str):\n    def __new__(cls, v):\n        return str.__new__(cls, v.upper())\n"
            + "class C(UpperMixin, Enum):\n    A = 'a'\n",
            "C",
        ),
    ],
)
def test_an_enum_whose_lineage_may_rewrite_values_sends_none(project, scan_collect, source: str, name: str) -> None:
    attributes = _class(project, scan_collect, source, name)

    assert attributes["cases"] == [{"name": "A"}]


def test_an_enum_over_a_same_file_base_that_opens_sends_no_cases(project, scan_collect) -> None:
    source = ENUM + "class Base(Enum):\n    for _ in range(1):\n        pass\nclass C(Base):\n    A = 'a'\n"

    assert "cases" not in _class(project, scan_collect, source)


def test_an_enum_reaching_one_mixin_twice_keeps_its_values(project, scan_collect) -> None:
    source = ENUM + "class A(str):\n    pass\nclass B(A):\n    pass\nclass C(B, A, Enum):\n    X = 'x'\n"

    assert _class(project, scan_collect, source)["cases"] == [{"name": "X", "value": "x"}]

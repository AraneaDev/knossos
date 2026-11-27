"""A pydantic validator before the type check drops a field's type; pydantic v1 fills an Optional without a default."""

from __future__ import annotations

from typing import Any

import pytest

V2 = "from pydantic import BaseModel, field_validator\n"


def _class(project, scan_collect, source: str, name: str = "C") -> dict[str, Any]:
    [contribution] = [each for each in scan_collect(project({"m.py": source}), ["m.py"])]
    nodes = {node["canonical_name"]: node["attributes"] for node in contribution["nodes"] if node["kind"] == "class"}
    return nodes[f"m.{name}"]


def _typed(attributes: dict[str, Any]) -> dict[str, str | None]:
    return {field["name"]: field.get("type") for field in attributes["fields"]}


def _required(attributes: dict[str, Any]) -> dict[str, bool]:
    return {field["name"]: field["required"] for field in attributes["fields"]}


def test_a_before_validator_drops_the_type_of_the_field_it_names_and_keeps_the_list(project, scan_collect) -> None:
    source = (
        V2
        + "from typing import Optional\n"
        + "class C(BaseModel):\n"
        + "    order_id: int\n    tags: list[str]\n    note: Optional[str]\n"
        + "    @field_validator('tags', mode='before')\n    @classmethod\n"
        + "    def split(cls, value): return value.split(',') if isinstance(value, str) else value\n"
    )
    attributes = _class(project, scan_collect, source)

    assert _typed(attributes) == {"note": "Optional[str]", "order_id": "int", "tags": None}
    assert _required(attributes) == {"note": True, "order_id": True, "tags": True}
    assert "fields_open" not in attributes


@pytest.mark.parametrize(
    "decorator",
    [
        "@field_validator('a', 'b', mode='before')",
        "@field_validator('a', 'b', mode='wrap')",
        "@field_validator('a', 'b', mode='plain')",
        "@field_validator('a', 'b', mode=MODE)",
        "@field_validator('a', 'b', **OPTIONS)",
        "@fv('a', 'b', mode='before')",
        "@pydantic.field_validator('a', 'b', mode='before')",
        "@validator('a', 'b', pre=True)",
        "@validator('a', 'b', pre=FLAG)",
    ],
)
def test_each_spelling_of_a_rewriting_validator_drops_every_named_type(project, scan_collect, decorator: str) -> None:
    source = (
        V2
        + "import pydantic\nfrom pydantic import field_validator as fv, validator\n"
        + "MODE = 'before'\nOPTIONS = {}\nFLAG = True\n"
        + f"class C(BaseModel):\n    a: int\n    b: int\n    c: int\n    {decorator}\n    def check(cls, v): return v\n"
    )

    assert _typed(_class(project, scan_collect, source)) == {"a": None, "b": None, "c": "int"}


@pytest.mark.parametrize(
    "decorator",
    [
        "@field_validator('a')",
        "@field_validator('a', mode='after')",
        "@validator('a')",
        "@validator('a', pre=False)",
        "@other('a', mode='before')",
    ],
)
def test_a_validator_after_the_type_check_keeps_the_type(project, scan_collect, decorator: str) -> None:
    source = (
        V2
        + "from pydantic import validator\ndef other(*names, mode): return lambda f: f\n"
        + f"class C(BaseModel):\n    a: int\n    {decorator}\n    def check(cls, v): return v\n"
    )

    assert _typed(_class(project, scan_collect, source)) == {"a": "int"}


def test_a_validator_for_every_field_drops_every_type(project, scan_collect) -> None:
    source = (
        V2
        + "class C(BaseModel):\n    a: int\n    b: str\n"
        + "    @field_validator('*', mode='before')\n    def check(cls, v): return v\n"
    )
    attributes = _class(project, scan_collect, source)

    assert _typed(attributes) == {"a": None, "b": None}
    assert "fields_open" not in attributes


@pytest.mark.parametrize(
    "decorator",
    [
        "@field_validator(NAME, mode='before')",
        "@field_validator(*NAMES, mode='before')",
        "@field_validator(mode='before')",
        "@field_validator",
    ],
)
def test_a_rewriting_validator_whose_fields_cannot_be_read_opens(project, scan_collect, decorator: str) -> None:
    source = (
        V2
        + "NAME = 'a'\nNAMES = ['a']\n"
        + f"class C(BaseModel):\n    a: int\n    {decorator}\n    def check(cls, v): return v\n"
    )
    attributes = _class(project, scan_collect, source)

    assert attributes.get("fields_open") is True
    assert _typed(attributes) == {"a": None}


def test_a_rewriting_validator_naming_an_inherited_field_opens_the_subclass(project, scan_collect) -> None:
    source = (
        V2
        + "class B(BaseModel):\n    a: int\n"
        + "class C(B):\n    b: int\n    @field_validator('a', mode='before')\n    def check(cls, v): return v\n"
    )

    assert _class(project, scan_collect, source).get("fields_open") is True
    assert "fields_open" not in _class(project, scan_collect, source, "B")


def test_a_base_validator_drops_the_type_of_a_field_a_subclass_redeclares(project, scan_collect) -> None:
    source = (
        V2
        + "class A(BaseModel):\n    a: int\n    @field_validator('a', mode='before')\n    def check(cls, v): return v\n"
        + "class B(A):\n    pass\n"
        + "class C(B, A):\n    a: int\n    b: int\n"
    )
    attributes = _class(project, scan_collect, source)

    assert _typed(attributes) == {"a": None, "b": "int"}
    assert "fields_open" not in attributes


def test_a_base_whose_validator_cannot_be_read_opens_the_subclass(project, scan_collect) -> None:
    source = (
        V2
        + "NAME = 'a'\n"
        + "class B(BaseModel):\n    a: int\n"
        + "    @field_validator(NAME, mode='before')\n    def check(cls, v): return v\n"
        + "class C(B):\n    b: int\n"
    )
    attributes = _class(project, scan_collect, source)

    assert attributes.get("fields_open") is True
    assert _typed(attributes) == {"b": None}


@pytest.mark.parametrize(
    "annotation",
    [
        "Annotated[list[str], BeforeValidator(split)]",
        "Annotated[list[str], PlainValidator(split)]",
        "Annotated[list[str], WrapValidator(split)]",
        "Annotated[list[str], functional_validators.BeforeValidator(split)]",
        "list[Annotated[str, BeforeValidator(split)]]",
        "Optional[Annotated[list[str], BV(split)]]",
    ],
)
def test_an_annotated_validator_before_the_type_check_drops_the_type(project, scan_collect, annotation: str) -> None:
    source = (
        "from typing import Annotated, Optional\nfrom pydantic import BaseModel, functional_validators\n"
        + "from pydantic import BeforeValidator, BeforeValidator as BV, PlainValidator, WrapValidator\n"
        + "def split(value): return value\n"
        + "from pydantic import AfterValidator\n"
        + f"class C(BaseModel):\n    tags: {annotation}\n    n: Annotated[int, AfterValidator(split)]\n"
    )
    attributes = _class(project, scan_collect, source)

    assert _typed(attributes) == {"n": "int", "tags": None}
    assert _required(attributes)["tags"] is True


@pytest.mark.parametrize(
    "imports",
    [
        "from pydantic import BaseModel\n",
        "from pydantic import BaseModel\nfrom pydantic.v1 import ConfigDict\n",
        "import pydantic\nfrom pydantic import BaseModel\nX = pydantic.BaseModel\n",
    ],
)
def test_without_a_v2_name_an_optional_or_any_field_without_a_default_is_not_required(
    project, scan_collect, imports: str
) -> None:
    source = (
        imports
        + "from typing import Any, Optional, Union\nfrom pydantic import Field\n"
        + "class C(BaseModel):\n"
        + "    a: Optional[int]\n    b: int | None\n    c: Union[int, None]\n    d: Any\n    e: None | str\n"
        + "    f: Optional[int] = ...\n    g: Optional[int] = Field(...)\n    h: Optional[int] = Field(default=...)\n"
        + "    i: Optional[int] = Field(gt=0)\n    j: int\n    k: Union[int, str]\n    m: list[Optional[int]]\n"
    )

    assert _required(_class(project, scan_collect, source)) == {
        "a": False,
        "b": False,
        "c": False,
        "d": False,
        "e": False,
        "f": True,
        "g": True,
        "h": True,
        "i": False,
        "j": True,
        "k": True,
        "m": True,
    }


@pytest.mark.parametrize(
    "imports",
    [
        "from pydantic import BaseModel, ConfigDict\n",
        "from pydantic.functional_validators import field_validator\nfrom pydantic import BaseModel\n",
        "import pydantic\nfrom pydantic import BaseModel\nX = pydantic.TypeAdapter\n",
        "import pydantic as pd\nfrom pydantic import BaseModel\nX = pd.RootModel\n",
    ],
)
def test_a_v2_name_keeps_an_optional_field_without_a_default_required(project, scan_collect, imports: str) -> None:
    source = imports + "from typing import Optional\nclass C(BaseModel):\n    a: Optional[int]\n"
    source += "    b: Optional[int] = None\n"

    assert _required(_class(project, scan_collect, source)) == {"a": True, "b": False}


def test_a_dataclass_optional_field_without_a_default_stays_required(project, scan_collect) -> None:
    source = "from dataclasses import dataclass\nfrom typing import Optional\n"
    source += "@dataclass\nclass C:\n    a: Optional[int]\n"

    assert _required(_class(project, scan_collect, source)) == {"a": True}

from dataclasses import dataclass, field
from enum import Enum
from typing import NotRequired, Optional, TypedDict

from pydantic import BaseModel, Field, field_validator


class Courier(Enum):
    POST = "post"
    VAN = "van"


class Parcel(BaseModel):
    id: int
    weight_kg: float
    label: str = Field(default="")
    courier: Optional[Courier] = None


class Labelled(BaseModel):
    parcel_id: int = Field(alias="parcelId")


@dataclass
class Stop:
    city: str
    notes: list[str] = field(default_factory=list)


class Manifest(TypedDict):
    reference: str
    courier: NotRequired[str]


def to_camel(name: str) -> str:
    head, *rest = name.split("_")
    return head + "".join(part.title() for part in rest)


class CamelOrder(BaseModel):
    model_config = {"alias_generator": to_camel}
    order_id: int


@dataclass
class Settings:
    name: str

    def __getattr__(self, item: str) -> str:
        return ""


@dataclass
class PostalAddress:
    street_name: str
    city_name: str


@dataclass
class Consignment:
    billing_address: PostalAddress
    shipping_address: PostalAddress


class Order(BaseModel):
    order_id: int
    tags: list[str]
    note: Optional[str]

    @field_validator("tags", mode="before")
    @classmethod
    def split(cls, value: object) -> object:
        return value.split(",") if isinstance(value, str) else value

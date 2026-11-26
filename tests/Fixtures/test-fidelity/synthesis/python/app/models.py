from dataclasses import dataclass
from enum import Enum


@dataclass
class Item:
    id: int
    name: str
    tags: list[str]


class Color(Enum):
    RED = "red"
    BLUE = "blue"

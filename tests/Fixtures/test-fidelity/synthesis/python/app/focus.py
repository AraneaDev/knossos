import functools
from typing import Optional, overload

from app.models import Item


def traced(fn):
    return fn


class Store:
    def get(self, key: str, default: Optional[int] = None) -> Optional[int]:
        return default

    def _refresh(self) -> None: ...

    def __secret(self) -> None: ...

    def __len__(self) -> int:
        return 0

    async def fetch(self, item_id: int) -> Item:
        return Item(item_id, "", [])

    def find(self, *ids: int, strict: bool = False) -> list[Item]:
        return []

    def pair(self) -> tuple[Item, "Ledger", int]:
        return (Item(0, "", []), None, 0)

    @traced
    def wrapped(self) -> int:
        return 0

    @overload
    def pick(self, a: int) -> int: ...
    @overload
    def pick(self, a: str) -> str: ...
    def pick(self, a):
        return a

    @staticmethod
    def build() -> "Store":
        return Store()

    @classmethod
    def create(cls) -> "Store":
        return cls()

    @property
    def size(self) -> int:
        return 0


class Dynamic:
    def known(self) -> str:
        return ""

    def __getattr__(self, name):
        return name

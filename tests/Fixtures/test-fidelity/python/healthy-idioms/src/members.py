"""Every kind of member a class can have, each of which a test may stub."""

from contextlib import contextmanager

from retrying import retry


class Repo:
    """A closed list: every name below is one the class really has."""

    attribute = 1
    annotated: int

    class Nested:
        pass

    def __init__(self) -> None:
        self.instance_name = 0
        setattr(self, "set_by_name", 1)

    @classmethod
    def build(cls) -> "Repo":
        cls.class_written = True
        return cls()

    def method(self, key: str) -> str:
        return key

    def _protected(self) -> None:
        pass

    def __hidden(self) -> None:
        pass

    @property
    def size(self) -> int:
        return 0

    @retry
    def fetch(self, key: str) -> str:
        return key

    @contextmanager
    def opened(self):
        yield self


class Slotted:
    __slots__ = ("slot",)

    def __init__(self) -> None:
        self.slot = 0

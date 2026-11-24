"""A value class whose equality is the code under test."""


class Money:
    def __init__(self, cents: int) -> None:
        self.cents = cents

    def __eq__(self, other: object) -> bool:
        return isinstance(other, Money) and other.cents == self.cents

    def __hash__(self) -> int:
        return hash(self.cents)


def fill(buffer: list[int]) -> None:
    buffer.append(1)


def count(items: list[int]) -> int:
    return len(items)


def apply(supplier) -> int:
    return supplier()

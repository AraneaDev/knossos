import abc
import enum
import functools
from dataclasses import dataclass


@dataclass(frozen=True, order=True)
class Point:
    x: int
    y: int = 0

    def shifted(self, dx):
        return Point(self.x + dx, self.y)


@functools.total_ordering
class Ver:
    def __init__(self, n):
        self.n = n

    def __lt__(self, other):
        return self.n < other.n

    def __eq__(self, other):
        return self.n == other.n

    def bump(self, by):
        return Ver(self.n + by)


class Port(abc.ABC):
    @abc.abstractmethod
    def send(self, data): ...

    def wrap(self, data):
        return self.send(data)


@enum.unique
class Color(enum.Enum):
    RED = 1

    def describe(self, prefix):
        return prefix + self.name


def make():
    return Point(1, y=2)


def use(p, v, c):
    p.shifted(3)
    v.bump(1)
    c.describe("x")

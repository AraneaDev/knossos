"""Classes whose names come from bases outside the project, and functions whose name holds something else."""

import functools
from abc import ABC, abstractmethod
from enum import Enum
from typing import NamedTuple


class Colour(Enum):
    RED = 1


class Point(NamedTuple):
    x: int


class Shape(ABC):
    @abstractmethod
    def area(self) -> float: ...


class Square(Shape):
    def area(self) -> float:
        return 1.0


def total(base, extra):
    return base + extra


total = functools.partial(total, 1)


def pick(value):
    return value


try:
    from fastpick import pick
except ImportError:
    pass


class Handler:
    def handle(self, request, *args, **options) -> str:
        return request

    def run(self, job):
        return job

    run = functools.partialmethod(run, "default")

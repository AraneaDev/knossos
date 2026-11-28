"""The writes docs/languages/python.md lists as not followed (G4-02, G4-05, G4-12), each a warning (U1)."""

import sys

from framework import Model
from helpers import install as callable


class Aliased:
    def __init__(self) -> None:
        me = self
        me.extra = 1


class Built:
    pass


Built().extra = 1


class Outer:
    class Inner:
        pass


Outer.Inner.extra = 1


class Held:
    pass


def stamp(kind=Held) -> None:
    kind.extra = 1


class Plugged:
    pass


callable(Plugged)


class Framed:
    hook = sys._getframe(0).f_locals.setdefault("extra", 1)


class Record(Model):
    def save(self, item: int) -> int:
        return item

"""Which def Python's lookup answers a name with (final review I1, Ruling P24)."""

import logging


class Base:
    def save(self, key):
        return key

    def emit(self):
        return None

    def handle(self, a):
        return a


class Left(Base):
    pass


class Right(Base):
    def handle(self, a, b):
        return a + b


class Diamondish(Left, Right):
    pass


class Mixed(Left, object):
    pass


def _save_impl(self, key, value, extra=None):
    return key


class Shadow(Base):
    save = _save_impl


class Tracker(logging.Handler, Base):
    pass


class InstanceShadow(Base):
    def __init__(self, region):
        self.save = lambda key, value: (region, key, value)


class Late(Base):
    pass


def _late_handle(self, a, b, c):
    return a


Late.handle = _late_handle


class A:
    def run(self, x):
        return x


class B(A):
    pass


class C:
    def run(self, x, y):
        return x + y


class D(B, C):
    pass


class Single(Base):
    """The planted twin: one base, nothing shadowing its def, so the def answers for certain."""

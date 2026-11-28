"""Local gets Wrapping only through Base, all in one module."""

import functools


class Wrapping(type):
    def __new__(mcs, name, bases, namespace):
        for key, fn in list(namespace.items()):
            if callable(fn) and not key.startswith("__"):
                namespace[key] = functools.partialmethod(fn, "session")
        return super().__new__(mcs, name, bases, namespace)


class Base(metaclass=Wrapping):
    pass


class Local(Base):
    def save(self, session, value):
        return value


def use():
    Local().save(1)

"""A project metaclass that binds a session into every method."""

import functools


class Wrapping(type):
    def __new__(mcs, name, bases, namespace):
        for key, fn in list(namespace.items()):
            if callable(fn) and not key.startswith("__"):
                namespace[key] = functools.partialmethod(fn, "session")
        return super().__new__(mcs, name, bases, namespace)


class Base(metaclass=Wrapping):
    def load(self, session, key):
        return key

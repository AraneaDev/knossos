"""Rebinding forms the worker's `rebound` view may miss."""

import functools


def inject(cls):
    for name, fn in list(vars(cls).items()):
        if callable(fn) and not name.startswith("__"):
            setattr(cls, name, functools.partialmethod(fn, "session"))
    return cls


@inject
class Store:
    def save(self, session, value):
        return value


def g(a, b):
    return a


globals()["g"] = functools.partial(g, 1)


def use():
    Store().save(1)
    g(2)

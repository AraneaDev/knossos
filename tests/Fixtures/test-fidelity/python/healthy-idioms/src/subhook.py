"""A base whose __init_subclass__ rewrites a subclass's methods."""

import functools


class Hooked:
    def __init_subclass__(cls, **kwargs):
        super().__init_subclass__(**kwargs)
        for key, fn in list(vars(cls).items()):
            if callable(fn) and not key.startswith("__"):
                setattr(cls, key, functools.partialmethod(fn, "session"))


class D(Hooked):
    def save(self, session, value):
        return value


def use():
    D().save(1)

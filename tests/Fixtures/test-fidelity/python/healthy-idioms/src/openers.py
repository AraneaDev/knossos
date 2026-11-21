"""Classes and a module whose names may come from somewhere the source does not spell."""

from typing import Generic, TypeVar

import attr

T = TypeVar("T")


class Lazy:
    def __getattr__(self, name: str) -> str:
        return name


class Guarded:
    def __getattribute__(self, name: str) -> object:
        return object.__getattribute__(self, name)


class Registered:
    def __init_subclass__(cls, **kwargs: object) -> None:
        cls.registered = True


class Meta(type):
    pass


class WithMeta(metaclass=Meta):
    pass


def register(cls: type) -> type:
    return cls


@register
class Decorated:
    pass


@attr.s
class Attrs:
    value = attr.ib()


class Box(Generic[T]):
    pass


class Failure(Exception):
    pass


class Settings:
    def __init__(self, **options: object) -> None:
        for key, value in options.items():
            setattr(self, key, value)


class Bag:
    def __init__(self, **options: object) -> None:
        self.__dict__.update(options)


class Generated:
    for _name in ("alpha", "beta"):
        locals()[_name] = _name


class Patched:
    pass


Patched.added = 1

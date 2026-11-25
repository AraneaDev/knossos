"""Classes written through a same-file alias (final review I3, Ruling P23)."""

from typing import Callable


class LoopA:
    def a(self):
        return 1


class LoopB:
    def b(self):
        return 1


class Viewish:
    pass


class Installed:
    pass


class Registered:
    pass


class Aliased:
    pass


class Paired:
    pass


class Comprehended:
    pass


for _cls in (LoopA, LoopB):
    _cls.extra = 1

for _name in ("get", "post"):
    setattr(Viewish, _name, lambda self, n=_name: n)


def _install(target):
    target.hook = lambda self: 2
    return target


Installed = _install(Installed)

REGISTRY = {"r": Registered}
REGISTRY["r"].registered = True

Alias = Aliased
Alias.x = 1
PAIR = (Paired, 1)
PAIR[0].y = 2
[k for k in [Comprehended]][0].z = 3


class Typed:
    """The planted twin: named in annotations, instantiated and checked, but never held, so its list stays closed."""

    def run(self):
        return 1


def check(value: tuple[Typed, int]) -> Callable[[Typed], Typed]:
    if isinstance(value[0], (Typed, int)):
        return lambda item: item
    raise TypeError(value)


INSTANCE = Typed()

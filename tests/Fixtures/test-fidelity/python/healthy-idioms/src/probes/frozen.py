"""Names written through `object.__setattr__` and `super().__setattr__`."""

from dataclasses import dataclass


@dataclass(frozen=True)
class Frozen:
    name: str

    def __post_init__(self):
        object.__setattr__(self, "slug", self.name.lower())

    def show(self):
        return self.slug


class Plain:
    def __init__(self):
        object.__setattr__(self, "token", "t")
        super().__setattr__("other", 1)

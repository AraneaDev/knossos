"""Nested classes whose names are written from outside their bodies (G4-05, U1)."""


class Outer:
    class Inner:
        def run(self, item: int) -> int:
            return item


Outer.Inner.extra = 1
Outer.Inner.run = lambda self, a, b: a + b


class Far:
    class Inner:
        pass


Alias = Outer.Inner
Alias.aliased = 1

"""A class nothing writes in a way the worker does not follow: its ghost and arity stay definite (U1)."""


class Plain:
    def run(self, item: int) -> int:
        return item

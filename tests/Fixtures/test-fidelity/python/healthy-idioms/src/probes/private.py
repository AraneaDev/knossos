"""A module-level `__x` is not mangled, so a patch of it under that spelling is healthy."""


def __helper(x):
    return x


def public(x):
    return __helper(x)


class Holder:
    def __hidden(self):
        return 1

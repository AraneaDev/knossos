"""A def its own class rebinds on `self`, in each form `member_names` reads (final review I2)."""

import functools
import os


class Same:
    def __init__(self, region):
        self.save = functools.partial(self._save, region)

    def save(self, key):
        return key

    def _save(self, region, key, value):
        return (region, key, value)


class ViaSetattr:
    def __init__(self):
        setattr(self, "load", self._load_v2)

    def load(self):
        return 1

    def _load_v2(self, a, b):
        return a + b


class Inst:
    def __init__(self):
        self.a, self.b = 1, 2
        [self.c, *self.d] = [1, 2, 3]
        with open(os.devnull) as self.fh:
            pass
        for self.last in range(2):
            pass

        def later():
            self.inner = 1

        later()
        (lambda: setattr(self, "lam", 1))()

    def run(self):
        return self.a


class Pair:
    def __init__(self):
        self.save, self.load = self._save, self._load

    def save(self, k):
        return k

    def load(self):
        return 0

    def _save(self, k, v):
        return v

    def _load(self, k):
        return k


class Kept:
    """The planted twin: `__init__` writes other names, so `save` keeps its def's signature."""

    def __init__(self):
        self.other, self.more = 1, 2
        setattr(self, "extra", 3)

    def save(self, k):
        return k

"""Every way a module or class body binds a name, each patched by `tests/test_probe_shapes.py`."""

import contextlib
import sys

try:
    import tomllib as toml_reader
except ImportError:
    toml_reader = None

with contextlib.suppress(Exception):
    CONFIG = {"a": 1}

for _i in range(2):
    LOOPED = _i

while True:
    IN_WHILE = 1
    break

if (WALRUS := 3) > 1:
    pass

match sys.platform:
    case "linux":
        PLATFORM = "l"
    case other:
        PLATFORM = other

COUNTER: int = 0
COUNTER += 1
a = b = 2
(c, d), e = (1, 2), 3
[z for z in range(1) if (COMP := z) is not None]

import os.path  # noqa: E402
import xml.etree.ElementTree as ET  # noqa: E402


def setup():
    global LATE
    LATE = 1


class Local:
    import json as cjson

    try:
        from functools import cache as memo
    except ImportError:
        memo = None
    if sys.version_info >= (3, 0):

        def modern(self):
            return 1
    else:

        def modern(self, x):
            return 2

    x = y = 1
    p, q = 1, 2
    __slots__ = "only"

    def method(self):
        def inner():
            self.nested_attr = 1

        inner()
        [self for self.comp_attr in range(1)]
        with open(os.devnull) as self.handle:
            pass
        for self.loop_attr in range(1):
            pass
        self.tuple_a, self.tuple_b = 1, 2
        (self.walrus_attr) = 2


def helper():
    return 1

"""Imports a function and binds the name again: the import no longer says what the name holds."""

import functools

from bases import Handler
from values import count

count = functools.partial(count, [1])

__all__ = ["Handler", "count"]

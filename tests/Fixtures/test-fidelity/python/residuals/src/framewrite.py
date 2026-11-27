"""Writes its caller's local, which Python 3.13 and later write through (G4-08)."""

import sys


def write_caller_local() -> None:
    sys._getframe(1).f_locals["x"] = 2

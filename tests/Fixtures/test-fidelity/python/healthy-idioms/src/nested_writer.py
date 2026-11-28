"""Writes a nested class another module declares (G4-03 meets G4-05)."""

import nested_writes


def enable() -> None:
    nested_writes.Far.Inner.remote = 1

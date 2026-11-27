"""A module that writes itself through an import of itself (G4-02)."""

import selfwrite


def enable() -> None:
    selfwrite.LATE = 1


def late() -> int:
    return 0

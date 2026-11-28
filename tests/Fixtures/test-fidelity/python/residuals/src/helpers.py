"""A helper that writes the class it is handed, imported under a read-only builtin's name (G4-05)."""


def install(kind) -> None:
    kind.extra = 1

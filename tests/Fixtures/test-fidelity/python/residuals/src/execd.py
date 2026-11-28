"""A module that writes its class through `builtins.exec`, which marks every class of it (G4-05)."""

import builtins


class Execd:
    pass


builtins.exec("Execd.extra = 1")

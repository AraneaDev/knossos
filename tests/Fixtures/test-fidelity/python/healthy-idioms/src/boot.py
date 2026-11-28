"""Sets another module's flags at start-up, as an application's settings loader does (G4-03)."""

import flags


def configure() -> None:
    flags.VERBOSE = True

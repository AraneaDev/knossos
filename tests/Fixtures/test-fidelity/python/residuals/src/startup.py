"""Writes the settings another module declares, as a start-up hook does (G4-03)."""

import config
from config import Settings


def enable() -> None:
    config.FLAG = 1
    config.DEBUG = True
    Settings.verbose = True

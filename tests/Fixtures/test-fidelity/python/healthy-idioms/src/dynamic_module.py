"""A module that answers any name, so no list of its exports is complete."""


def __getattr__(name: str) -> str:
    return name


def known() -> str:
    return "known"

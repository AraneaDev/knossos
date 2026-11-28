"""Calls a method through its class, handing the receiver over, as a mixin or a base call does (G4-10)."""

from members import Repo


def keep(repo: Repo, key: str) -> str:
    return Repo.method(repo, key)

"""Calls `Repo.save` through its class, handing the receiver over, and `Ledger.add` bound (G4-10)."""

from store import Ledger, Repo


def keep(repo: Repo, item: int) -> int:
    return Repo.save(repo, item)


def record(item: int) -> int:
    return Ledger().add(item)

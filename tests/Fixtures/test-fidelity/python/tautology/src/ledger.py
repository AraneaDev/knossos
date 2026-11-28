"""Production code the tautology fixture's healthy twins run."""


class Ledger:
    def __init__(self) -> None:
        self.entries: list[int] = []

    def add(self, amount: int) -> int:
        self.entries.append(amount)
        return sum(self.entries)

    def total(self) -> int:
        return sum(self.entries)

    def itself(self) -> "Ledger":
        return self


def deposit(ledger: Ledger, amount: int) -> int:
    return ledger.add(amount)


def report(ledger: Ledger) -> str:
    return f"total {ledger.total()}"

class Ledger:
    def total(self) -> int:
        return 0


def report(ledger: Ledger) -> str:
    return f"total {ledger.total()}"

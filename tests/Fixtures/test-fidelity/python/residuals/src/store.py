"""Two classes: production calls one's method through the class, the other's only bound (G4-10)."""


class Repo:
    def save(self, item: int) -> int:
        return item


class Ledger:
    def add(self, item: int) -> int:
        return item

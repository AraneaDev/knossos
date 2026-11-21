class Account:
    """`balance` became a property; patching it the old way breaks it."""

    @property
    def balance(self) -> int:
        return 0

    def deposit(self, amount: int) -> int:
        return amount

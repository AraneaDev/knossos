class PaymentClient:
    def charge(self, cents: int) -> str:
        return str(cents)

    def refund(self, reference: str) -> str:
        return reference

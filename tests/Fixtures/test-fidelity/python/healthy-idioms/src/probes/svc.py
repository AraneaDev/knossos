"""A spec'd double handed to production (p3)."""


class Api:
    def fetch(self) -> list:
        return []

    def count(self) -> int:
        return 0


def use(api: Api) -> int:
    return len(api.fetch())

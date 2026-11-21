class Repo:
    def save(self, item: "Item") -> int:
        return 1

    def price(self) -> int:
        return 4200

    def count(self):
        return sum([1, 2, 3])

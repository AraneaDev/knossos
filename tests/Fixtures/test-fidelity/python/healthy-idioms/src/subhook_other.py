from subhook import Hooked


class E(Hooked):
    def save(self, session, value):
        return value


def use():
    E().save(1)

"""C gets Wrapping only through Base, declared in another module."""

from wrapmeta import Base


class C(Base):
    def save(self, session, value):
        return value


def use():
    C().save(1)

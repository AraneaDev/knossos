"""A module that imports a class and a function under names of its own, where a test patches them."""

from members import Repo as Store
from openers import install as setup_class


def build() -> Store:
    setup_class(Store)
    return Store()

from app.derived import Derived
from app.other import Other


def test_unrelated_pair(mocker):
    # Only this one is related to Base: Derived inherits `save` from it, and
    # the lambda's arity does not match the real method's.
    mocker.patch("app.derived.Derived.save", side_effect=lambda a, b, c: True)

    # Same method name, unrelated class. Must never be blamed for the
    # mismatch above just because the name `save` collides.

    mocker.patch("app.other.Other.save")

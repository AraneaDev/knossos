"""A matcher that lists the receiver production hands a method it calls through the class (G4-10)."""

from unittest import mock

import keeper
from members import Repo


def test_a_method_called_through_its_class_records_the_receiver():
    repo = Repo()
    with mock.patch.object(Repo, "method") as method:
        keeper.keep(repo, "k")
        method.assert_called_once_with(repo, "k")

"""A matcher that lists the receiver of a call made through the class (G4-10), beside two that cannot match."""

from unittest import mock

import service
from store import Ledger, Repo


def test_a_method_production_calls_through_its_class():
    repo = Repo()
    with mock.patch.object(Repo, "save") as save:
        service.keep(repo, 1)
        save.assert_called_once_with(repo, 1)


def test_one_argument_more_than_the_receiver_still_reports():
    repo = Repo()
    with mock.patch.object(Repo, "save") as save:
        service.keep(repo, 1)
        save.assert_called_once_with(repo, 1, 2)


def test_the_receiver_of_a_method_production_calls_bound_still_reports():
    ledger = Ledger()
    with mock.patch.object(Ledger, "add") as add:
        service.record(1)
        add.assert_called_once_with(ledger, 1)

"""Healthy (G3-19): an `Enum` case pinned in a matcher, by the case and by its value."""

from unittest import mock

from route import Leg, Router, send


def test_a_case_pinned_in_a_matcher():
    router = mock.Mock(spec=Router)
    router.reroute.return_value = True
    assert send(router, Leg.SEA) is True
    router.reroute.assert_called_once_with(Leg.SEA)


def test_a_case_built_from_its_value():
    router = mock.Mock(spec=Router)
    router.reroute.return_value = True
    assert send(router, Leg("air")) is True
    router.reroute.assert_called_once_with(Leg("air"))

from unittest import mock

from members import Repo


def test_a_planted_ghost_still_gives_a_definite():
    with mock.patch.object(Repo, "methd"):
        pass

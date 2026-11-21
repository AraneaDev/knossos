from unittest.mock import patch, Mock
from repo import Repo


def test_save():
    with patch.object(Repo, "save2") as m:
        pass


def test_price():
    m = Mock(spec=Repo)
    m.price.return_value = "nope"


def test_count():
    m = Mock(spec=Repo)
    m.count.return_value = "nope"

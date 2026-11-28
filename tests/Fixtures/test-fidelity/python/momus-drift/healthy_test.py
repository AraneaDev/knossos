from unittest.mock import patch, Mock
from repo import Repo


def test_save():
    with patch.object(Repo, "save") as m:
        pass


def test_price():
    m = Mock(spec=Repo)
    m.price.return_value = 1

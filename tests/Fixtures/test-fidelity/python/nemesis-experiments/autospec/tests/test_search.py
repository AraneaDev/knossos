from unittest.mock import create_autospec

from src.search import SearchClient


def test_autospec_experiment():
    client = create_autospec(SearchClient)
    client.query.return_value = ['result']
    assert client is not None

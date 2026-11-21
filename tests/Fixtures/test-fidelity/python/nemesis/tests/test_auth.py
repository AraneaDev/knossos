"""Fixture tests exercising unittest.mock / pytest-mock drift."""
from unittest.mock import patch

from src.services import AuthClient


def test_ghost_method(mocker):
    """Stubbing a method that no longer exists."""
    with patch.object(AuthClient, 'logn', return_value='tok'):
        client = AuthClient()
        assert client is not None


def test_clean_patch(mocker):
    with patch.object(AuthClient, 'login', return_value='tok'):
        client = AuthClient()
        assert client.login('u', 'p') == 'tok'


def test_dotted_patch(mocker):
    with patch('src.services.AuthClient.refresh', return_value='x'):
        assert True

from unittest.mock import Mock, patch

from src.session import SessionClient


def test_spec_experiment():
    client = Mock(spec=SessionClient)
    client.open.return_value = True
    assert client is not None


def test_patch_object_experiment():
    with patch.object(SessionClient, 'close', return_value=True):
        assert True

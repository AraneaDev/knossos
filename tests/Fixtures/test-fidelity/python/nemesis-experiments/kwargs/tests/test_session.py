from unittest.mock import Mock

from src.session import Session


def test_keyword_outlived_the_parameter():
    session = Mock(spec=Session)
    session.open.assert_called_with(user='ada', token='t')

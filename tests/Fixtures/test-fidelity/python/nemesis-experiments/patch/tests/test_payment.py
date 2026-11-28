from unittest.mock import patch

from src.payment import PaymentClient


def test_dotted_patch_experiment():
    with patch('src.payment.PaymentClient.charge', return_value='ok'):
        assert PaymentClient().charge(10) == 'ok'


def test_stale_dotted_method_experiment():
    with patch('src.payment.PaymentClient.capture', return_value='ok'):
        assert True

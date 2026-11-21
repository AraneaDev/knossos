from postal import mailer


def test_module_members_drift(mocker):
    # `dispatch` left this module when it was renamed to `send`.
    mocker.patch("postal.mailer.dispatch")

    # `deliver` is imported here rather than defined here, which is where
    # Python convention says to patch it. Its real return type is bool.
    mocker.patch("postal.mailer.deliver", return_value="yes")

    assert mailer is not None

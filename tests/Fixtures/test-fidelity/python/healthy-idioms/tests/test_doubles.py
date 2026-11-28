import os
from unittest import mock

from members import Repo


def test_mock_open_stands_in_for_the_builtin():
    with mock.patch("builtins.open", mock.mock_open(read_data="x")):
        with open("anything") as handle:
            assert handle.read() == "x"


def test_create_adds_a_name_the_class_lacks():
    with mock.patch.object(Repo, "brand_new", create=True, return_value=1):
        assert Repo.brand_new() == 1


def test_raising_false_tolerates_a_missing_name(monkeypatch):
    monkeypatch.setattr(Repo, "missing", 1, raising=False)
    monkeypatch.setenv("NAME", "value")
    assert Repo.missing == 1


def test_an_unspecd_mock_takes_any_name():
    double = mock.Mock()
    double.anything.return_value = 1
    double.anything.assert_not_called()
    assert Repo().method(double.anything()) == 1


def test_a_class_autospec_records_the_instance():
    repo = Repo()
    with mock.patch.object(Repo, "method", autospec=True) as method:
        repo.method("k")
        method.assert_called_once_with(repo, "k")


def test_a_spy_on_a_class_records_the_instance(mocker):
    spy = mocker.spy(Repo, "method")
    Repo().method("k")
    spy.assert_called_once_with(mock.ANY, "k")


def test_a_dict_and_the_environment_are_no_class_doubles():
    with mock.patch.dict(os.environ, {"A": "1"}), mock.patch.dict("members.__dict__", {"extra": 1}):
        assert os.environ["A"] == "1"


def test_a_name_imported_under_an_alias_is_patched_where_it_is_used(monkeypatch, mocker):
    import aliasing

    with mock.patch("aliasing.Store"), mock.patch.object(aliasing, "setup_class"):
        aliasing.build()
    monkeypatch.setattr("aliasing.setup_class", lambda target: None)
    mocker.patch.object(aliasing, "Store")
    mocker.patch("aliasing.setup_class")

from unittest import mock

import members
from members import Repo, Slotted


def test_each_member_kind_can_be_stubbed():
    repo = Repo()
    with (
        mock.patch.object(Repo, "method", return_value="x"),
        mock.patch.object(Repo, "attribute", 2),
        mock.patch.object(Repo, "Nested"),
        mock.patch.object(Repo, "build"),
        mock.patch.object(Repo, "_Repo__hidden"),
        mock.patch.object(Repo, "size", new_callable=mock.PropertyMock, return_value=3),
        mock.patch.object(Repo, "fetch", return_value="y"),
        mock.patch.object(Repo, "opened"),
        mock.patch.object(repo, "instance_name", 4),
        mock.patch.object(repo, "set_by_name", 5),
    ):
        assert repo.method("k") == "x"
        assert repo.size == 3


def test_a_slot_can_be_stubbed():
    slotted = Slotted()
    with mock.patch.object(slotted, "slot", 1):
        assert slotted.slot == 1


def test_a_spec_double_names_real_members():
    double = mock.create_autospec(Repo, instance=True)
    double.method.return_value = "z"
    assert double.method("k") == "z"


def test_a_module_function_can_be_patched():
    with mock.patch("members.Repo.method", return_value="w"):
        assert members.Repo().method("k") == "w"

from app.vault import Vault


def test_patch_multiple_names_members(mocker):
    # Every keyword names a member that has to exist; `reseal` left the class.
    mocker.patch.multiple(Vault, seal=mocker.DEFAULT, reseal=mocker.DEFAULT)
    assert Vault is not None

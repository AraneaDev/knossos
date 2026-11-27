"""A call of a method through its class hands the receiver as an argument, and its `calls` edge says so (G4-10)."""

from __future__ import annotations

REPO = "class Repo:\n    def save(self, item: int) -> int:\n        return item\n"


def _calls(project, scan_collect, source: str) -> list[tuple[str, int, dict]]:
    root = project({"repo.py": REPO, "service.py": source})
    [contribution] = [item for item in scan_collect(root, ["service.py"]) if item["owner_key"].endswith("service.py")]
    return [
        (edge["target"], edge["evidence"]["start_line"], edge["attributes"])
        for edge in contribution["edges"]
        if edge["kind"] == "calls" and edge["target"].startswith("py:method:")
    ]


def test_a_method_called_through_its_class_is_unbound(project, scan_collect) -> None:
    source = (
        "import repo\nfrom repo import Repo\n\n\ndef store(item):\n    keeper = Repo()\n"
        "    Repo.save(keeper, item)\n    repo.Repo.save(keeper, item)\n    keeper.save(item)\n    Repo().save(item)\n"
    )

    assert _calls(project, scan_collect, source) == [
        ("py:method:repo.Repo::save", 7, {"unbound": True}),
        ("py:method:repo.Repo::save", 8, {"unbound": True}),
        ("py:method:repo.Repo::save", 9, {}),
        ("py:method:repo.Repo::save", 10, {}),
    ]


def test_a_class_method_reached_through_self_or_cls_is_bound(project, scan_collect) -> None:
    source = (
        "from repo import Repo\n\n\nclass Cached(Repo):\n    def save(self, item: int) -> int:\n"
        "        return Repo.save(self, item)\n\n"
        "    def again(self, item: int) -> int:\n        return self.save(item)\n"
    )

    calls = _calls(project, scan_collect, source)

    # An explicit base call hands `self` on as its first argument; `self.save()` binds it.
    assert ("py:method:repo.Repo::save", 6, {"unbound": True}) in calls
    assert ("py:method:service.Cached::save", 9, {}) in calls

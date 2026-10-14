"""Celery tasks queued by name: ``app.send_task("pkg.tasks.name")`` and ``signature("...")``."""

from __future__ import annotations

from types import ModuleType

TASKS = "from celery import shared_task\n@shared_task\ndef deliver(order_id):\n    return order_id\n"


def _dispatches(contributions: list[dict]) -> list[tuple[str, str, str, dict]]:
    return [
        (edge["source"], edge["target"], edge["confidence"], edge.get("attributes", {}))
        for item in contributions
        for edge in item["edges"]
        if edge["kind"] == "dispatches"
    ]


def test_a_task_sent_by_its_name_is_a_dispatch_to_that_task(worker: ModuleType, project, scan_collect) -> None:
    root = project(
        {
            "shop/__init__.py": "",
            "shop/tasks.py": TASKS,
            "shop/views.py": (
                "from celery import current_app, signature\n"
                "def checkout(order_id):\n"
                "    current_app.send_task('shop.tasks.deliver', args=[order_id])\n"
                "def later(order_id):\n"
                "    return signature('shop.tasks.deliver', args=[order_id])\n"
            ),
        }
    )
    dispatches = _dispatches(scan_collect(root, ["shop/tasks.py", "shop/views.py"]))
    assert ("py:function:shop.views.checkout", "py:function:shop.tasks.deliver", "certain", {}) in dispatches
    assert (
        "py:function:shop.views.later",
        "py:function:shop.tasks.deliver",
        "probable",
        {"celery": "signature"},
    ) in dispatches


def test_a_name_no_project_module_declares_is_a_speculative_dispatch(worker: ModuleType, project, scan_collect) -> None:
    root = project(
        {
            "shop/__init__.py": "",
            "shop/views.py": (
                "from celery import current_app\n"
                "def checkout(order_id):\n"
                "    current_app.send_task('emails.send_receipt', args=[order_id])\n"
                "def dynamic(name):\n"
                "    current_app.send_task(name)\n"
            ),
        }
    )
    dispatches = _dispatches(scan_collect(root, ["shop/views.py"]))
    assert dispatches == [
        (
            "py:function:shop.views.checkout",
            "py:function:emails.send_receipt",
            "probable",
            {"celery": "by_name", "speculative": True},
        )
    ]

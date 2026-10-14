"""Django URL configuration: include() mounts, ``urlpatterns +=`` and DRF routers."""

from __future__ import annotations

from types import ModuleType


def _by_file(contributions: list[dict]) -> dict[str, dict]:
    return {item["owner_key"].removeprefix("knossos.python:file:"): item for item in contributions}


def _routes(contribution: dict) -> dict[str, dict]:
    return {node["canonical_name"]: node for node in contribution["nodes"] if node["kind"] == "route"}


def _mounts(contribution: dict) -> list[tuple[str, str, dict]]:
    return [
        (edge["source"], edge["target"], edge.get("attributes", {}))
        for edge in contribution["edges"]
        if edge["kind"] == "mounts"
    ]


SHOP_URLS = (
    "from django.urls import path\nfrom shop.views import order\nurlpatterns = [path('orders/<int:id>/', order)]\n"
)
SHOP_VIEWS = "def order(request, id):\n    return None\n"


def test_an_include_is_a_mount_onto_the_including_urlconf(worker: ModuleType, project, scan_collect) -> None:
    root = project(
        {
            "proj/__init__.py": "",
            "proj/urls.py": (
                "from django.urls import include, path\n"
                "urlpatterns = [\n"
                "    path('api/', include('shop.urls')),\n"
                "    path('legacy/', include(('shop.urls', 'shop'))),\n"
                "]\n"
            ),
            "shop/__init__.py": "",
            "shop/urls.py": SHOP_URLS,
            "shop/views.py": SHOP_VIEWS,
        }
    )
    files = _by_file(scan_collect(root, ["proj/urls.py", "shop/urls.py", "shop/views.py"]))
    including = files["proj/urls.py"]
    # The include is no route of its own.
    assert _routes(including) == {}
    mounts = _mounts(including)
    assert ("py:module:proj.urls", "py:module:shop.urls", {"prefix": "api/", "onto": "py:module:proj.urls"}) in mounts
    assert (
        "py:module:proj.urls",
        "py:module:shop.urls",
        {"prefix": "legacy/", "onto": "py:module:proj.urls"},
    ) in mounts
    assert "ANY /orders/<int:id>/ => order" in _routes(files["shop/urls.py"])


def test_an_include_of_a_module_outside_the_project_is_speculative(worker: ModuleType, project, scan_collect) -> None:
    root = project(
        {
            "proj/urls.py": (
                "from django.urls import include, path\nurlpatterns = [path('auth/', include('allauth.urls'))]\n"
            ),
        }
    )
    [contribution] = scan_collect(root, ["proj/urls.py"])
    assert _mounts(contribution) == [
        (
            "py:module:proj.urls",
            "py:module:allauth.urls",
            {"prefix": "auth/", "onto": "py:module:proj.urls", "speculative": True},
        )
    ]


def test_patterns_added_with_plus_equals_and_plus_are_routes(worker: ModuleType, project, scan_collect) -> None:
    root = project(
        {
            "proj/urls.py": (
                "from django.urls import path\n"
                "from proj.views import a, b, c\n"
                "urlpatterns = [path('a/', a)] + [path('b/', b)]\n"
                "urlpatterns += [path('c/', c)]\n"
            ),
            "proj/views.py": "".join(f"def {name}(request):\n    return None\n" for name in "abc"),
        }
    )
    files = _by_file(scan_collect(root, ["proj/urls.py", "proj/views.py"]))
    assert set(_routes(files["proj/urls.py"])) == {"ANY /a/ => a", "ANY /b/ => b", "ANY /c/ => c"}


def test_a_drf_router_registration_is_a_list_and_a_detail_route(worker: ModuleType, project, scan_collect) -> None:
    root = project(
        {
            "proj/urls.py": (
                "from django.urls import include, path\n"
                "from rest_framework.routers import DefaultRouter\n"
                "from proj.views import OrderViewSet, NoteViewSet\n"
                "router = DefaultRouter()\n"
                "router.register(r'orders', OrderViewSet, basename='order')\n"
                "extra = DefaultRouter()\n"
                "extra.register('notes', NoteViewSet)\n"
                "urlpatterns = [path('api/', include(router.urls))] + extra.urls\n"
            ),
            "proj/views.py": "class OrderViewSet:\n    pass\nclass NoteViewSet:\n    pass\n",
        }
    )
    files = _by_file(scan_collect(root, ["proj/urls.py", "proj/views.py"]))
    contribution = files["proj/urls.py"]
    routes = _routes(contribution)
    assert set(routes) == {
        "ANY /orders/ => OrderViewSet",
        "ANY /orders/{pk}/ => OrderViewSet",
        "ANY /notes/ => NoteViewSet",
        "ANY /notes/{pk}/ => NoteViewSet",
    }
    assert routes["ANY /orders/{pk}/ => OrderViewSet"]["attributes"]["router"] == "py:router:proj.urls.router"
    assert routes["ANY /orders/{pk}/ => OrderViewSet"]["attributes"]["path"] == "orders/{pk}/"
    edges = [(edge["kind"], edge["source"], edge["target"]) for edge in contribution["edges"]]
    assert ("routes_to", "py:route:ANY /orders/ => OrderViewSet", "py:class:proj.views.OrderViewSet") in edges
    mounts = _mounts(contribution)
    assert (
        "py:module:proj.urls",
        "py:router:proj.urls.router",
        {"prefix": "api/", "onto": "py:module:proj.urls"},
    ) in mounts
    assert ("py:module:proj.urls", "py:router:proj.urls.extra", {"prefix": "", "onto": "py:module:proj.urls"}) in mounts
    assert any(
        node["kind"] == "router" and node["canonical_name"] == "proj.urls.router" for node in contribution["nodes"]
    )

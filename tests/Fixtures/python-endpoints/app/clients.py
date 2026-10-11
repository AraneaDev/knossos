import httpx
import requests
from google.appengine.api import taskqueue


def sync(order_id: int) -> None:
    requests.get("/api/orders")
    requests.post(f"/api/orders/{order_id}/lines")
    requests.request("DELETE", f"/api/orders/{order_id}")
    httpx.patch("/api/orders/1")
    requests.get("https://example.com/x")


def enqueue() -> None:
    taskqueue.add(url="/task/search/", params={"q": "x"})
    taskqueue.Task(url="/task/reindex/", method="GET")


def dynamic(base: str, suffix: str, kind: str, a: str, b: str) -> None:
    requests.get(f"{base}/orders")
    requests.get(f"/api/orders{suffix}")
    requests.get(f"/api/{kind}-list")
    requests.get(f"{a}{b}")
    requests.get(base)

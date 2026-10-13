from celery import chain, shared_task

from app import billing
from app.billing import reconcile_orders


@shared_task
def deliver(order_id: int) -> int:
    return order_id


def place(order_id: int) -> None:
    deliver(order_id)
    deliver.delay(order_id)
    deliver.apply_async(args=[order_id], countdown=5)


def nightly() -> None:
    reconcile_orders.delay()
    billing.reconcile_orders.apply_async()


def prepare(order_id: int) -> object:
    return deliver.s(order_id)


def batch(order_ids: list[int]) -> None:
    chain(deliver.si(order_ids[0]), billing.reconcile_orders.signature()).delay()


def retry(order_id: int) -> None:
    deliver.s(order_id)
    deliver.delay(order_id)

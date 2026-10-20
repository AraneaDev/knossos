from django.urls import path

from .search import search_orders


def search(request):
    return search_orders(request)


urlpatterns = [path("task/search/", search)]

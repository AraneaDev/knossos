from rest_framework.generics import GenericAPIView

from app.serializers import BulkSerializer


class BulkView(GenericAPIView):
    def post(self, request):
        serializer = self.get_serializer(data=request.data)
        serializer.is_valid(raise_exception=True)
        method = serializer.validated_data.get("method")
        return method([1, 2])

    serializer_class = BulkSerializer


class DirectView(GenericAPIView):
    def post(self, request):
        serializer = BulkSerializer(data=request.data)
        serializer.is_valid(raise_exception=True)
        return serializer.validated_data["method"]([1])

    def put(self, request):
        serializer = BulkSerializer(data=request.data)
        return serializer.validated_data["ids"]


class Untyped(GenericAPIView):
    def post(self, request):
        serializer = self.get_serializer(data=request.data)
        return serializer.validated_data.get("method")

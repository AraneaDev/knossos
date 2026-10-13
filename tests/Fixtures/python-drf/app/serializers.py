from rest_framework import serializers

from app import actions


class BulkSerializer(serializers.Serializer):
    method = serializers.CharField()

    def validate_method(self, method):
        if method == "archive":
            return actions.archive
        return actions.restore

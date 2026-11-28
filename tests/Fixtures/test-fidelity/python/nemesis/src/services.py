"""Fixture production module for Python drift scenarios."""


class AuthClient:
    """Client with a couple of methods to mock."""

    def login(self, username: str, password: str) -> str:
        return "token-" + username

    def refresh(self, token: str) -> str:
        return "refreshed-" + token

    def _rotate_keys(self) -> bool:
        return True

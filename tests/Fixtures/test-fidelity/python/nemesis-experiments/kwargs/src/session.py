class Session:
    """`user` was renamed to `username`; the keyword in the test was not."""

    def open(self, username: str, token: str) -> str:
        return username + token

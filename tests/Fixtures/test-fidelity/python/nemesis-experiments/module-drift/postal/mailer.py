from postal.sender import deliver


def send(address: str) -> bool:
    return deliver(address)

from enum import Enum


class Leg(Enum):
    AIR = "air"
    SEA = "sea"


class Router:
    def reroute(self, to: Leg) -> bool:
        return to is not Leg.AIR


def send(router: Router, to: Leg) -> bool:
    return router.reroute(to)

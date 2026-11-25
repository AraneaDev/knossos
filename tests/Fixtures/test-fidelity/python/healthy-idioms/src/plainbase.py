"""The planted twin of the rewriting bases: a plain base rewrites nothing, so its subclass's signature stands."""


class PlainBase:
    def load(self, key):
        return key


class Plain(PlainBase):
    def save(self, session, value):
        return value

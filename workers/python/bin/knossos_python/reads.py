"""Read recording: every file a request read, its hash, and which file or module computation read it."""

import hashlib
from pathlib import Path

from .safe_io import PathWalk, _inside, read_bounded, walk_keys


class ReadRecorder:
    """Record the reads one request makes, for ``input_hashes`` and the reads each result names.

    ``read_hashes`` records every file a request read, keyed by its
    project-relative path, for the result's ``input_hashes``: the SHA-256 of
    the bytes read, or ``None`` when the read was attempted and failed. One
    path can be read more than once in a request: by an importer's resolution
    and by its own scan, or under two module ids. Two reads that disagree, in
    hash or in whether they succeeded, record ``None``, because at least one
    of them differs from what discovery hashed or failed, and either may have
    fed facts. Keeping either value alone would leave the other read
    unverified.
    """

    def __init__(self, root: Path, max_bytes: int) -> None:
        self.root = root
        self.max_bytes = max_bytes
        self.read_hashes: dict[str, str | None] = {}
        # Reads the request shares: the probes that decide the source roots.
        self.shared_reads: set[str] = set()
        # The keys each open file or module computation has read, innermost last.
        self._scopes: list[set[str]] = []

    def record_read(self, relative: str, content_hash: str | None, attributed: bool = True) -> None:
        """Record one read for ``input_hashes``; a disagreeing repeat read records ``None``.

        An attributed read is also named by whatever is being resolved: a
        requested file's read of its own bytes is not, since its
        contribution's ``content_hash`` covers it.
        """
        if relative in self.read_hashes and self.read_hashes[relative] != content_hash:
            content_hash = None
        self.read_hashes[relative] = content_hash
        if attributed:
            self._note({relative})

    def _note(self, keys: set[str]) -> None:
        """Name ``keys`` as read by the innermost open scope, or by the whole request outside any."""
        (self._scopes[-1] if self._scopes else self.shared_reads).update(keys)

    def open_scope(self) -> None:
        """Start collecting the keys one file or module computation reads."""
        self._scopes.append(set())

    def close_scope(self) -> set[str]:
        """Stop collecting for the innermost scope and return what it read."""
        return self._scopes.pop()

    def _in_root_file(self, walked: PathWalk) -> Path | None:
        """The file a walked path opens, when that file lies inside the root.

        Discovery never follows a symlink, so a module reached through a linked
        file or directory is read where its bytes actually live, and keyed
        there by :func:`walk_key`; that is the path whose recorded hash
        describes them. ``None`` for a walk that did not end at a file inside
        the root: nothing outside the root is read.
        """
        if walked.kind != "file" or walked.location is None or not _inside(self.root, walked.location):
            return None
        return Path(walked.location)

    def _record_walk(self, walked: PathWalk, content_hash: str | None) -> None:
        """Record a read, hashed or failed, under every key its walk gives."""
        final, linked = walk_keys(self.root, walked)
        for relative in ([final] if final is not None else []) + linked:
            self.record_read(relative, content_hash)

    def _record_probe(self, walked: PathWalk, present: bool, hashed: bool = True) -> None:
        """Record an existence probe whose answer feeds facts.

        A probe answering absent, or not a file, records ``None`` under every key
        its walk gives: resolution goes on as if the file were not there, so a
        discovered file missing for that moment must fail verification. A probe
        answering present records ``None`` under the linked keys, so a
        discovered path that had become a link is still caught, and the hash
        of the in-root file it found under the location it reached: the file
        being there is a fact, and only a read's key lets its deletion reach
        the reader. ``hashed`` false leaves that location out, for a probe
        that only its absence makes matter. Discovery never reports an absent
        path or a link, so a stable tree is unaffected.
        """
        final, linked = walk_keys(self.root, walked)
        if not present and final is not None:
            self.record_read(final, None)
        for relative in linked:
            self.record_read(relative, None)
        location = self._in_root_file(walked) if present and hashed else None
        if location is None or final is None:
            return
        if final in self.read_hashes:
            self._note({final})
            return
        try:
            source = read_bounded(location, self.max_bytes)
            content_hash = hashlib.sha256(source).hexdigest() if len(source) <= self.max_bytes else None
        except OSError:
            content_hash = None
        self.record_read(final, content_hash)

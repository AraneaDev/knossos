"""Module naming: the project's source roots, and the file and id an import of a module finds."""

import hashlib
import sys
import tomllib
from pathlib import Path, PurePosixPath
from typing import Any

from .exclusions import RESOLUTION_ALLOWED_EXCLUDED, Exclusions
from .reads import ReadRecorder
from .safe_io import S_IFMT, S_IFREG, UnreadableInput, names_python_in_shebang, read_bounded, walk_path

# ``sys.stdlib_module_names`` exists from Python 3.10; an older interpreter
# resolves no script-directory imports rather than risk shadowing the stdlib.
STDLIB_MODULE_NAMES: frozenset[str] = frozenset(getattr(sys, "stdlib_module_names", ()))
# Opens the path that qualifies the id of a file another file's id shadows:
# ``utils.<src/utils.py>``. No dotted import name contains it.
QUALIFIED = ".<"


def module_name(relative: str, strip: int = 0) -> str:
    path = PurePosixPath(relative)
    parts = list(path.with_suffix("").parts)[strip:]
    if parts and parts[-1] == "__init__":
        parts.pop()
    return ".".join(parts) or "__root__"


def _table(data: Any, key: str) -> dict[str, Any]:
    """The table ``data[key]``, or an empty one when either is not a table."""
    value = data.get(key) if isinstance(data, dict) else None
    return value if isinstance(value, dict) else {}


def _list(data: dict[str, Any], key: str) -> list[Any]:
    """The array ``data[key]``, or an empty one when it is not an array."""
    value = data.get(key)
    return value if isinstance(value, list) else []


def _project_directory(value: Any) -> tuple[str, ...] | None:
    """A pyproject's project-relative directory as path segments; ``None`` for the root itself or an unsafe path."""
    # Discovery cannot name a path with a control character, so a probe below
    # one would be a read of no known file.
    if not isinstance(value, str) or "\\" in value or any(ord(c) < 32 or ord(c) == 127 for c in value):
        return None
    path = PurePosixPath(value)
    parts = tuple(part for part in path.parts if part != ".")
    if path.is_absolute() or ".." in parts or not parts:
        return None
    return parts


class ModuleLayout:
    """Where the project's modules live and what each is named, as an import resolves it.

    Every probe and read deciding that is recorded by ``recorder``, the
    index whose reads they are (:class:`ReadRecorder`).
    """

    def __init__(self, recorder: ReadRecorder, exclusions: Exclusions) -> None:
        self._recorder = recorder
        self.root = recorder.root
        self.max_bytes = recorder.max_bytes
        self.exclusions = exclusions
        self._prefixes: list[tuple[str, ...]] | None = None
        # The import that found each path-qualified module, which is how its declarations are read.
        self._spellings: dict[str, str] = {}

    @property
    def prefixes(self) -> list[tuple[str, ...]]:
        """The source roots, detected on first use.

        Detecting them probes the tree, and those probes are recorded, so a
        request that resolves nothing, such as one whose every file was
        refused, reports no reads it did not need. They decide every file's
        module id and every import, so they are the request's shared reads,
        whichever file happened to ask first.
        """
        if self._prefixes is None:
            scopes, self._recorder._scopes = self._recorder._scopes, []
            try:
                self._prefixes = self._source_root_prefixes()
            finally:
                self._recorder._scopes = scopes
        return self._prefixes

    def environment(self) -> str:
        """The digest of the source roots, one per line in search order, the bare root as an empty line.

        A new top-level directory is a source root no import could have
        probed, so the core compares this with the digest a reused
        contribution was derived under instead.
        """
        listed = "\n".join("/".join(prefix) for prefix in self.prefixes)
        return hashlib.sha256(listed.encode("utf-8")).hexdigest()

    def _source_root_prefixes(self) -> list[tuple[str, ...]]:
        """The source roots: the bare root first, then ``src/`` and the pyproject's package directories, sorted.

        ``src/`` is a source root when it is a directory and not a package
        (the src layout). Whether ``src/__init__.py`` is a file decides every
        module id below it, so that probe is recorded (:meth:`_record_probe`).
        Any other top-level directory is an ordinary directory, a package or a
        namespace package below the bare root, so ``app/models/user.py`` is
        ``app.models.user``, the name an import of it spells. The pyproject's
        bytes are not recorded: the core's configuration hash covers every
        ``pyproject.toml``, so editing one rebuilds every Python file.
        """
        roots = {prefix for prefix in self._declared_roots() if not self.exclusions.excludes(prefix)}
        source = self.root / "src"
        if not self.exclusions.excludes(("src",)) and source.is_dir():
            marker = source / "__init__.py"
            present = marker.is_file()
            # Only the marker's absence is a read: deleting it is a layout
            # change the core rebuilds the whole language for, and an edit
            # of its bytes moves no source root.
            self._recorder._record_probe(walk_path(marker), present, hashed=False)
            if not present:
                roots.add(("src",))
        return [(), *sorted(roots)]

    def _declared_roots(self) -> set[tuple[str, ...]]:
        """The directories the project's ``pyproject.toml`` says its packages live in.

        Read from setuptools (``packages.find.where``, the ``""`` entry of
        ``package-dir``), Poetry (``packages[].from``), Hatch (the parent of
        each wheel ``packages`` path) and PDM (``build.package-dir``). A file
        that is missing, excluded, outside the root, over the byte cap or not
        TOML declares none, and so does a path that is absolute or climbs out.

        The probe is recorded, hashed, as the request's shared read: a
        pyproject discovery leaves out (a gitignored one) is no unit, so no
        configuration hash covers it, and editing, creating or deleting it
        must still reach every file.
        """
        walked = walk_path(self.root / "pyproject.toml")
        location = self._recorder._in_root_file(walked)
        self._recorder._record_probe(walked, location is not None)
        try:
            if location is None or self.exclusions.excludes(("pyproject.toml",)):
                return set()
            source = read_bounded(location, self.max_bytes)
            if len(source) > self.max_bytes:
                return set()
            data = tomllib.loads(source.decode("utf-8"))
        except (OSError, UnicodeDecodeError, tomllib.TOMLDecodeError):
            return set()
        tool = _table(data, "tool")
        setuptools = _table(tool, "setuptools")
        declared: list[Any] = list(_list(_table(_table(setuptools, "packages"), "find"), "where"))
        declared.append(_table(setuptools, "package-dir").get(""))
        declared.extend(
            item.get("from") for item in _list(_table(tool, "poetry"), "packages") if isinstance(item, dict)
        )
        wheel = _table(_table(_table(_table(tool, "hatch"), "build"), "targets"), "wheel")
        declared.extend(
            PurePosixPath(item).parent.as_posix() for item in _list(wheel, "packages") if isinstance(item, str)
        )
        declared.append(_table(_table(tool, "pdm"), "build").get("package-dir"))
        return {prefix for prefix in map(_project_directory, declared) if prefix}

    def module_for(self, relative: str) -> str:
        parts = PurePosixPath(relative).parts
        best: tuple[str, ...] = ()
        for prefix in self.prefixes:
            if len(prefix) < len(parts) and parts[: len(prefix)] == prefix and len(prefix) > len(best):
                best = prefix
        return module_name(relative, len(best))

    def module_file(self, module: str, hash_found: bool = True) -> Path | None:
        """The file ``module`` resolves to, or ``None``; every candidate tried is recorded.

        A path-qualified id (:meth:`file_identity`) names a file no import
        spells, so nothing is probed for it. ``hash_found`` is whether a
        probe that finds a candidate hashes it (:meth:`is_project_file`).
        """
        parts = module.split(".")
        if not parts or "" in parts or QUALIFIED in module:
            return None
        for prefix in self.prefixes:
            base = self.root.joinpath(*prefix).joinpath(*parts)
            # Prefer the package (``mod/__init__.py``) over a same-named module
            # (``mod.py``) so a colliding pair resolves to a single stable id.
            for candidate in (base / "__init__.py", base.with_suffix(".py")):
                if self.is_project_file(candidate, hash_found):
                    return candidate
            # Then the suffixless file itself. Discovery admits an extensionless
            # script on its shebang, so such a file is scanned and its symbols
            # are emitted, but a name derived from it round-trips only to
            # ``<name>.py`` — which does not exist. Its own declarations were
            # therefore never found, every name inside it went unresolved, and
            # so a script's ``main()`` calling its ``check()`` produced no edge
            # and both read as unreferenced. Gated on the same shebang rule
            # discovery used, so ``import config`` cannot bind to a shell script
            # named ``config``.
            if self._is_python_script(base, hash_found):
                return base
            # Last, a stub with no module beside it: the declarations of an
            # extension module, which is all an importer can resolve against.
            for candidate in (base / "__init__.pyi", base.with_suffix(".pyi")):
                if self.is_project_file(candidate, hash_found):
                    return candidate
        return None

    def file_identity(self, relative: str, absolute: Path, found_as: str | None = None) -> tuple[str, str, Path | None]:
        """How the module file at ``relative`` is named: its location, its id, and the file its location finds.

        The location is :meth:`module_for`'s dotted name, which relative
        imports climb from. When an import of the location finds another file
        (a ``mod.py`` beside ``mod/__init__.py``, a module at the bare root and
        one in ``src/``, a stub beside its module), this file is named
        ``<location>.<relative>`` instead, so no two files share one id; the
        probes deciding that are recorded. A stub with no module beside it owns
        its location. A location no import finds names the file as it is, its
        own scan then declares nothing under it, and the owner returned is
        ``None``. ``found_as`` is a module whose import just found this file;
        when it is the location, the location is not probed again.
        """
        location = self.module_for(relative)
        owner = Path(absolute) if found_as == location else self.module_file(location)
        if owner is None or owner.resolve() == absolute.resolve():
            return location, location, owner
        return location, f"{location}{QUALIFIED}{relative}>", owner

    def canonical_module(self, module: str) -> str:
        """The id of the file an import of ``module`` finds, or ``module`` when it finds none.

        ``import src.shop.cart`` finds ``src/shop/cart.py``, whose id is
        ``shop.cart`` when ``src/`` is a source root, so the import names
        ``shop.cart``, as that file's own scan does.
        """
        path = self.module_file(module)
        if path is None:
            return module
        name = self.file_identity(path.relative_to(self.root).as_posix(), path, module)[1]
        if QUALIFIED in name:
            # Only an import spells a path-qualified module, so its declarations are found through that spelling.
            self._spellings.setdefault(name, module)
        return name

    def spelling(self, name: str) -> str | None:
        """The import that found the path-qualified module ``name``, if one did (:meth:`canonical_module`)."""
        return self._spellings.get(name)

    def script_sibling_module(self, importer: str, module: str) -> str | None:
        """How to spell the module an absolute import names in the importer's own directory, if one is there.

        Running ``python3 app/main.py`` puts ``app/`` first on ``sys.path``, so
        the script's bare ``import monitor`` loads ``app/monitor.py`` whether or
        not ``app/`` is a package, and pytest does the same for a test module
        in a directory that is no package. A module something else imports has
        no directory of its own on the path. A name the standard library owns
        is left alone, so a sibling ``json.py`` never captures ``import json``.
        """
        parts = module.split(".")
        if not parts or "" in parts or parts[0] in STDLIB_MODULE_NAMES:
            return None
        directory = PurePosixPath(importer).parent
        if not directory.parts:
            return None  # the bare root is already a source root
        base = self.root.joinpath(*directory.parts, *parts)
        for candidate in (
            base / "__init__.py",
            base.with_suffix(".py"),
            base / "__init__.pyi",
            base.with_suffix(".pyi"),
        ):
            if self.is_project_file(candidate):
                # Spelled from the bare root, which is searched first, so an import of it finds this very file.
                return module_name(candidate.relative_to(self.root).as_posix())
        return None

    def is_package_directory(self, directory: PurePosixPath) -> bool:
        """Whether the project directory ``directory`` holds an ``__init__.py``; the probe is recorded."""
        marker = self.root.joinpath(*directory.parts, "__init__.py")
        present = marker.is_file()
        self._recorder._record_probe(walk_path(marker), present)
        return present

    def _is_python_script(self, path: Path, hash_found: bool = True) -> bool:
        """Whether a suffixless path is a project file whose shebang names Python.

        A file its shebang rules out was still decided by its bytes, so it
        is recorded by its hash, which an edit that makes it Python changes.
        """
        if path.suffix or not self.is_project_file(path, hash_found):
            return False
        try:
            named = names_python_in_shebang(path)
        except UnreadableInput:
            named = False
        if not named:
            self._recorder._record_probe(walk_path(path), True)
        return named

    def is_project_file(self, path: Path, hash_found: bool = True) -> bool:
        """Whether ``path`` is a module file this index may read.

        A candidate that is absent, not a regular file (a FIFO, say), or
        refused (it links out of the root, it is over the byte cap, or it
        cannot be examined) is left out of resolution, so every importer's facts are computed as if it did not
        exist; it is recorded as a failed read under the keys a read of it
        would go under. A stable layout never trips over that: discovery
        reports no absent path, symlink or over-cap file, and the core accepts
        a ``None`` at commit for an undiscovered path that is absent, not a
        regular file, reached through a link, or over the cap, while a
        discovered file that became one mid-scan fails verification. An
        accepted candidate is recorded as a probe that found it present,
        hashed unless ``hash_found`` is false: a caller that reads the file
        next records its hash itself.
        """
        # Discovery does not enter excluded directories, but import resolution
        # also tries candidates derived from dotted names. Reject those lexical
        # paths before statting them, so an import such as ``site.foo`` cannot
        # pull generated output back into the scan through the bare root prefix.
        try:
            parts = path.relative_to(self.root).parts
            allowed = [index for index, segment in enumerate(parts) if segment in RESOLUTION_ALLOWED_EXCLUDED]
            # Below a dependency tree its own layout governs, not the project's.
            governed = parts[: allowed[0]] if allowed else parts
            if governed and self.exclusions.excludes(governed):
                return False
        except ValueError:
            pass
        walked = walk_path(path)
        location = self._recorder._in_root_file(walked)
        try:
            status = None if location is None else location.stat()
            if status is not None and status.st_mode & S_IFMT == S_IFREG and status.st_size <= self.max_bytes:
                self._recorder._record_probe(walked, True, hash_found)
                return True
        except OSError:
            pass
        self._recorder._record_walk(walked, None)
        return False

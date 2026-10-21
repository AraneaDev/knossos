"""The filesystem-backed, batch-independent module index; module naming is :mod:`module_layout`'s."""

import ast
import hashlib
from pathlib import Path, PurePosixPath

from .ast_helpers import (
    absolute_import,
    assigned_declaration,
    declared_protocols,
    dotted,
    module_statements,
    optional_inner,
    ref,
    router_constructors,
    top_level_declarations,
)
from .exclusions import Exclusions
from .fixtures import path_loading_fixtures
from .module_layout import QUALIFIED, ModuleLayout
from .reads import ReadRecorder
from .safe_io import read_bounded, walk_path


class ProjectModuleIndex(ReadRecorder):
    """Filesystem-backed, batch-independent module resolution.

    Import and reference targets must be identical no matter how a scan request
    was chunked, so resolution is grounded in the project's on-disk layout — not
    in whichever files happen to share the current batch. Source roots (bare root
    plus non-package top-level directories such as ``src/``) are detected once,
    and each referenced module's top-level declarations are parsed lazily and
    memoized. Only files that live under the validated root and stay within the
    byte cap are read, and every read is recorded (:class:`ReadRecorder`).
    """

    def __init__(
        self,
        root: Path,
        max_bytes: int,
        exclusions: Exclusions | None = None,
        source_files: frozenset[str] | None = None,
    ) -> None:
        super().__init__(root, max_bytes)
        self.exclusions = exclusions or Exclusions()
        # Every discovered Python file, when the request lists them: a module
        # among them has a contribution of its own that names what it read.
        self.source_files = source_files
        # Reads made for a discovered module, which its own contribution names.
        self.unattributed_reads: set[str] = set()
        # What a module's declarations cost an importer that uses them.
        self._exposed: dict[str, set[str]] = {}
        # Where the modules live and what each is named.
        self.layout = ModuleLayout(self, self.exclusions)
        self._cache: dict[str, dict[str, str]] = {}
        self._protocols: set[str] = set()
        # The project class each function or method returns, by its ref, as
        # its return annotation names it (:meth:`return_type`).
        self._returns: dict[str, str] = {}
        # Each conftest.py looked for: its fixtures that load a module by path, and what reading it cost.
        self._conftests: dict[str, tuple[dict[str, str], set[str]]] = {}
        # The project classes that declare their own ``__init__``, by ref (:meth:`initialiser`).
        self._initialised: set[str] = set()
        # The project class each class-body annotation names, and the project
        # classes each class derives from, by the class's canonical name
        # (:meth:`class_attributes`).
        self._class_attributes: dict[str, dict[str, str]] = {}
        self._class_bases: dict[str, list[str]] = {}
        # Each module's classes as written, until one is asked about, and
        # then what resolving them read (:meth:`_class_attribute_reads`).
        self._class_sources: dict[
            str,
            tuple[
                list[tuple[str, list[tuple[str, str | None]], list[str | None]]],
                dict[str, str],
                dict[str, str],
                list[ast.Import | ast.ImportFrom],
                str,
                bool,
            ],
        ] = {}
        self._class_reads: dict[str, set[str]] = {}
        # The modules a discovered file's own scan declares, which names what
        # resolving their classes reads.
        self._self_scanned: set[str] = set()

    def environment(self) -> str:
        """The digest of the source roots (:meth:`ModuleLayout.environment`)."""
        return self.layout.environment()

    def module_for(self, relative: str) -> str:
        """The dotted location of the file at ``relative`` (:meth:`ModuleLayout.module_for`)."""
        return self.layout.module_for(relative)

    def module_file(self, module: str) -> Path | None:
        """The file ``module`` resolves to (:meth:`ModuleLayout.module_file`)."""
        return self.layout.module_file(module)

    def file_identity(self, relative: str, absolute: Path, found_as: str | None = None) -> tuple[str, str, Path | None]:
        """How the module file at ``relative`` is named (:meth:`ModuleLayout.file_identity`)."""
        return self.layout.file_identity(relative, absolute, found_as)

    def canonical_module(self, module: str) -> str:
        """The id of the file an import of ``module`` finds (:meth:`ModuleLayout.canonical_module`)."""
        return self.layout.canonical_module(module)

    def script_sibling_module(self, importer: str, module: str) -> str | None:
        """The module an import names in the importer's directory (:meth:`ModuleLayout.script_sibling_module`)."""
        return self.layout.script_sibling_module(importer, module)

    def is_package_directory(self, directory: PurePosixPath) -> bool:
        """Whether ``directory`` holds an ``__init__.py`` (:meth:`ModuleLayout.is_package_directory`)."""
        return self.layout.is_package_directory(directory)

    def path_loaded_fixtures(self, relative: str) -> dict[str, str]:
        """The fixtures a test module at ``relative`` can take that load a project file by path, with its module.

        pytest hands a test the fixtures of every ``conftest.py`` from the root
        down to the test's own directory, the nearest one winning
        (:func:`path_loading_fixtures` reads each). Every conftest looked for
        is recorded as read by the open scope, found or not.
        """
        directory = PurePosixPath(relative).parent
        fixtures: dict[str, str] = {}
        for folder in reversed([directory, *directory.parents]):
            fixtures.update(self._conftest_fixtures((folder / "conftest.py").as_posix()))
        return fixtures

    def _conftest_fixtures(self, relative: str) -> dict[str, str]:
        """The path-loading fixtures of the conftest at ``relative``, memoised; its reads are named each time."""
        cached = self._conftests.get(relative)
        if cached is None:
            self.open_scope()
            try:
                fixtures = self._read_conftest(relative)
            finally:
                keys = self.close_scope()
            cached = (fixtures, keys)
            self._conftests[relative] = cached
        self._note(cached[1])
        return cached[0]

    def _read_conftest(self, relative: str) -> dict[str, str]:
        """Read the conftest at ``relative``, recording probe and read, for its fixtures by the module each loads."""
        path = self.root / relative
        if not self.layout.is_project_file(path, hash_found=False):
            return {}
        walked = walk_path(path)
        location = self._in_root_file(walked)
        try:
            source = None if location is None else read_bounded(location, self.max_bytes)
        except OSError:
            source = None
        if source is None or len(source) > self.max_bytes:
            self._record_walk(walked, None)
            return {}
        self._record_walk(walked, hashlib.sha256(source).hexdigest())
        try:
            tree = ast.parse(source)
        except (SyntaxError, ValueError, RecursionError):
            return {}
        modules: dict[str, str] = {}
        for name, loaded in path_loading_fixtures(tree, PurePosixPath(relative)).items():
            if loaded.suffix == ".py" and self.layout.is_project_file(self.root / loaded):
                modules[name] = self.module_for(loaded.as_posix())
        return modules

    def module_declarations(self, module: str) -> dict[str, str]:
        """The top-level declarations of ``module``, memoised, named as read by whatever asked.

        Whoever asks, first or from the memo, names what finding and reading
        the module cost (:meth:`_expose`). The declarations are named by the
        file found (:meth:`file_identity`), so ``src.shop.cart`` and
        ``shop.cart`` give the same ids. What the module re-exports was read
        for the module's own sake: a discovered module whose own scan derives
        the same declarations names it in its own contribution, so it is
        reported unattributed rather than charged to every importer; any
        other module passes it on to the importer.
        """
        cached = self._cache.get(module)
        if cached is None and QUALIFIED in module:
            spelled = self.layout.spelling(module)
            return {} if spelled is None else self.module_declarations(spelled)
        if cached is None:
            self.open_scope()
            try:
                path, tree = self._read_module(module)
                location, name, owner = (
                    (module, module, None)
                    if path is None
                    else self.file_identity(path.relative_to(self.root).as_posix(), path, module)
                )
            except BaseException:
                # What was read before the failure is in input_hashes, so the
                # caller names it, and the failure reaches the file it fails.
                self._note(self.close_scope())
                raise
            located = self.close_scope()
            self._exposed[module] = located
            placeholder: dict[str, str] = {}
            cached = placeholder
            self._cache[module] = cached
            if path is not None and tree is not None:
                self.open_scope()
                try:
                    cached = self._declare(tree, name, path.stem == "__init__", location, module)
                except BaseException:
                    located |= self.close_scope()
                    self._note(located)
                    # Left in place, the empty placeholder would answer every
                    # later importer in the request with no declarations at all.
                    if self._cache.get(module) is placeholder:
                        del self._cache[module]
                    raise
                declared = self.close_scope()
                derived = owner is not None
                if derived and self._owns(path):
                    self.unattributed_reads |= declared
                    self._self_scanned.add(name)
                else:
                    located |= declared
                if derived and name != module:
                    # Asked for under the file's own id later, the memo answers.
                    self._cache.setdefault(name, cached)
                    self._exposed.setdefault(name, located)
        self._expose(module)
        return cached

    def _expose(self, module: str) -> None:
        """Name what ``module``'s declarations cost as read by the open scope."""
        self._note(self._exposed.get(module, set()))

    def _owns(self, path: Path) -> bool:
        """Whether ``path`` has a contribution of its own that names what it re-exports.

        Only a discovered file does, and only one whose own scan declares its
        id; without the request's list of discovered files, none is assumed to.
        """
        return self.source_files is not None and path.relative_to(self.root).as_posix() in self.source_files

    def _read_module(self, module: str) -> tuple[Path | None, ast.Module | None]:
        """Find and parse ``module``'s file, recording every probe and read; ``None`` parts when it cannot."""
        # The read below hashes the file module_file finds, so the probe
        # that found it does not read it first.
        path = self.layout.module_file(module, hash_found=False)
        # One walk decides whether the module may be read, the file its bytes
        # are read from, and the key the read goes under, so none can disagree.
        walked = None if path is None else walk_path(path)
        location = None if walked is None else self._in_root_file(walked)
        if walked is None:
            return None, None
        if location is None:
            # Accepted by module_file, then retargeted out of the root or gone
            # before this read resolved it: not read, and recorded as refused.
            self._record_walk(walked, None)
            return path, None
        try:
            source = read_bounded(location, self.max_bytes)
        except OSError:
            self._record_walk(walked, None)
            return path, None
        if len(source) > self.max_bytes:
            # Grew past the cap after _is_project_file checked it.
            self._record_walk(walked, None)
            return path, None
        # Hashed before parsing, so a module that fails to parse still
        # reports the bytes this request saw.
        self._record_walk(walked, hashlib.sha256(source).hexdigest())
        try:
            return path, ast.parse(source)
        except (SyntaxError, ValueError, RecursionError):
            return path, None

    def _declare(
        self, tree: ast.Module, name: str, is_package: bool, location: str | None = None, key: str | None = None
    ) -> dict[str, str]:
        """Derive and memoise the declarations of the module file ``tree`` parsed.

        ``name`` is the id its symbols take, ``location`` the dotted name its
        relative imports climb from, and ``key`` the memo entry; each defaults
        to ``name``. Memoised before the re-exports are followed, so modules
        importing each other resolve without recursing.
        """
        location = location or name
        declarations = top_level_declarations(tree, name)
        self._protocols |= declared_protocols(tree, name)
        self._initialised |= {
            ref("class", f"{name}.{child.name}")
            for child in module_statements(tree)
            if isinstance(child, ast.ClassDef)
            and any(
                isinstance(item, (ast.FunctionDef, ast.AsyncFunctionDef)) and item.name == "__init__"
                for item in child.body
            )
        }
        self._cache[key or name] = declarations
        try:
            self._add_reexports(tree, location, is_package, declarations)
            imported = self._add_instances(tree, location, is_package, declarations, name)
            self._add_returns(tree, name, declarations, imported)
            self._add_class_attributes(tree, name, declarations, imported, location, is_package)
        except BaseException:
            # Left in place, the partial entry would answer every later
            # importer in the request with whatever was added before the failure.
            self._cache.pop(key or name, None)
            raise
        return declarations

    def is_protocol(self, owner: str) -> bool:
        """Whether ``owner`` is a structural protocol a project module declares."""
        self.module_declarations(owner.rpartition(".")[0])
        return owner in self._protocols

    def _add_reexports(self, tree: ast.Module, module: str, is_package: bool, declarations: dict[str, str]) -> None:
        """Add the project declarations ``tree`` imports to its declarations.

        A name a module imports is an attribute of that module, which is how a
        package re-exports what its submodules declare: ``from app import
        config; config.staging_dir()`` reaches ``app.config.paths.staging_dir``
        through ``app/config/__init__.py``. A star import brings the source's
        public names. A module's own declaration of a name wins. The
        declarations are cached before this runs, so modules importing each
        other resolve without recursing.
        """
        for child in module_statements(tree):
            if not isinstance(child, ast.ImportFrom):
                continue
            source = absolute_import(module, child.level, child.module, is_package)
            if not source:
                continue
            exported = self.module_declarations(source)
            for alias in child.names:
                if alias.name == "*":
                    for name, target in exported.items():
                        if not name.startswith("_"):
                            declarations.setdefault(name, target)
                    continue
                reexported = exported.get(alias.name)
                if reexported is not None:
                    declarations.setdefault(alias.asname or alias.name, reexported)

    def _add_returns(self, tree: ast.Module, name: str, declarations: dict[str, str], imported: dict[str, str]) -> None:
        """Record the project class each top-level function and method says it returns.

        ``def load(pk) -> Document`` hands its caller a ``Document``, so
        ``load(pk).save()`` calls ``Document.save``. Only an annotation naming a
        class this module declares or imports counts.
        """

        def returned(function: ast.FunctionDef | ast.AsyncFunctionDef) -> str | None:
            annotation = optional_inner(function.returns)
            if isinstance(annotation, ast.Constant) and isinstance(annotation.value, str):
                try:
                    annotation = ast.parse(annotation.value, mode="eval").body
                except SyntaxError:
                    return None
            named = dotted(annotation) if annotation is not None else None
            target = None if named is None else declarations.get(named) or imported.get(named)
            return target.removeprefix("py:class:") if target is not None and target.startswith("py:class:") else None

        for child in module_statements(tree):
            if isinstance(child, (ast.FunctionDef, ast.AsyncFunctionDef)):
                held = returned(child)
                if held is not None:
                    self._returns[ref("function", f"{name}.{child.name}")] = held
            elif isinstance(child, ast.ClassDef):
                for member in child.body:
                    if isinstance(member, (ast.FunctionDef, ast.AsyncFunctionDef)):
                        held = returned(member)
                        if held is not None:
                            self._returns[ref("method", f"{name}.{child.name}::{member.name}")] = held

    def _add_class_attributes(
        self,
        tree: ast.Module,
        name: str,
        declarations: dict[str, str],
        imported: dict[str, str],
        location: str,
        is_package: bool,
    ) -> None:
        """Keep what each top-level class annotates its attributes as, and the classes it derives from, as written.

        They are resolved when the module's own scan adopts it, or when a
        class of the module is first asked about (:meth:`_class_attribute_reads`), not here: a dotted name reads the
        module it goes through, and reading modules while this one is being
        declared would make declaring a module recurse through every module
        its classes name.
        """
        classes = [
            (
                f"{name}.{child.name}",
                [
                    (item.target.id, dotted(inner) if (inner := optional_inner(item.annotation)) is not None else None)
                    for item in child.body
                    if isinstance(item, ast.AnnAssign) and isinstance(item.target, ast.Name)
                ],
                [dotted(base) for base in child.bases],
            )
            for child in module_statements(tree)
            if isinstance(child, ast.ClassDef)
        ]
        if not classes:
            return
        imports = [child for child in module_statements(tree) if isinstance(child, (ast.Import, ast.ImportFrom))]
        self._class_sources[name] = (classes, declarations, imported, imports, location, is_package)

    def _class_attribute_reads(self, module: str) -> set[str]:
        """Resolve the classes :meth:`_add_class_attributes` kept for ``module``, once, and return what that read.

        Only an annotation or a base naming a class the module declares or
        imports counts, as for :meth:`_add_returns`, or one named through a
        module it imports (``models.Base``, ``m.Base``). The reads are the
        module's own, so the caller decides who they are named by.
        """
        if module in self._class_reads:
            return self._class_reads[module]
        source = self._class_sources.get(module)
        if source is None:
            return set()
        classes, declarations, imported, imports, location, is_package = source
        self.open_scope()
        try:
            # Only the names a dotted base or annotation starts with are looked
            # up as modules, so a module naming none reads nothing more.
            heads = {
                named.split(".")[0]
                for _, attributes, bases in classes
                for named in [*(named for _, named in attributes), *bases]
                if named is not None and "." in named
            }
            modules = self._module_aliases(imports, location, is_package, heads) if heads else {}

            def named_class(named: str | None) -> str | None:
                target = None if named is None else declarations.get(named) or imported.get(named)
                if target is None and named is not None and "." in named:
                    target = self._module_qualified(named, modules)
                return (
                    target.removeprefix("py:class:") if target is not None and target.startswith("py:class:") else None
                )

            for canonical, attributes, bases in classes:
                held_by = {attribute: named_class(named) for attribute, named in attributes}
                self._class_attributes[canonical] = {
                    attribute: held for attribute, held in held_by.items() if held is not None
                }
                self._class_bases[canonical] = [base for base in map(named_class, bases) if base is not None]
        except BaseException:
            self._note(self.close_scope())
            raise
        reads = self.close_scope()
        self._class_reads[module] = reads
        del self._class_sources[module]
        return reads

    def _module_aliases(
        self, imports: list[ast.Import | ast.ImportFrom], location: str, is_package: bool, heads: set[str]
    ) -> dict[str, str]:
        """The modules a module's top-level ``imports`` bind to the names in ``heads``.

        ``import pkg.models as m`` binds ``m`` to ``pkg.models``, ``import
        pkg.models`` binds ``pkg``, and ``from pkg import models`` binds
        ``models`` when ``pkg`` declares no ``models`` of its own and the
        submodule exists.
        """
        modules: dict[str, str] = {}
        for child in imports:
            if isinstance(child, ast.Import):
                for alias in child.names:
                    top = alias.name.split(".")[0]
                    if (alias.asname or top) in heads:
                        modules[alias.asname or top] = alias.name if alias.asname else top
            elif isinstance(child, ast.ImportFrom):
                source = absolute_import(location, child.level, child.module, is_package)
                if not source:
                    continue
                for alias in child.names:
                    if (alias.asname or alias.name) not in heads:
                        continue
                    if alias.name == "*" or alias.name in self.module_declarations(source):
                        continue
                    if self.module_file(f"{source}.{alias.name}") is not None:
                        modules[alias.asname or alias.name] = f"{source}.{alias.name}"
        return modules

    def _module_qualified(self, named: str, modules: dict[str, str]) -> str | None:
        """What ``<alias>.<path>.<Name>`` names: ``Name`` as the module the alias and path reach declares it."""
        first, _, rest = named.partition(".")
        module = modules.get(first)
        if module is None:
            return None
        owner, _, member = f"{module}.{rest}".rpartition(".")
        return self.module_declarations(owner).get(member)

    def class_attributes(self, held: str) -> tuple[dict[str, str], list[str]]:
        """The attributes the project class ``held`` annotates in its body, and the project classes it derives from.

        Reads the class's module first, through :meth:`module_declarations`,
        so what that cost is named as read by whoever asked.
        """
        module = held.rpartition(".")[0]
        self.module_declarations(module)
        reads = self._class_attribute_reads(module)
        # A discovered module's own scan names them, as it does its re-exports.
        if module in self._self_scanned:
            self.unattributed_reads |= reads
        else:
            self._note(reads)
        return self._class_attributes.get(held, {}), self._class_bases.get(held, [])

    def initialiser(self, target: str) -> str | None:
        """The ``__init__`` a call of the class ``target`` runs, when its module was read and the class declares one."""
        if target not in self._initialised:
            return None
        return ref("method", f"{target.removeprefix('py:class:')}::__init__")

    def return_type(self, target: str) -> str | None:
        """The project class the function or method ``target`` returns, once its module was read."""
        return self._returns.get(target)

    def _add_instances(
        self, tree: ast.Module, module: str, is_package: bool, declarations: dict[str, str], name: str
    ) -> dict[str, str]:
        """Add the module-level instances ``tree`` creates to its declarations.

        ``user_repo = UserRepository()`` at module level is how a service hands
        out one shared object, and the rest of the codebase imports that name.
        Recorded as ``py:instance:<class>`` so an importer can type a call on
        it. The class is found among the module's own classes or its
        ``from ... import`` names; anything else is left out. A FastAPI
        ``APIRouter`` or Flask ``Blueprint`` is recorded as the ``py:router``
        node its own scan emits (``name`` is the module's id), so a mount in
        another module names it. The declarations are cached before this
        runs, so two modules importing each other resolve without recursing.
        Returns the project classes the module imports by name, which its
        return annotations may name too (:meth:`_add_returns`).
        """
        routers = router_constructors(tree)
        imported: dict[str, str] = {}
        for child in module_statements(tree):
            if not isinstance(child, ast.ImportFrom):
                continue
            source = absolute_import(module, child.level, child.module, is_package)
            if not source:
                continue
            for alias in child.names:
                if alias.name == "*":
                    continue
                target = self.module_declarations(source).get(alias.name)
                if target is not None and target.startswith("py:class:"):
                    imported[alias.asname or alias.name] = target
        for child in module_statements(tree):
            variable, constructor = None, None
            if isinstance(child, ast.Assign) and len(child.targets) == 1 and isinstance(child.targets[0], ast.Name):
                variable, constructor = child.targets[0].id, child.value
            elif isinstance(child, ast.AnnAssign) and isinstance(child.target, ast.Name):
                variable, constructor = child.target.id, child.value
            if variable is None or variable in declarations:
                continue
            if isinstance(constructor, ast.Call) and dotted(constructor.func) in routers:
                declarations[variable] = ref("router", f"{name}.{variable}")
                continue
            value = assigned_declaration(constructor, declarations, imported)
            if value is not None:
                declarations[variable] = value
        return imported

    def adopt_parsed(self, absolute: Path, relative: str, tree: ast.Module) -> tuple[str, str | None]:
        """Make a scanned file's own declarations come from the tree just parsed; return its id and its id's owner.

        ``module_declarations`` reads a module's file on its own, so an earlier
        file in the batch that imports this one may have cached declarations
        from bytes other than the ones this scan hashed. Overwriting the entry
        with the hashed tree ties the file's own local resolution to its
        ``content_hash``. A file whose location another file owns (see
        :meth:`file_identity`) is declared under its path-qualified id, which
        no import shares, so seeding it never makes another importer's targets
        depend on batch order. The owner returned is that other file's path,
        ``None`` when this file owns its id or no import finds it.
        """
        self.open_scope()
        try:
            location, name, owner = self.file_identity(relative, absolute)
        finally:
            located = self.close_scope()
        # Which file the id resolves to is this file's fact too: a package
        # added beside it takes the id over.
        self._note(located)
        if owner is None:
            return name, None
        # An importer later in the batch names this file, as a read would.
        self._exposed[name] = located
        self._declare(tree, name, PurePosixPath(relative).stem == "__init__", location)
        # Resolved again from this tree, and named as this file's reads.
        self._class_reads.pop(name, None)
        self._self_scanned.add(name)
        self._note(self._class_attribute_reads(name))
        return name, None if name == location else owner.relative_to(self.root).as_posix()

    def collides(self, absolute: Path, is_package: bool) -> bool:
        """A ``mod.py``/``mod/__init__.py`` pair maps to the same module id.

        The answer decides the file's module identity, so the probe is recorded
        (:meth:`_record_probe`).
        """
        if is_package:
            competitor = absolute.parent.with_suffix(".py")
        else:
            competitor = absolute.with_suffix("") / "__init__.py"
        try:
            present = competitor.is_file()
        except OSError:
            present = False
        self._record_probe(walk_path(competitor), present)
        return present

# Python

Knossos scans Python through the same out-of-process scanner protocol as the
other languages. The worker needs Python 3.11 or newer. The bundled worker uses
only the standard-library `ast` module and starts with the isolated,
bytecode-disabled flags `-I -B`, plus `-W ignore::SyntaxWarning` so that the
parser's warnings about invalid escapes in your source stay off stderr. It
never imports or runs your project.

## What the scanner reads

- `pyproject.toml`: the project unit and its name, PEP 621 dependencies and
  optional dependencies, Poetry's `[tool.poetry.dependencies]`,
  `dev-dependencies` and `group.<name>.dependencies` tables, and the entry
  points under `[project.scripts]` or `[tool.poetry.scripts]`
- `requirements.txt` and `requirements-*.txt`, for projects that keep
  dependencies outside `pyproject.toml`
- `.py` source files and `.pyi` stubs
- packages, identified by `__init__.py`
- ordinary and relative imports, with their aliases. An import under
  `if TYPE_CHECKING:` is type-only: it runs for the type checker only, so a
  cycle closed by nothing but such imports is not reported, unless the same
  module is also imported at runtime
- the source roots your packages live in: `src/` when it holds no
  `__init__.py`, and the directories `pyproject.toml` declares through
  setuptools (`packages.find.where`, the `""` entry of `package-dir`), Poetry
  (`packages[].from`), Hatch (wheel `packages`) or PDM (`build.package-dir`)

A console script such as `shop.cli:main` maps to the exact path `shop/cli.py`,
and a `.py` file listed in `[tool.vulture] paths` (a whitelist vulture reads as
source) is an entry point too. The mapping only applies when that path produced
a scanner node, so a manifest cannot invent an entry point.

Discovery skips `.venv`, `venv`, `__pycache__`, `.tox`, `.mypy_cache` and
`.pytest_cache` by default, along with the other directories every language
skips.

## Module names

A module is named by its dotted path below the source root it sits in, and
the bare project root is always the first source root. So `src/shop/cart.py`
in a src layout is `shop.cart`, while `app/models/user.py` is
`app.models.user` whether or not `app/` holds an `__init__.py`: any other
top-level directory is a package or a namespace package, not a source root.

An import names the module by the file it finds, so `import shop.cart` and
`import src.shop.cart` both reach `shop.cart`. A bare import in a script, or
in a module whose directory holds no `__init__.py` (such as a test module
pytest runs), also finds the file beside it, because that directory is first
on `sys.path` when it runs. A file that puts a directory on the path itself,
with `sys.path.insert(..)` or `sys.path.append(..)` of a path built from
`__file__` (`Path(__file__).resolve().parents[1]`, `.parent / "lib"`, or
`os.path.dirname`, `abspath` and `join`), finds a bare import in that
directory too, when no source root has the module.

Two files never share a module id. When an import of a file's name finds
another file (`utils.py` at the root and `src/utils.py`, or `mod.py` beside a
`mod/` package), the file the import finds keeps the name and the other one is
named by its path, `utils.<src/utils.py>`, with a `PY_MODULE_ID_COLLISION`
warning. A stub beside its module (`mod.pyi` beside `mod.py`) is named the
same way, `mod.<mod.pyi>`, without a warning. A stub with no module beside it,
such as the stub of an extension module, is the module an import finds, so
its names resolve. Every symbol of a `.pyi` file is a declaration that the
dead-code analysis leaves out.

## What ends up in the graph

The worker emits modules, packages, classes, functions, methods, containment,
imports, inheritance and the calls it can resolve statically. Async status and
decorator names are kept as node attributes. A reference to a function or class
that is not a call, such as one passed as an argument, becomes a `references`
edge.

A call is attributed to a class when its receiver's class is known: a
parameter or attribute annotated with the class, including `Cls | None`,
`Optional[Cls]` and any of them written as a string, or a value built as `Cls()`,
including the default in `self.x = injected or Cls()`. An attribute annotated
in the class body (`index: Cls`, with or without a value, as a dataclass or a
mixin declares it) types `self.index` in every method of that class, unless a
method assigns it a different class the scan can see. A subclass inherits the
annotation from its bases and mixins, in this module or another, and the
nearest class that annotates the attribute decides. A base counts whether it is
imported by name (`class C(Base)`) or named through its module
(`class C(models.Base)` after `import models` or `from app import models`).

A call through a project class's own name, `Cls.build()` or
`models.Cls.build()`, is attributed to that class's static, class or plain
method. A member the class inherits is named on `Cls`, and the core resolves it
to the base class that declares it.

A cross-file reference resolves when the declaration is in the same scan
request. Anything else stays an explicit unresolved or external fact.

Some code is never called or imported by name. The scanner marks it, so it stays
off the [dead-code candidates](../guides/dead-code-candidates.md) list:

- A module with a shebang, or with an `if __name__ == "__main__":` guard, is
  `executable`. So is a module that builds a served app at module level:
  `FastAPI()`, `Flask(__name__)`, Starlette, Quart, Litestar, or Django's
  `get_wsgi_application()` and `get_asgi_application()`. A server such as
  uvicorn loads that module by name.
- A function a decorator hands to an object (`@queue.register("scan")`,
  `@bus.on`) is `runtime_invoked`.
- A module whose file name no import can spell (`029_seed.py`) is
  `runtime_invoked`, together with its public top-level functions, because a
  loader reads it by path.

A name a package imports is an attribute of that package, so
`config.staging_dir()` reaches the submodule that `config/__init__.py`
re-exports it from. A class declared under `if TYPE_CHECKING:` is a module-level
name.

## Frameworks

The worker recognizes a framework from what a file imports. It reads no
framework hint from the request, so recognizable code is enriched even when the
dependency metadata is incomplete or absent.

| Framework | Recognized source                                                                                                                                                                                                                                                                                                  | Graph facts                                                                                                                                                                                                                                                                                                                                                                                                       |
| --------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| FastAPI   | `FastAPI` and `APIRouter` objects, HTTP decorators, `Depends` in parameters and decorator lists, `include_router`, `add_middleware`                                                                                                                                                                                | `route` nodes, `routes_to`, `depends_on`, `mounts` and `uses_middleware` edges, and the `fastapi.route_handler` role                                                                                                                                                                                                                                                                                              |
| Django    | `path` and `re_path` in `urlpatterns` (also `+=` and lists joined with `+`), `include()`, DRF `DefaultRouter`/`SimpleRouter` registrations, function and class-based views, `models.Model` bases, `__call__` middleware, `INSTALLED_APPS`, `MIDDLEWARE`, `ROOT_URLCONF`, `ASGI_APPLICATION` and `WSGI_APPLICATION` | `route` and `setting` nodes, `routes_to` and `configures` edges, and the `django.model`, `django.view` and `django.middleware` roles                                                                                                                                                                                                                                                                              |
| Flask     | `Flask` and `Blueprint` objects, `@app.route` and `@blueprint.route`, `register_blueprint`, `add_url_rule`, `MethodView` subclasses                                                                                                                                                                                | `route` nodes, `routes_to` and `mounts` edges, with every method of a multi-method decorator kept, and the `flask.view` and `flask.route_handler` roles                                                                                                                                                                                                                                                           |
| Celery    | `task` and `shared_task` decorators; `<task>.delay(...)` and `<task>.apply_async(...)` on a project function; a signature, `<task>.s(...)`, `.si(...)` or `.signature(...)`; a task sent by name, `<app>.send_task("pkg.tasks.name")` or `celery.signature("pkg.tasks.name")`                                      | the `python.task` role; a `dispatches` edge from the submitter to the task it queues, which flow follows. A signature's edge is probable and carries `celery: signature`, since it queues the task once a chain, a chord or its caller applies it. A name is read as Celery's default, module then function; a name the module does not declare, such as a custom `name=`, is kept only when that function exists |

Blueprint prefixes and route paths combine only when both are literals in the
same file, for a decorated route and for `add_url_rule` alike. An `APIRouter`
or `Blueprint` assigned to a name at module level is a `router` node,
`py:router:<module>.<name>`, and a `mounts` edge from `include_router` or
`register_blueprint` reaches the router node it names, in the same file or
imported from another module. A router built inside a function belongs to each
call of it, so it has no node, and routes declared on it read an unknown prefix,
as does an `add_url_rule` on an object whose type the scan cannot see. A
router handed in as a parameter has no node of its own either, so its mount is
kept only when its target resolves. One module mounting one router twice under
two prefixes keeps a `mounts` edge for each prefix.

## HTTP clients and routes

A call to `requests.get`, `post`, `put`, `patch`, `delete`, `head` or
`options`, to `requests.request(method, url)` (also with `method=` and `url=`
keywords) and to the same functions of `httpx` becomes an
`endpoint` node and a `calls_endpoint` edge from the enclosing function, when
the URL is a literal or an f-string and the name resolves to the imported
module. `taskqueue.add(url=...)` and `taskqueue.Task(url=...)` from
`google.appengine.api` give an endpoint and a `dispatches` edge, since the
request happens later. The method is `POST` when `method=` is absent, and `ANY` when it is not a
literal, as it is for `requests.request` with a method read from a variable.
`requests.Session()` and `httpx.Client()` receivers are not read.

To let the core join these to the routes above, a `route` node says which
`router` it hangs under, a `mounts` edge says which router it is mounted
`onto`, and a Django `route` node carries the `module` that holds its
`urlpatterns`. A Django `include()` is a `mounts` edge from the including
urlconf, `onto` itself, with the pattern's path as its prefix, to the included
module (by dotted name, also in an `include((module, namespace))` tuple) or to a
DRF router's `urls`. A DRF `router.register(prefix, ViewSet)` gives two routes on
that router, `<prefix>/` and `<prefix>/{pk}/`, both to the viewset class; `@action`
routes are not read. A Django route has a known prefix when its chain of
includes reaches the module `ROOT_URLCONF` names. [From request to handler](../concepts/request-to-handler.md)
describes the join.

## Test doubles

In a test file, the worker reports each mock, patch and spy as a `test_double`
fact. A test file is one the core's test-module rule takes for test code by its
path:

- one under `tests/`, `test/`, `spec/`, `e2e/` or `fixtures/` outside a source
  root (`src/`, `lib/`, `app/` or `source/`);
- one under `__tests__/`, `__test__/`, `__fixtures__/` or `__mocks__/` at any
  depth, or under `src/test/` or `database/factories/`;
- one named `test_*.py`, `*_test.py`, `*.test.py`, `*.spec.py` or, in
  PascalCase, `*Test.py`.

The test-fidelity rules hold each double against the class, function or module
it stands in for. The worker reads these, without importing any of them:

- `unittest.mock`, or its `mock` backport, under any alias: the `Mock` family
  (`Mock`, `MagicMock`, `AsyncMock`, `NonCallableMock`,
  `NonCallableMagicMock`, `PropertyMock`, `ThreadingMock`), `mock_open`,
  `create_autospec`, `patch`, `patch.object`, `patch.multiple` and
  `patch.dict`;
- pytest-mock's `mocker` fixture at any scope (`class_mocker`,
  `module_mocker`, …): the same calls, plus `mocker.spy`, `mocker.stub` and
  `mocker.async_stub`;
- pytest's `monkeypatch.setattr`.

A fixture counts only as a parameter of the function the call sits in, and a
call counts only when its name resolves to one of these, so a project function
that happens to be called `patch` makes no double.

What a double stands for comes from its arguments. A dotted string such as
`patch("shop.cart.Cart.total")` is read through its longest prefix that is a
project module. A name the module only imports is judged in the module that
declares it, when the name it is imported under is the declared one. An object
(`patch.object(Cart, "total")`) is the class or module the expression names.
A `spec=`, `spec_set=`, `autospec=True` or `create_autospec()` names the class
the double is shaped like. `create=True` and `raising=False` say the name may
not exist, and a builtin patched on a module (`patch("m.open")`) is one the
patch creates, so none of these is judged as a ghost.

A double is bound to a name in the test when that is certain: a local assigned
once, a `with … as`, a patcher's `start()`, or the argument a `@patch`
decorator injects (bottom-up, and through a class decorator into each `test*`
method). A `self.` or `cls.` attribute written once in `setUp`, `setUpClass`,
`asyncSetUp` or `setup_method` serves every test of the class, and a fixture
in the same file that returns or yields one double serves each test that asks
for it. A double from the nearest `conftest.py` gives its identity only:
configuration a test applies to it is not read. A name bound in both branches
of an `if`, or again in another block, holds no double for certain, so what is
done through it is not read.

A double's members are the names its patch form replaces, the names read
through a spec'd handle, and, under `spec_set`, the names written on it. The
`Mock` API (`return_value`, `assert_called_with`, …), the prefixes a mock
reserves (`assert`, `_mock_`, …) and dunders are never members. A double with
no spec records no members, since it answers every name. Its pins are
`return_value`, `side_effect` (one pin per literal item of a list; none for an
exception; the literal a lambda or local function returns), a
`**{"m.return_value": v}` keyword dict, `configure_mock`, a `new=` value and
the value `monkeypatch.setattr` sets. A pin's type is read from the value only:
a literal, a builtin constructor, a project class's constructor
(`class:<name>`), or a `lambda` (`callable`); any other value is `unknown`.
Its call matchers are `assert_called_with`, `assert_called_once_with`,
`assert_any_call`, their
`await` forms, and each `call(...)` that `assert_has_calls` or
`assert_has_awaits` lists, with the keyword names they pass. A `*` or `**`
argument leaves the count open. A `side_effect` callable, when the double is
not autospec'd, gives the member its parameters, and so does a function that
replaces a module's function.

## Test cases

The same test files report each test as a `test_case` fact: a module-level
`test*` function that is not a fixture, a `test*` method of a `Test*` class
without `__init__` (nested ones too), and a `test*` method of a
`unittest.TestCase` subclass. Async tests count. Each lists:

- its assertions: a bare `assert` on one comparison (`==`, `!=`, `<`, `<=`,
  `>`, `>=`, `is`, `is not`, `in`, `not in`), any other bare `assert` with one
  operand, `unittest`'s value assertions (`assertEqual`, `assertIs`,
  `assertTrue`, `assertIn`, … by position or keyword, never `msg`), and the
  mock assertions (`assert_called*`, `assert_any_call`, `assert_has_calls`,
  `assert_not_called` and their `await` forms). A chained comparison gives
  none. `assertRaises`, `assertWarns`, `pytest.raises`, `pytest.warns` and
  `pytest.deprecated_call` mark the test as expecting a failure.
- where each operand's value comes from: a literal, a double (`mock-call`), the
  value a stub was pinned to (`mock-config`), the code under test
  (`production`), a `parametrize` argument (`parameter`), or unknown.
- its production calls and the project files they land in.

A local name is followed to a literal only when the test function binds it
exactly once, to a constant or a tuple of constants, before it is read, and
nothing can write it unseen: no `global` or `nonlocal` of it at any depth, no
`locals()`, `vars()`, `globals()`, `exec` or `eval` in the function, and no
frame access (`sys._getframe`, `inspect.currentframe`, `f_locals`, …) anywhere
in the file. Every other form of binding counts as one: a `for` or `with`
target, `except … as`, a `match` capture, unpacking, a walrus, `del`, an
import. A list, dict or set is never followed, so no call can change a value
the worker took as fixed. A `self.` attribute is never a literal.

A call of a stub gives its pinned value only for a `Mock`-family double made
in the same test without a spec, a target or a positional argument, pinned
once before the call, and used in no way that could change what it answers:
no `side_effect`, `reset_mock` or `configure_mock`, never handed to a call
that is not an assertion, put in a container, returned or written through. A
double held on `self.` or by a fixture is a double for its identity, but a
call of it never gives its pin.

A call counts as production when what it calls is imported from anything
other than `unittest`, `mock`, `pytest`, `pytest_*`, `django.test` or a test
module; a standard-library or third-party call counts, with no project file. A
method called on the value such a call made counts, as does a `self.` method
the class inherits from a base the file does not show, and the calls inside a
helper of the file the test calls. A test also counts what its class's
`setUp` methods and the fixtures it asks for run. A fixture the worker cannot
find counts as one production call.

A test file whose doubles or tests cannot be read reports the warning
`PY_TEST_FACTS_FAILED` and keeps every other fact.

## How the rules judge Python tests

The test-fidelity rules read these facts as they read TypeScript's and PHP's,
with Python's own semantics. Python enforces no annotation at run time, so a
type a double breaks is never more than a warning, while a name or a call the
runtime would refuse is definite.

| Category                                                | Python construct                                                                                                           | Confidence                                                                  |
| ------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------- |
| `GHOST_MEMBER`                                          | a stubbed or patched name the class or module no longer has                                                                | definite, against a complete list; silent where the list is open            |
| `ARITY_MISMATCH/call_arity`                             | a call matcher the signature cannot bind: too many positional arguments, an unknown keyword, a required parameter left out | definite, with or without `autospec`                                        |
| `ARITY_MISMATCH/signature`                              | a `side_effect` lambda or function whose parameters do not fit the member's                                                | warning                                                                     |
| `RETURN_DRIFT`                                          | a pin whose type the declared return annotation refuses                                                                    | warning                                                                     |
| `VISIBILITY_BREACH`                                     | a double of `_x`; of `__x` (not a dunder), which a double cannot replace under that name                                   | `_x` a warning; `__x` definite where every ancestor is seen, else a warning |
| `TAUTOLOGY/self_comparison`                             | `x is x`; `x == x` where both sides trace to a literal                                                                     | definite                                                                    |
| `TAUTOLOGY/constant`                                    | an assertion on constants only, a local bound once to a constant comparing as that constant                                | definite                                                                    |
| `TAUTOLOGY/mock_echo`                                   | a stub's pinned value compared with itself                                                                                 | definite                                                                    |
| `TAUTOLOGY/mock_only`, `unconfigured_spy`, `zero_reach` | a test that checks only doubles, a spy (`Mock(wraps=…)`, `mocker.spy`) nothing stubs or calls, a double nothing reaches    | warning                                                                     |

Three of these follow decisions taken for Python:

- `RETURN_DRIFT` is a warning only. No runtime check refuses a `return_value`
  of the wrong type, so the test still runs; the finding says the double no
  longer tells the truth about the code.
- A call matcher's arity is definite with or without `autospec`. Without
  `autospec` the matcher passes, but it asserts a call the real signature
  cannot accept, so it can never match the code it stands for.
- A `side_effect` lambda or function of the wrong arity is a warning: it
  raises only when something calls it. Nemesis reports it as definite.

A self-comparison is definite under `is` (`assert x is x`, `assertIs(x, x)`),
or under `==` and the other comparisons only when both operands trace to a
literal constant. Any other `x == x` is never definite: a float NaN, or an
`__eq__` under test, can make it fail.

The lists a ghost is judged against come from the worker's `member_names` and
`exports` (see [complete name lists](../reference/scanner-protocol-v1.md#complete-name-lists)).
A dunder is never judged. A base outside the project leaves the list open,
except `abc.ABC`, `typing.Protocol` and `typing.Generic`, which add only their
metaclass's names, and an open ancestor leaves every subclass open. A package's
exports are always open. A class whose name its own file stores in another
binding, a list, tuple, set or dict, a `for` loop or a comprehension, or
returns (`Alias = C`, `REGISTRY = {"c": C}`, `for k in (C, D): k.x = 1`) may
be written through that holder, so its list is open too (Ruling P23). A call
matcher and a `side_effect` are not judged against a def that is decorated or
rebound in its own module or class body, `self.m = …`, `self.m, self.n = …`
and `setattr(self, "m", …)` among them. Neither they nor a pin are judged on a
class whose lineage carries `methods_rewritable` (a metaclass, an
`__init_subclass__`, a class decorator or call that may rewrite its methods),
nor against a def Python's lookup may not reach first (Ruling P24): one whose
name another class of the lineage also lists (a subclass's `save = impl`, a
second base's def an MRO may put first), or one inherited by a class with a
base outside the project, which may come first in the MRO. A pin is judged
against a declared return annotation only, built-in types, `typing`'s aliases,
`Optional`, `Union`, `|` and `Annotated`; a project class,
a `Protocol`, a `TypeVar`, `Literal`, `Callable` or any other annotation is
not judged, and a `new=` value replaces the member rather than pinning what it
returns. A double answers to every test of its file, since a Python double
names no test case yet, so `zero_reach` speaks only in a file whose tests run
no production code.

### What the Python rules do not see

A write from another file (G4-03) is seen: the worker reads one file at a
time, so the writer's module node names each write it makes on a project
module or class another file declares (`m.FLAG = 1`, `C.x = 1`,
`m.C.x = 1`, a literal `setattr`) in `foreign_writes`, and the target's list
is then open. A def such a write rebinds (`Repo.save = wrap(Repo.save)`, or a
package's `m.f = partial(m.f, 1)`) has its arity judged no more, and a
`setattr` whose name no literal spells leaves every def of the target
unjudged. The writer reads the target's file, so a change to either file is
judged again.

A call made unbound (G4-10) is seen too: production that calls
`Repo.save(repo, x)` hands the receiver to `self` as an argument, which a
double of `save` on the class records, so a matcher or a `side_effect` that
lists it is no arity mismatch, while one argument more still is, and so is the
receiver of a method production calls only bound. A static or class method
called through its class is held to one argument more as well, a missed check.

Some writes the worker sees but does not follow, by the user's decision of
2026-11-27 (U1): the list stays as it is, the node names the pattern in
`unfollowed_writes`, and a ghost, an arity mismatch or a `constant` that rests
on it is a **warning**, never a definite, naming the pattern as its
`residual`. A ghost or arity on a target with none of them stays definite.

- **Instance writes outside the class** (G4-02). A name written on an instance
  through an alias of `self` (`me = self; me.save = …`, `self_alias`), on an
  instance built right there, in the class's file or another
  (`C().extra = 1`, `instance_write`), or by a module through an import of
  itself (`import m; m.x = 1` inside `m`, `module_self_write`) is not listed.
- **Class writes the worker does not follow** (G4-05). A write to a nested
  class (`O.I.x = 1`, `nested_class_write`), a class held as a parameter
  default (`def f(k=C): k.x = 1`, `default_holder`), a class handed to a call
  named like a read-only builtin but bound to something else
  (`rebound_builtin`), `builtins.exec` or `builtins.eval` (`builtins_exec`,
  every class of the module and the module), and a frame hack whose result is
  assigned in the class body (`assigned_frame_hack`) leave the list closed.
  A double of a nested class names it through its outer class
  (`Outer.Inner`), which the worker lists under its own name, so it is not
  judged at all.
- **A frame write from another module** (G4-08). Production code that reads a
  frame's `f_locals` (`sys._getframe(1).f_locals["x"] = v`, which writes
  through on Python 3.13 and later) is marked `frame_write`, and while the
  scan holds such a production module, a `constant` a test's local holds is a
  warning. Frame access in the test file itself is seen, and a constant
  written out (`assert 1 == 2`) is no local a frame can change.
- **A metaclass outside the project that may rewrite methods** (G4-12). A base
  the scan does not hold may rewrite its subclasses' signatures through its
  metaclass or `__init_subclass__`, so an arity mismatch under one is a
  warning, as `external_base`. A builtin, `abc.ABC`, `typing.Protocol`,
  `typing.Generic`, `enum`'s bases and Django's `Model` keep the signatures
  their defs write, and stay definite.

These are accepted, and each costs a missed finding, as stated:

- **A spec'd double's pin read back** (G4-14). `m = Mock(spec=C)`,
  `m.f.return_value = 7`, then `assert m.f() == 7` is an echo, but a spec'd
  double gives no pinned value, so it is silent.
- **`MOCKS_SUBJECT`** (G4-11). A Python double names no test case yet, so the
  rule that a test replaces its own subject never speaks for Python.
- **A member list opened for nothing** (G4-04). A class handed to a call the
  worker does not know to be read-only (a registry, `codecs.CodecInfo`, a
  helper) opens its list, so ghosts on it are missed. Measured before Ruling
  P23, this opened 892 of the standard library's 2,644 classes (484 of them
  handed to `codecs.CodecInfo`) and 1 of Knossos's own 30. A class a same-file
  alias holds (Ruling P23) adds up to 179 more in the standard library, and
  none in Knossos.

Not read at all: a `@property` patched without `PropertyMock`; a
constructor's arguments asserted through the class double
(`MockCls.assert_called_with(...)`); a dotted target whose module does not
exist; an `assert` on a tuple (`assert (x, "msg")`, which always passes);
pytest's collection settings (`python_files`, `python_classes`); the test
cases and doubles of a `conftest.py` at the project root, which is no test
module (its fixtures are read); and frameworks other than these, such as
`responses` or `freezegun`.

## Field facts

A class sends its fields only when its file shows what makes its annotations
fields: `@dataclass`, or a base that is pydantic's `BaseModel`, a `TypedDict`
or a class of the same file that is one. A plain class, a `NamedTuple`, an
attrs class, a pydantic dataclass and a subclass of a class in another file
send none, which leaves most real pydantic hierarchies unjudged. A field with
a default is optional; `Field(...)` and `= ...` stay required. `Optional[X]`
without a default stays required, as pydantic v2 reads it, only in a file that
imports a name pydantic v1 lacks (`ConfigDict`, `field_validator` and the
like); elsewhere v1 may fill it with `None`, so it is not required. A field a
validator may reach before the type check (`field_validator` in mode
`before`, `wrap` or `plain`, v1 `validator(pre=True)`, or a `BeforeValidator`,
`PlainValidator` or `WrapValidator` in its `Annotated` metadata) keeps its
name but sends no type, so a fixture's kind for it is never judged. An `Enum`
sends its cases and is never a fixture's type. One with a nested class sends
none: that class is a member before Python 3.13 and not after.

The list is open for an alias (`alias`, `validation_alias`,
`serialization_alias`), a `model_config` or `class Config`, a field call with
`init`, `metadata` or `**`, an `InitVar`, a constructor or a pydantic hook
that may reread the input, a decorator other than `dataclass`, a class
keyword, a base the scan cannot read, a name that may hold an alias, and the
causes that open a class's member list. A pydantic model passed to any call,
and a default naming a module constant, open it too. An Enum whose `_missing_`,
`__new__`, `__init__` or `_generate_next_value_` may give other values sends
no values. The full rules are in the [scanner protocol](../reference/scanner-protocol-v1.md#field-lists).

## Limits

- Dynamic imports, monkey-patching, runtime decorator effects, metaclass
  behavior and dynamically selected call targets are not executed or inferred. A
  decorator name is structural evidence, not a claim about what it does at
  runtime.
- A route path that is not a string literal is skipped with
  `PY_DYNAMIC_ROUTE_PATH`. A Flask converter path such as
  `/users/<int:user_id>` is a template and kept as written.
- Django settings are limited to the five names above with statically literal
  values, and a settings module is never imported. FastAPI dependency callables
  are never invoked.
- A syntax error produces `PY_SYNTAX_ERROR` for that file, a file the worker
  cannot read or that is oversized produces `PY_UNSCANNABLE_FILE`, and neither
  stops the other files from contributing.
- A relative import that climbs above the project root emits no edge and reports
  `PY_UNRESOLVED_RELATIVE_IMPORT`. A module file and a package that share one
  module id both report `PY_MODULE_ID_COLLISION`; the package keeps the id.

## Processes it starts

A list or tuple that reads as the argv of a process links the code that builds
it to the file the process runs, with a probable `launches` edge to that file's
module: `[sys.executable, ..., str(ROOT / "worker.py")]`, `["node",
"js/worker.js"]`, or a program path joined to a root in first place
(`[str(ROOT) + "/bin/tool"]`). A path is a string literal or the literal parts
it is joined from (`/`, `+`, an f-string, `os.path.join`); a name with no
directory counts only with a script's extension. The core resolves the path to
the module of the project file at that path, or of the one file whose path ends
with it, and drops the edge when there is none.

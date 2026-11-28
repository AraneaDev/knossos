"""Names from the standard library and pytest that the worker needs, frozen so it never imports them.

Knossos never executes or imports project code, and it does not import the
mock library either: what the worker knows of ``builtins``, the ``Mock`` API
and pytest's fixtures is these lists, taken from CPython 3.11 to 3.14,
``unittest/mock.py`` and pytest 8.
"""

from __future__ import annotations

BUILTINS = frozenset(
    """
    Ellipsis False None NotImplemented True abs aiter all anext any ascii bin bool breakpoint bytearray bytes
    callable chr classmethod compile complex copyright credits delattr dict dir divmod enumerate eval exec exit
    filter float format frozenset getattr globals hasattr hash help hex id input int isinstance issubclass iter
    len license list locals map max memoryview min next object oct open ord pow print property quit range repr
    reversed round set setattr slice sorted staticmethod str sum super tuple type vars zip
    """.split()
) | frozenset(
    """
    ArithmeticError AssertionError AttributeError BaseException BaseExceptionGroup BlockingIOError
    BrokenPipeError BufferError BytesWarning ChildProcessError ConnectionAbortedError ConnectionError
    ConnectionRefusedError ConnectionResetError DeprecationWarning EOFError EncodingWarning EnvironmentError
    Exception ExceptionGroup FileExistsError FileNotFoundError FloatingPointError FutureWarning GeneratorExit
    IOError ImportError ImportWarning IndentationError IndexError InterruptedError IsADirectoryError KeyError
    KeyboardInterrupt LookupError MemoryError ModuleNotFoundError NameError NotADirectoryError
    NotImplementedError OSError OverflowError PendingDeprecationWarning PermissionError ProcessLookupError
    PythonFinalizationError RecursionError ReferenceError ResourceWarning RuntimeError RuntimeWarning
    StopAsyncIteration StopIteration SyntaxError SyntaxWarning SystemError SystemExit TabError TimeoutError
    TypeError UnboundLocalError UnicodeDecodeError UnicodeEncodeError UnicodeError UnicodeTranslateError
    UnicodeWarning UserWarning ValueError Warning ZeroDivisionError
    """.split()
)
"""The public names of ``builtins``: ``mock._builtins``, which ``patch`` creates on a module without ``create=True``."""

EXCEPTION_SUFFIXES = ("Error", "Exception", "Exit", "Interrupt", "Iteration", "Warning", "Group")
"""How the builtin exceptions, and by convention a project's own, end their names."""

MOCK_API = frozenset(
    """
    assert_any_await assert_any_call assert_awaited assert_awaited_once assert_awaited_once_with
    assert_awaited_with assert_called assert_called_once assert_called_once_with assert_called_with
    assert_has_awaits assert_has_calls assert_not_awaited assert_not_called attach_mock await_args
    await_args_list await_count call_args call_args_list call_count called configure_mock method_calls
    mock_add_spec mock_calls reset_mock return_value side_effect mock wait_until_called wait_until_any_call_with
    """.split()
)
"""Attributes every mock answers itself, which are never members of what it stands in for."""

UNSAFE_PREFIXES = ("assert", "assret", "asert", "aseert", "assrt", "_mock_", "_spec_")
"""Prefixes a mock reserves: a spec'd mock refuses these names rather than looking them up."""

CALL_MATCHERS = frozenset(
    {
        "assert_called_with",
        "assert_called_once_with",
        "assert_any_call",
        "assert_awaited_with",
        "assert_awaited_once_with",
        "assert_any_await",
    }
)
"""The assertions that list one call's arguments."""

CALL_LIST_MATCHERS = frozenset({"assert_has_calls", "assert_has_awaits"})
"""The assertions that list several calls, each a ``call(...)``."""

MOCK_CLASSES = frozenset(
    {"Mock", "MagicMock", "AsyncMock", "NonCallableMock", "NonCallableMagicMock", "PropertyMock", "ThreadingMock"}
)
"""The classes of the ``Mock`` family, each of which makes a double."""


MOCKER_FIXTURES = frozenset({"mocker", "class_mocker", "module_mocker", "package_mocker", "session_mocker"})
"""The fixtures pytest-mock provides, each a ``MockerFixture``."""

PYTEST_BUILTIN_FIXTURES = frozenset(
    """
    cache capfd capfdbinary caplog capsys capsysbinary capteesys doctest_namespace monkeypatch pytestconfig
    record_property record_testsuite_property record_xml_attribute recwarn request subtests testdir tmp_path
    tmp_path_factory tmpdir tmpdir_factory
    """.split()
)
"""The fixtures pytest itself provides, which no conftest has to declare."""

SETUP_METHODS = frozenset({"setUp", "setUpClass", "asyncSetUp", "setup_method"})
"""Methods a test class runs before each of its tests, whose ``self.`` and ``cls.`` writes every test sees."""


def is_dunder(name: str) -> bool:
    """Whether ``name`` is a protocol hook such as ``__enter__``: never a stubbed member (Ruling P3)."""
    return len(name) > 4 and name.startswith("__") and name.endswith("__")


def is_mock_api(name: str) -> bool:
    """Whether a mock answers ``name`` itself rather than standing in for a member by that name."""
    return name in MOCK_API or name.startswith(UNSAFE_PREFIXES) or is_dunder(name)

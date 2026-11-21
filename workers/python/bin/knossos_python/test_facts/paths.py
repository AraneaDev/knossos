"""Whether a file is test code, by the path conventions the core's ``TestModuleRule`` reads.

Kept in step with ``src/Classification/TestModuleRule.php`` by hand, as the
PHP worker's ``TestModulePath`` is: the worker runs in its own process and
cannot load the core's classes. Only a test file's mocks are test doubles.
Named ``paths`` rather than ``test_paths`` so the core does not take this
module itself for a test (Ruling P15).
"""

from __future__ import annotations

import re

UNAMBIGUOUS_SEGMENTS = frozenset({"__tests__", "__test__", "__fixtures__", "__mocks__"})
"""Directories that hold tests wherever they are nested."""

AMBIGUOUS_SEGMENTS = frozenset({"tests", "test", "spec", "e2e", "fixtures"})
"""Directories that hold tests only outside a package's source root."""

SOURCE_ROOTS = frozenset({"src", "lib", "app", "source"})
"""Directories a package keeps its product code in."""

_PASCAL_TEST = re.compile(r"(?:^|[^A-Za-z])Test$|[a-z0-9]Test$")


def is_test_path(path: str) -> bool:
    """Whether a project-relative path, with forward slashes, is a test module."""
    *segments, file = path.split("/")
    under_source_root = False
    previous: str | None = None
    for segment in segments:
        lower = segment.lower()
        if lower in UNAMBIGUOUS_SEGMENTS or (lower in AMBIGUOUS_SEGMENTS and not under_source_root):
            return True
        # Maven's `src/test/`, and Laravel's model factories, which build fixtures for tests.
        if (lower == "test" and previous == "src") or (lower == "factories" and previous == "database"):
            return True
        under_source_root = under_source_root or lower in SOURCE_ROOTS
        previous = lower
    stem = re.sub(r"\.[^.]+$", "", file)
    lower = stem.lower()
    if lower.endswith((".test", ".spec", "_test")) or lower.startswith("test_"):
        return True
    # PascalCase `ThingTest`: the suffix counts only at a word boundary, so `contest` or `latest` do not.
    return _PASCAL_TEST.search(stem) is not None

<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

/**
 * What the core knows of the Python bases outside the project, by the
 * reference a class's `extends` edge names one with: `py:external_symbol:<name>`
 * (`from abc import ABC`) or `py:class:<name>` (`abc.ABC`), the one spelling
 * of that reference for every reader (Ruling P8).
 *
 * `abc.ABC`, `typing.Protocol` and `typing.Generic` add no member a double
 * may stub but their metaclass's own (on 3.14, the one version checked, with
 * the pure-Python `_py_abc` caches added; a lookup on the class reaches them,
 * so `patch.object(C, "register")` replaces one, though `dir(C)` and a
 * `spec=C` list none), so a class inheriting from one keeps a complete name
 * list. Every other base outside the project (`enum.Enum`,
 * `typing.NamedTuple`, `typing.TypedDict`, `Exception`) is unseen, its names
 * unknown.
 *
 * A base outside the project whose metaclass or `__init_subclass__` may
 * rewrite its subclasses' methods cannot be read (G4-12). Those known to keep
 * every signature a def writes are a builtin, a base of the standard library
 * (`logging.Handler`, `threading.Thread`, `collections.abc.Mapping`: its
 * metaclasses, `ABCMeta` and `EnumMeta` among them, keep them), and Django's
 * model base.
 */
final class PythonBases
{
    /** A Python reference to a class outside the project, the qualified name captured. */
    public const REFERENCE = '/^py:(?:external_symbol|class):(.+)$/sD';

    /** The known bases, by qualified name, and the names each gives. */
    private const KNOWN = [
        'abc.ABC' => self::ABC_NAMES,
        'typing.Protocol' => [...self::ABC_NAMES, '_is_protocol', '_is_runtime_protocol'],
        'typing.Generic' => ['_is_protocol'],
    ];

    /** What `abc.ABCMeta` gives each class it makes, the pure-Python `_py_abc`'s caches included. */
    private const ABC_NAMES = ['_abc_cache', '_abc_caches_clear', '_abc_impl', '_abc_negative_cache', '_abc_negative_cache_version', '_abc_registry', '_abc_registry_clear', '_dump_registry', 'register'];

    /** Bases outside the project and the standard library whose metaclass keeps every signature a def writes. */
    private const PLAIN = ['django.db.models.Model', 'django.db.models.base.Model'];

    /**
     * The standard library's public top-level modules: Python 3.14's
     * `sys.stdlib_module_names`, those that start with an underscore left out,
     * with the modules 3.12 and 3.13 removed added, as the worker runs on 3.11
     * and later.
     */
    private const STDLIB = [
        'abc', 'aifc', 'annotationlib', 'antigravity', 'argparse', 'array', 'ast', 'asynchat', 'asyncio',
        'asyncore', 'atexit', 'audioop', 'base64', 'bdb', 'binascii', 'bisect', 'builtins', 'bz2', 'cProfile',
        'calendar', 'cgi', 'cgitb', 'chunk', 'cmath', 'cmd', 'code', 'codecs', 'codeop', 'collections',
        'colorsys', 'compileall', 'compression', 'concurrent', 'configparser', 'contextlib', 'contextvars',
        'copy', 'copyreg', 'crypt', 'csv', 'ctypes', 'curses', 'dataclasses', 'datetime', 'dbm', 'decimal',
        'difflib', 'dis', 'distutils', 'doctest', 'email', 'encodings', 'ensurepip', 'enum', 'errno',
        'faulthandler', 'fcntl', 'filecmp', 'fileinput', 'fnmatch', 'fractions', 'ftplib', 'functools', 'gc',
        'genericpath', 'getopt', 'getpass', 'gettext', 'glob', 'graphlib', 'grp', 'gzip', 'hashlib', 'heapq',
        'hmac', 'html', 'http', 'idlelib', 'imaplib', 'imghdr', 'imp', 'importlib', 'inspect', 'io',
        'ipaddress', 'itertools', 'json', 'keyword', 'lib2to3', 'linecache', 'locale', 'logging', 'lzma',
        'mailbox', 'mailcap', 'marshal', 'math', 'mimetypes', 'mmap', 'modulefinder', 'msilib', 'msvcrt',
        'multiprocessing', 'netrc', 'nis', 'nntplib', 'nt', 'ntpath', 'nturl2path', 'numbers', 'opcode',
        'operator', 'optparse', 'os', 'ossaudiodev', 'pathlib', 'pdb', 'pickle', 'pickletools', 'pipes',
        'pkgutil', 'platform', 'plistlib', 'poplib', 'posix', 'posixpath', 'pprint', 'profile', 'pstats', 'pty',
        'pwd', 'py_compile', 'pyclbr', 'pydoc', 'pydoc_data', 'pyexpat', 'queue', 'quopri', 'random', 're',
        'readline', 'reprlib', 'resource', 'rlcompleter', 'runpy', 'sched', 'secrets', 'select', 'selectors',
        'shelve', 'shlex', 'shutil', 'signal', 'site', 'smtpd', 'smtplib', 'sndhdr', 'socket', 'socketserver',
        'spwd', 'sqlite3', 'sre_compile', 'sre_constants', 'sre_parse', 'ssl', 'stat', 'statistics', 'string',
        'stringprep', 'struct', 'subprocess', 'sunau', 'symtable', 'sys', 'sysconfig', 'syslog', 'tabnanny',
        'tarfile', 'telnetlib', 'tempfile', 'termios', 'textwrap', 'this', 'threading', 'time', 'timeit',
        'tkinter', 'token', 'tokenize', 'tomllib', 'trace', 'traceback', 'tracemalloc', 'tty', 'turtle',
        'turtledemo', 'types', 'typing', 'unicodedata', 'unittest', 'urllib', 'uu', 'uuid', 'venv', 'warnings',
        'wave', 'weakref', 'webbrowser', 'winreg', 'winsound', 'wsgiref', 'xdrlib', 'xml', 'xmlrpc', 'zipapp',
        'zipfile', 'zipimport', 'zlib', 'zoneinfo',
    ];

    private function __construct() {}

    /** The qualified name a reference to a Python class outside the project names, or null for any other reference. */
    public static function qualifiedName(string $reference): ?string
    {
        return preg_match(self::REFERENCE, $reference, $match) === 1 ? $match[1] : null;
    }

    /**
     * The names a known base gives, when the reference names one; null for any other.
     *
     * @return list<string>|null
     */
    public static function knownNames(string $reference): ?array
    {
        return self::KNOWN[self::qualifiedName($reference) ?? ''] ?? null;
    }

    /** Whether a base outside the project, by reference, is known to keep every signature its subclasses' defs write: a builtin, a base of the standard library, or Django's model base. */
    public static function keepsSignatures(string $reference): bool
    {
        $name = self::qualifiedName($reference);
        if ($name === null) {
            return false;
        }

        return isset(self::KNOWN[$name]) || in_array($name, self::PLAIN, true) || in_array(explode('.', $name)[0], self::STDLIB, true)
            || preg_match('/^(?:builtins\.)?[A-Za-z_][A-Za-z0-9_]*$/D', $name) === 1;
    }
}

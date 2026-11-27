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
 * every signature a def writes are a builtin, the bases above, and `enum`'s
 * and Django's model base (`ABCMeta`, `EnumMeta` and the model metaclass).
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

    /** Bases outside the project, besides the known ones and the builtins, whose metaclass keeps every signature a def writes. */
    private const PLAIN = ['enum.Enum', 'enum.IntEnum', 'enum.StrEnum', 'enum.Flag', 'enum.IntFlag', 'django.db.models.Model', 'django.db.models.base.Model'];

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

    /** Whether a base outside the project, by reference, is known to keep every signature its subclasses' defs write: a builtin, a known base, `enum`'s or Django's model base. */
    public static function keepsSignatures(string $reference): bool
    {
        $name = self::qualifiedName($reference);
        if ($name === null) {
            return false;
        }

        return isset(self::KNOWN[$name]) || in_array($name, self::PLAIN, true) || preg_match('/^(?:builtins\.)?[A-Za-z_][A-Za-z0-9_]*$/D', $name) === 1;
    }
}

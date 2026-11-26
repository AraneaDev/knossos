<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Fixtures;

use Knossos\TestFidelity\PhpTypeFit;
use Knossos\TestFidelity\PythonTypeFit;
use Knossos\TestFidelity\TestFidelityGraph;

/**
 * Whether a fixture value's kind fits a field's declared type: Nemesis's
 * `typesCompatible` (`analyzer.ts:1032-1255`), per language.
 *
 * TypeScript and Rust are judged by the port of Nemesis's lattice itself
 * (`alternatives`, `canon`, `kindSatisfies`). PHP and Python are judged by
 * {@see PhpTypeFit} (PHP's coercing rules, `strict: false`) and
 * {@see PythonTypeFit}, which already read a pinned kind against a declared
 * type (preflight F14), with Nemesis's leniencies on top: a float where an
 * int is declared fits, as an int where a float is does; an array or an
 * object fits where Nemesis's lattice takes it (PHP's list and dict are one
 * `array`, and an object may be any named type).
 *
 * A declared type of only names (`Mode`, `Address | null`) is not judged at
 * all ({@see self::namesOnly()}): nothing in the scan says what such a name
 * admits.
 */
final class KindLattice
{
    /** Async and optional wrappers that carry the interesting type inside them (`analyzer.ts:1024-1026`). */
    private const ASYNC_WRAPPER = '/^(?:Promise|PromiseLike|Awaitable|Task|Future|Coroutine|Deferred)\s*<([\s\S]+)>$/D';

    private const PY_ASYNC = '/^(?:Awaitable|Coroutine)\s*\[([\s\S]+)]$/D';

    /** The kinds of `canon` (`analyzer.ts:1070-1096`), each matched on a type's lower-case last segment. */
    private const KINDS = [
        'wild' => '/^(?:mixed|any|unknown|self|static|this|json|jsonvalue|serializable)$/D',
        'string' => '/^(?:string|str|&str|text|char)$/D',
        'bytes' => '/^(?:bytes|bytearray|memoryview)$/D',
        'bool' => '/^(?:bool|boolean)$/D',
        'int' => '/^(?:int|integer|long|short|byte|bigint|usize|isize|[iu](?:8|16|32|64|128))$/D',
        'float' => '/^(?:float|double|number|real|decimal|f32|f64)$/D',
        'void' => '/^(?:void|none|nonetype|unit|never|nothing)$/D',
        'null' => '/^null$/D',
        'undefined' => '/^undefined$/D',
        'list' => '/^(?:array|list|sequence|iterable|iterator|generator|traversable|collection|vec|set|frozenset|tuple|arraylist|slice)$/D',
        'dict' => '/^(?:dict|mapping|record|map|hashmap|btreemap|object|stdclass|assoc|counter|defaultdict|ordereddict)$/D',
        'callable' => '/^(?:callable|closure|function|fn|callback)$/D',
    ];

    /** A fixture kind as the pinned type {@see PhpTypeFit} reads. */
    private const PHP_PINS = [Value::NULL => 'null', Value::BOOL => 'bool', Value::INT => 'int', Value::FLOAT => 'float', Value::STRING => 'string', Value::LIST => 'array', Value::DICT => 'array'];

    /** A fixture kind as the pinned type {@see PythonTypeFit} reads. */
    private const PYTHON_PINS = [Value::NULL => 'None', Value::BOOL => 'bool', Value::INT => 'int', Value::FLOAT => 'float', Value::STRING => 'str', Value::LIST => 'list', Value::DICT => 'dict'];

    /** How deep `alternatives` unwraps (`analyzer.ts:1034`). */
    private const MAX_DEPTH = 8;

    private function __construct() {}

    /** Whether a declared type says nothing a value can be held against: absent, `mixed`, `any` or `unknown` (`analyzer.ts:134-138`). */
    public static function untyped(?string $declared): bool
    {
        return $declared === null || preg_match('/^(?:mixed|any|unknown)?$/iD', trim($declared)) === 1;
    }

    /**
     * Whether every alternative of a declared type but null is a bare name
     * (`declaresOnlyNamedTypes`, `analyzer.ts:1220-1226`).
     */
    public static function namesOnly(string $declared): bool
    {
        $kinds = array_filter(array_map(self::canon(...), self::alternatives($declared)), static fn(string $kind): bool => !in_array($kind, ['null', 'undefined', 'void'], true));

        return $kinds !== [] && !in_array(false, array_map(static fn(string $kind): bool => $kind === 'nominal', $kinds), true);
    }

    /**
     * Whether a value of fixture kind `$kind` fits `$declared` in `$language`;
     * null when that is unknown.
     */
    public static function fits(string $kind, string $declared, string $language, TestFidelityGraph $graph): ?bool
    {
        $lattice = self::latticeFits($kind, $declared, $language);
        $verdict = match ($language) {
            'php' => PhpTypeFit::judge(self::PHP_PINS[$kind], $declared, $graph, null, false)['fits'],
            'python' => PythonTypeFit::judge(self::PYTHON_PINS[$kind], $declared, $graph),
            default => $lattice,
        };
        if ($verdict === true || $language !== 'php' && $language !== 'python') {
            return $verdict;
        }
        if ($kind === Value::FLOAT && self::fits(Value::INT, $declared, $language, $graph) === true) {
            return true;
        }

        return ($kind === Value::LIST || $kind === Value::DICT) && $lattice ? true : $verdict;
    }

    /** Whether Nemesis's lattice lets the kind satisfy any alternative of the declared type (`typesCompatible`, `analyzer.ts:1228-1245`). */
    private static function latticeFits(string $kind, string $declared, string $language): bool
    {
        foreach (self::alternatives($declared) as $alternative) {
            if (self::satisfies($kind, self::canon($alternative), $language)) {
                return true;
            }
        }

        return false;
    }

    /** Whether a value of kind `$kind` satisfies a declared alternative of canonical kind `$declared` (`kindSatisfies`, `analyzer.ts:1105-1147`). */
    private static function satisfies(string $kind, string $declared, string $language): bool
    {
        $php = $language === 'php';

        return $declared === 'wild' || match ($kind) {
            Value::NULL => in_array($declared, ['null', 'undefined', 'void'], true),
            Value::INT, Value::FLOAT => $declared === 'int' || $declared === 'float',
            Value::LIST => $declared === 'list' || ($php && $declared === 'dict'),
            Value::DICT => $declared === 'dict' || $declared === 'nominal' || ($php && $declared === 'list'),
            default => $declared === $kind,
        };
    }

    /**
     * A declared type as the alternatives it may hold (`alternatives`,
     * `analyzer.ts:1032-1068`): `string | null` is `string` and `null`,
     * `Option<T>` and `Optional[T]` are `T` and `null`.
     *
     * @return list<string>
     */
    private static function alternatives(string $raw, int $depth = 0): array
    {
        $type = trim($raw);
        if ($type === '' || $depth > self::MAX_DEPTH) {
            return [$type];
        }
        $type = (string) preg_replace(['/^readonly\s+/', '/^\\\\/'], '', $type);
        if (str_starts_with($type, '?')) {
            return [...self::alternatives(substr($type, 1), $depth + 1), 'null'];
        }
        foreach ([self::ASYNC_WRAPPER, self::PY_ASYNC, '/^Result\s*<([\s\S]+)>$/D'] as $wrapper) {
            if (preg_match($wrapper, $type, $match) === 1) {
                return self::alternatives(self::split($match[1], ',')[0] ?? '', $depth + 1);
            }
        }
        if (preg_match('/^(?:Optional\s*\[([\s\S]+)]|Option\s*<([\s\S]+)>)$/D', $type, $match) === 1) {
            return [...self::alternatives($match[1] !== '' ? $match[1] : $match[2], $depth + 1), 'null'];
        }
        $parts = preg_match('/^Union\s*\[([\s\S]+)]$/D', $type, $match) === 1 ? self::split($match[1], ',') : self::split($type, '|');
        if (count($parts) > 1 || $parts !== [$type]) {
            return array_merge(...array_map(static fn(string $part): array => self::alternatives($part, $depth + 1), $parts));
        }

        return [$type];
    }

    /** One alternative's canonical kind, or `nominal` for a name (`canon`, `analyzer.ts:1099-1166`). */
    private static function canon(string $raw): string
    {
        $type = (string) preg_replace(['/^readonly\s+/', '/^\\\\/'], '', trim($raw));
        if ($type === '' || preg_match('/^[A-Z]\d?$/D', $type) === 1 || preg_match('/[<(\[,]\s*[A-Z]\d?\s*[>)\],]/', $type) === 1) {
            return 'wild';
        }

        return match (true) {
            preg_match('/\[\s*]$/D', $type) === 1 => 'list',
            preg_match('/^([\'"`])[\s\S]*\1$/D', $type) === 1 => 'string',
            preg_match('/^-?\d+\.\d+$/D', $type) === 1 => 'float',
            preg_match('/^-?\d+$/D', $type) === 1 => 'int',
            preg_match('/^(?:true|false)$/iD', $type) === 1 => 'bool',
            preg_match('/^\{[\s\S]*}$/D', $type) === 1 => 'dict',
            // An indexed access (`Stats['users']`) names one member of a type, which nothing here resolves.
            preg_match('/^[\w$.\\\\]+\s*\[\s*(?:([\'"`])[^\'"`]*\1|\d+)\s*]$/D', $type) === 1 => 'wild',
            default => self::named($type),
        };
    }

    /** The kind a type's head names: a primitive, a container, or a name. */
    private static function named(string $type): string
    {
        $head = preg_match('/^([\w\\\\.$]+)\s*[<\[]/', $type, $match) === 1 ? $match[1] : $type;
        $parts = preg_split('/[\\\\.]/', $head);
        $short = (string) end($parts);
        if (preg_match('/^(?:string|str|&str|String|text|char)$/D', $short) === 1) {
            return 'string';
        }
        foreach (self::KINDS as $kind => $pattern) {
            if (preg_match($pattern, strtolower($short)) === 1) {
                return $kind;
            }
        }

        return 'nominal';
    }

    /**
     * A type's members at the top level, split on `$separator` outside
     * brackets and quotes (`splitTopLevel`, `analyzer.ts:991-1021`).
     *
     * @return list<string>
     */
    private static function split(string $type, string $separator): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;
        foreach (str_split($type) as $character) {
            if ($quote !== null) {
                $current .= $character;
                $quote = $character === $quote ? null : $quote;
                continue;
            }
            if ($character === '"' || $character === "'" || $character === '`') {
                $quote = $character;
            } elseif (str_contains('<[({', $character)) {
                ++$depth;
            } elseif (str_contains('>])}', $character)) {
                --$depth;
            } elseif ($character === $separator && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $character;
        }
        $parts[] = $current;

        return array_values(array_filter(array_map(trim(...), $parts), static fn(string $part): bool => $part !== ''));
    }
}

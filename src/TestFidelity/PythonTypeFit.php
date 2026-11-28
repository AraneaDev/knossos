<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

/**
 * Whether a value a Python test pins fits the replaced callable's annotated
 * return type, judged in core because the Python worker has no type checker.
 *
 * The pin's type is one the worker reads from the expression alone: `int`,
 * `float`, `complex`, `str`, `bytes`, `bool`, `None`, `list`, `dict`, `set`,
 * `tuple`, `class:<qualname>` or `callable`; anything else is unknown.
 *
 * Only these annotations are judged: the builtins and their container kinds
 * (`list[str]` admits any `list`, its items unread), `typing`'s aliases of
 * them (`List`, `Dict`, `Set`, `Tuple`, `FrozenSet`, `Text`), `Optional`,
 * `Union` and `|`, `Annotated`'s first argument, the numeric tower (`bool`
 * fits `int`, `int` fits `float`, either fits `complex`, as PEP 484 has it),
 * and `Any` and `object`, which admit everything. A quoted annotation is read
 * as the annotation it quotes. Everything else is unknown: a project class
 * name, a Protocol, a TypeVar, `Literal`, `Self`, `Iterator`, an alias, a
 * `NewType`.
 *
 * A `class:X` pin is refused by a builtin only when the scan holds `X` with a
 * complete lineage: an ancestor outside the project, or a list left open (a
 * builtin base such as `int` gives no edge, so it opens the list), may make
 * `X` a subclass of that builtin.
 */
final class PythonTypeFit
{
    /** Each builtin type an annotation may name, with the pinned kinds it admits. */
    private const BUILTINS = [
        'bool' => ['bool'], 'int' => ['bool', 'int'], 'float' => ['bool', 'int', 'float'], 'complex' => ['bool', 'int', 'float', 'complex'],
        'str' => ['str'], 'bytes' => ['bytes'], 'None' => ['None'],
        'list' => ['list'], 'dict' => ['dict'], 'set' => ['set'], 'tuple' => ['tuple'], 'frozenset' => [], 'bytearray' => [],
    ];

    /** The pinned types the worker names, `class:<qualname>` aside, that a builtin may admit. */
    private const PINNED = ['bool', 'int', 'float', 'complex', 'str', 'bytes', 'None', 'list', 'dict', 'set', 'tuple'];

    /** `typing`'s names for builtin types. */
    private const ALIASES = ['List' => 'list', 'Dict' => 'dict', 'Set' => 'set', 'Tuple' => 'tuple', 'FrozenSet' => 'frozenset', 'Text' => 'str', 'NoneType' => 'None'];

    /** The modules whose names an annotation may spell in full. */
    private const QUALIFIERS = '/^(?:typing|typing_extensions|builtins)\./';

    /** The types that admit every value. */
    private const TOP = ['Any', 'object'];

    /** How deep a quoted annotation is read inside another one. */
    private const MAX_DEPTH = 4;

    private function __construct() {}

    /**
     * Whether `$pinned` fits `$declared`: null when that is unknown, for an
     * unannotated callable, a pin of unknown type, or an annotation this
     * does not judge.
     */
    public static function judge(string $pinned, ?string $declared, TestFidelityGraph $graph): ?bool
    {
        if ($declared === null || (!str_starts_with($pinned, 'class:') && !in_array($pinned, self::PINNED, true))) {
            return null;
        }
        $annotation = PythonAnnotation::parse($declared);

        return $annotation === null ? null : self::fits($pinned, $annotation, $graph, 0);
    }

    /** Whether the pin fits one parsed annotation; null when unknown. */
    private static function fits(string $pinned, PythonAnnotation $annotation, TestFidelityGraph $graph, int $depth): ?bool
    {
        if ($annotation->form === PythonAnnotation::UNION) {
            return self::any(array_map(static fn(PythonAnnotation $member): ?bool => self::fits($pinned, $member, $graph, $depth), $annotation->members));
        }
        if ($annotation->form === PythonAnnotation::QUOTED) {
            $quoted = $depth < self::MAX_DEPTH ? PythonAnnotation::parse($annotation->text) : null;

            return $quoted === null ? null : self::fits($pinned, $quoted, $graph, $depth + 1);
        }
        if ($annotation->form !== PythonAnnotation::NAME) {
            return null;
        }
        $name = self::normalised($annotation->text);
        $arguments = $annotation->arguments ?? [];

        return match (true) {
            in_array($name, self::TOP, true) => true,
            $name === 'Optional' && count($arguments) === 1 => self::fits($pinned, PythonAnnotation::union([$arguments[0], PythonAnnotation::name('None')]), $graph, $depth),
            $name === 'Union' && $arguments !== [] => self::fits($pinned, PythonAnnotation::union($arguments), $graph, $depth),
            $name === 'Annotated' && $arguments !== [] => self::fits($pinned, $arguments[0], $graph, $depth),
            isset(self::BUILTINS[$name]) => str_starts_with($pinned, 'class:') ? self::classFits(substr($pinned, 6), $graph) : in_array($pinned, self::BUILTINS[$name], true),
            default => null,
        };
    }

    /** A builtin name as an annotation spells it, without its module and under its builtin name. */
    private static function normalised(string $name): string
    {
        $name = (string) preg_replace(self::QUALIFIERS, '', $name);

        return self::ALIASES[$name] ?? $name;
    }

    /**
     * True when any verdict is, else null when any is unknown, else false.
     *
     * @param list<?bool> $verdicts
     */
    private static function any(array $verdicts): ?bool
    {
        if (in_array(true, $verdicts, true)) {
            return true;
        }

        return in_array(null, $verdicts, true) ? null : false;
    }

    /**
     * Whether an instance of the project class `$name` fits a builtin type:
     * false when its whole lineage is in the scan with complete name lists,
     * so it cannot be a subclass of the builtin; null otherwise.
     */
    private static function classFits(string $name, TestFidelityGraph $graph): ?bool
    {
        $class = $graph->pythonClass($name);

        return $class === null || !$graph->hasClosedLineage($class) ? null : false;
    }
}

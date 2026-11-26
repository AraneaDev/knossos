<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Synthesis;

use Closure;

/**
 * An example value of a written type, for a template's stubbed return
 * (Decision 5): a primitive's zero value, Momus's table per language
 * (`tsReturnExample`, `phpReturnExample`, `pyReturnExample`,
 * `rustReturnExample`); a project record whose field list is complete as a
 * literal built from it, at most two levels deep, or an enum's first case;
 * and for anything else a marked placeholder, `/* knossos: fill in Widget *\/`
 * beside the language's own "not yet" form, listed in `unknown` (Python's
 * `None` is marked at the end of its line, having no inline comment).
 *
 * Records come from a lookup the caller gives, which knows the graph: it
 * answers a type's name (in the file that writes it) with a record's
 * fields, a constructible PHP class's constructor parameters, or an enum's
 * case, and null for any type it cannot vouch for.
 *
 * @phpstan-type Record array{kind: 'record', name: string, fields: array<string, ?string>, methods: list<string>, import: ?string, file: string}
 * @phpstan-type EnumCase array{kind: 'enum', value: string, import: ?string}
 * @phpstan-type Example array{value: ?string, unknown: list<string>, imports: list<string>}
 */
final readonly class ExampleValue
{
    /** How deep a record literal nests: a record, and the records its fields hold. */
    private const MAX_DEPTH = 2;

    private const RUST_INTEGERS = ['u8', 'u16', 'u32', 'u64', 'u128', 'usize', 'i8', 'i16', 'i32', 'i64', 'i128', 'isize'];

    /**
     * @param Closure(string, string): (Record|EnumCase|null) $lookup a type's name and the file writing it => what the graph vouches for
     * @param string $stub a TypeScript function stub (`vi.fn()`, `jest.fn()`)
     */
    public function __construct(private string $language, private Closure $lookup, private string $stub = 'vi.fn()') {}

    /**
     * An example of `$type`, written in `$file`: `value` null when no value
     * can be returned (`never`, `!` is `panic!()`).
     *
     * @return Example
     */
    public function of(?string $type, string $file, int $depth = 0): array
    {
        if ($type === null) {
            return self::plain($this->top());
        }

        return match ($this->language) {
            'typescript' => $this->typescript(trim($type), $file, $depth),
            'php' => $this->php(trim($type), $file, $depth),
            'python' => $this->python(trim($type), $file, $depth),
            default => $this->rust(trim($type), $file, $depth),
        };
    }

    /**
     * The marked placeholder for `$type`: the language's "not yet" form, listed in `unknown`.
     *
     * @return Example
     */
    public function placeholder(string $type): array
    {
        $mark = 'knossos: fill in ' . str_replace('*/', '* /', $type);
        $value = match ($this->language) {
            'typescript' => sprintf('undefined as unknown as %s /* %s */', $type, $mark),
            'php' => sprintf('null /* %s */', $mark),
            // Python has no inline comment: the template marks the line instead ({@see PythonTemplate}).
            'python' => 'None',
            default => sprintf('todo!() /* %s */', $mark),
        };

        return ['value' => $value, 'unknown' => ['value of ' . $type], 'imports' => []];
    }

    /** The value a top type gets: `undefined`, `null`, `None` or `todo!()`. */
    public function top(): string
    {
        return ['typescript' => 'undefined', 'php' => 'null', 'python' => 'None'][$this->language] ?? 'todo!()';
    }

    /** @return Example */
    private static function plain(?string $value): array
    {
        return ['value' => $value, 'unknown' => [], 'imports' => []];
    }

    /**
     * Join examples into one value, `$format` given each value in turn.
     *
     * @param list<Example> $parts
     * @param Closure(list<string>): string $format
     * @return Example
     */
    private static function joined(array $parts, Closure $format): array
    {
        $values = [];
        $unknown = $imports = [];
        foreach ($parts as $part) {
            $values[] = $part['value'] ?? 'undefined';
            $unknown = [...$unknown, ...$part['unknown']];
            $imports = [...$imports, ...$part['imports']];
        }

        return ['value' => $format($values), 'unknown' => $unknown, 'imports' => $imports];
    }

    /** @return Example */
    private function typescript(string $type, string $file, int $depth): array
    {
        $zero = ['number' => '0', 'bigint' => '0n', 'string' => "''", 'boolean' => 'false', 'true' => 'true', 'false' => 'false', 'null' => 'null', 'undefined' => 'undefined', 'void' => 'undefined', 'any' => 'undefined', 'unknown' => 'undefined', 'object' => '{}', 'symbol' => 'Symbol()'];
        if (isset($zero[$type])) {
            return self::plain($zero[$type]);
        }
        if ($type === 'never') {
            return self::plain(null);
        }
        if (preg_match('/^-?\d+(?:\.\d+)?$/', $type) === 1) {
            return self::plain($type);
        }
        $union = TypeText::split($type, '|');
        if (count($union) > 1) {
            $chosen = array_values(array_filter($union, static fn(string $member): bool => !in_array($member, ['null', 'undefined', 'void'], true)));

            return $this->typescript($chosen[0] ?? $union[0], $file, $depth);
        }
        if (preg_match('/^([\'"])(.*)\1$/s', $type, $match) === 1) {
            return self::plain((string) json_encode(stripcslashes($match[2]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        if (($inner = TypeText::enclosed($type, '(', ')')) !== null && !str_contains($type, '=>')) {
            return $this->typescript($inner, $file, $depth);
        }
        if (str_ends_with($type, '[]') || str_starts_with($type, 'readonly ')) {
            return self::plain('[]');
        }
        if (($elements = TypeText::enclosed($type, '[', ']')) !== null) {
            $parts = TypeText::split($elements, ',');
            if (array_filter($parts, static fn(string $part): bool => str_contains($part, '?') || str_starts_with($part, '...') || str_contains($part, ':')) !== []) {
                return $this->placeholder($type);
            }

            return self::joined(array_map(fn(string $part): array => $this->typescript($part, $file, $depth), $parts), static fn(array $values): string => '[' . implode(', ', $values) . ']');
        }
        if (($members = TypeText::enclosed($type, '{', '}')) !== null) {
            return $this->typeLiteral($type, $members, $file, $depth);
        }
        if (str_starts_with($type, '(') || str_starts_with($type, '<') || str_starts_with($type, 'new ')) {
            return self::plain($this->stub);
        }
        $generic = TypeText::generic($type);
        $name = $generic[0] ?? $type;
        $builtin = ['Array' => '[]', 'ReadonlyArray' => '[]', 'Date' => 'new Date()', 'RegExp' => '/./', 'Map' => 'new Map()', 'Set' => 'new Set()', 'WeakMap' => 'new WeakMap()', 'WeakSet' => 'new WeakSet()', 'Record' => '{}', 'Partial' => '{}'];
        if (isset($builtin[$name])) {
            return self::plain($builtin[$name]);
        }
        if ($name === 'Promise') {
            $resolved = $this->typescript($generic[1][0] ?? 'unknown', $file, $depth);

            return ['value' => sprintf('Promise.resolve(%s)', $resolved['value'] ?? 'undefined')] + $resolved;
        }

        return $generic === null ? $this->record($type, $file, $depth) : $this->placeholder($type);
    }

    /**
     * An inline object type's literal, each property its example and each
     * method a stub; a placeholder for an index signature or a member it cannot read.
     *
     * @return Example
     */
    private function typeLiteral(string $type, string $members, string $file, int $depth): array
    {
        $parts = [];
        foreach (TypeText::split(str_replace(';', ',', $members), ',') as $member) {
            if (preg_match('/^(?:readonly\s+)?([A-Za-z_$][\w$]*)(\??)\s*(\(|<|:)(.*)$/s', $member, $match) !== 1) {
                return $this->placeholder($type);
            }
            $parts[$match[1]] = $match[3] === ':' ? $this->typescript(trim($match[4]), $file, $depth) : self::plain($this->stub);
        }

        return self::joined(array_values($parts), static fn(array $values): string => $values === [] ? '{}' : '{ ' . implode(', ', array_map(static fn(string $name, string $value): string => $name . ': ' . $value, array_keys($parts), $values)) . ' }');
    }

    /** @return Example */
    private function php(string $type, string $file, int $depth): array
    {
        if (str_starts_with($type, '?')) {
            return $this->php(substr($type, 1), $file, $depth);
        }
        $union = TypeText::split($type, '|');
        if (count($union) > 1) {
            $chosen = array_values(array_filter($union, static fn(string $member): bool => strtolower($member) !== 'null'));

            return $this->php($chosen[0] ?? 'null', $file, $depth);
        }
        $zero = ['int' => '0', 'float' => '0.0', 'string' => "''", 'bool' => 'false', 'true' => 'true', 'false' => 'false', 'array' => '[]', 'iterable' => '[]', 'null' => 'null', 'void' => 'null', 'mixed' => 'null'];
        $lower = strtolower($type);
        if (isset($zero[$lower])) {
            return self::plain($zero[$lower]);
        }
        if ($lower === 'never') {
            return self::plain(null);
        }
        if (str_contains($type, '&') || str_contains($type, '(') || in_array($lower, ['object', 'callable', 'self', 'static', 'parent', 'closure'], true)) {
            return $this->placeholder($type);
        }

        return $this->record($type, $file, $depth);
    }

    /** @return Example */
    private function python(string $type, string $file, int $depth): array
    {
        $union = TypeText::split($type, '|');
        if (count($union) === 1 && preg_match('/^([\'"])(.*)\1$/s', $type, $match) === 1) {
            return $this->python(trim($match[2]), $file, $depth);
        }
        $generic = TypeText::generic($type, '[');
        $name = preg_replace('/^(?:typing|typing_extensions|collections\.abc)\./', '', $generic[0] ?? $type);
        if (count($union) === 1 && in_array($name, ['Optional', 'Union'], true) && $generic !== null) {
            $union = [...$generic[1], 'None'];
        }
        if (count($union) > 1) {
            $chosen = array_values(array_filter($union, static fn(string $member): bool => $member !== 'None'));

            return $this->python($chosen[0] ?? 'None', $file, $depth);
        }
        $zero = ['int' => '0', 'float' => '0.0', 'complex' => '0j', 'str' => "''", 'bytes' => "b''", 'bool' => 'False', 'None' => 'None', 'Any' => 'None', 'object' => 'None',
            'list' => '[]', 'List' => '[]', 'Sequence' => '[]', 'Iterable' => '[]', 'MutableSequence' => '[]', 'dict' => '{}', 'Dict' => '{}', 'Mapping' => '{}', 'MutableMapping' => '{}',
            'set' => 'set()', 'Set' => 'set()', 'AbstractSet' => 'set()', 'frozenset' => 'frozenset()', 'FrozenSet' => 'frozenset()'];
        if (isset($zero[$name])) {
            return self::plain($zero[$name]);
        }
        if ($name === 'NoReturn' || $name === 'Never') {
            return self::plain(null);
        }
        if ($name === 'Literal' && $generic !== null) {
            return self::plain($generic[1][0] ?? 'None');
        }
        if ($name === 'tuple' || $name === 'Tuple') {
            $elements = $generic[1] ?? [];
            if ($elements === [] || in_array('...', $elements, true)) {
                return self::plain('()');
            }

            return self::joined(array_map(fn(string $element): array => $this->python($element, $file, $depth), $elements), static fn(array $values): string => '(' . implode(', ', $values) . (count($values) === 1 ? ',' : '') . ')');
        }

        return $generic === null ? $this->record($type, $file, $depth) : $this->placeholder($type);
    }

    /** @return Example */
    private function rust(string $type, string $file, int $depth): array
    {
        if ($type === '()') {
            return self::plain('()');
        }
        if ($type === '!') {
            return self::plain('panic!()');
        }
        if (preg_match('/^&(?:\'\w+\s+)?str$/', $type) === 1) {
            return self::plain('""');
        }
        if (($inner = TypeText::enclosed($type, '(', ')')) !== null) {
            $elements = TypeText::split($inner, ',');

            return self::joined(array_map(fn(string $element): array => $this->rust($element, $file, $depth), $elements), static fn(array $values): string => '(' . implode(', ', $values) . (count($values) === 1 ? ',' : '') . ')');
        }
        $generic = TypeText::generic($type);
        $path = $generic[0] ?? $type;
        $name = ($at = strrpos($path, ':')) === false ? $path : substr($path, $at + 1);
        if (in_array($name, self::RUST_INTEGERS, true)) {
            return self::plain('0');
        }
        $zero = ['f32' => '0.0', 'f64' => '0.0', 'bool' => 'false', 'char' => "'a'", 'String' => 'String::from("")', 'Option' => 'None', 'Vec' => 'vec![]',
            'HashMap' => 'Default::default()', 'BTreeMap' => 'Default::default()', 'HashSet' => 'Default::default()', 'BTreeSet' => 'Default::default()', 'VecDeque' => 'Default::default()'];
        if (isset($zero[$name])) {
            return self::plain($zero[$name]);
        }
        if ($name === 'Result' && $generic !== null) {
            $ok = $this->rust($generic[1][0] ?? '()', $file, $depth);

            return ['value' => sprintf('Ok(%s)', $ok['value'] ?? 'todo!()')] + $ok;
        }
        if ($name === 'Box' && $generic !== null) {
            $boxed = $this->rust($generic[1][0] ?? '()', $file, $depth);

            return ['value' => sprintf('Box::new(%s)', $boxed['value'] ?? 'todo!()')] + $boxed;
        }
        if ($generic !== null || preg_match('/^[\w:]+$/', $type) !== 1 || $name === 'Self') {
            return $this->placeholder($type);
        }

        return $this->record($type, $file, $depth);
    }

    /**
     * A project type by name: a record's literal, a constructible PHP
     * class's `new`, an enum's first case; a placeholder for anything the
     * lookup cannot vouch for, or a record past the depth kept.
     *
     * @return Example
     */
    private function record(string $type, string $file, int $depth): array
    {
        $found = $depth < self::MAX_DEPTH ? ($this->lookup)($type, $file) : null;
        if ($found === null) {
            return $this->placeholder($type);
        }
        $imports = $found['import'] === null ? [] : [$found['import']];
        if ($found['kind'] === 'enum') {
            return ['value' => $found['value'], 'unknown' => [], 'imports' => $imports];
        }
        $parts = [];
        foreach ($found['fields'] as $field => $fieldType) {
            $parts[$field] = $fieldType === null ? $this->placeholder('the type of ' . $found['name'] . '.' . $field) : $this->of($fieldType, $found['file'], $depth + 1);
        }
        foreach ($found['methods'] as $method) {
            $parts[$method] = self::plain($this->stub);
        }
        $names = array_map(strval(...), array_keys($parts));
        $joined = self::joined(array_values($parts), fn(array $values): string => $this->literal($found['name'], array_combine($names, $values)));

        return ['value' => $joined['value'], 'unknown' => $joined['unknown'], 'imports' => [...$imports, ...$joined['imports']]];
    }

    /**
     * A record literal in the language's form.
     *
     * @param array<string, string> $values field => value
     */
    private function literal(string $name, array $values): string
    {
        $pairs = static fn(string $between): array => array_map(static fn(string $field, string $value): string => $field . $between . $value, array_keys($values), $values);

        return match ($this->language) {
            'typescript' => $values === [] ? '{}' : '{ ' . implode(', ', $pairs(': ')) . ' }',
            'php' => sprintf('new %s(%s)', $name, implode(', ', $pairs(': '))),
            'python' => sprintf('%s(%s)', $name, implode(', ', $pairs('='))),
            default => $values === [] ? $name : sprintf('%s { %s }', $name, implode(', ', $pairs(': '))),
        };
    }
}

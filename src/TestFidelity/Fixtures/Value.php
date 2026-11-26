<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Fixtures;

/**
 * One value of a fixture file as core's own readers parsed it: its kind, its
 * scalar, its children and the line it is on. A member of an object carries
 * its key's line, so a finding about a key points at that key.
 *
 * The kinds are the lattice names Nemesis gives a parsed value
 * (`staleFixtures.ts:699-714`): `null`, `bool`, `int`, `float`, `string`,
 * `list` and `dict`.
 */
final readonly class Value
{
    public const NULL = 'null';

    public const BOOL = 'bool';

    public const INT = 'int';

    public const FLOAT = 'float';

    public const STRING = 'string';

    public const LIST = 'list';

    public const DICT = 'dict';

    /**
     * @param array<array-key, Value> $children a `dict`'s members by key, in file order, or a `list`'s items; empty for a scalar
     */
    public function __construct(
        public string $kind,
        public int|float|string|bool|null $scalar,
        public array $children,
        public int $line,
    ) {}

    /** A scalar value. */
    public static function scalar(int|float|string|bool|null $scalar, int $line): self
    {
        return new self(match (true) {
            $scalar === null => self::NULL,
            is_bool($scalar) => self::BOOL,
            is_int($scalar) => self::INT,
            is_float($scalar) => self::FLOAT,
            default => self::STRING,
        }, $scalar, [], $line);
    }

    /** The same value on another line: an object member, which sits on its key's line. */
    public function at(int $line): self
    {
        return new self($this->kind, $this->scalar, $this->children, $line);
    }

    /** Whether this is an object. */
    public function isDict(): bool
    {
        return $this->kind === self::DICT;
    }

    /** Whether this is an array. */
    public function isList(): bool
    {
        return $this->kind === self::LIST;
    }

    /**
     * An object's keys, in file order, as the text they were written as.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return $this->isDict() ? array_map(strval(...), array_keys($this->children)) : [];
    }

    /** An object's member under `$key`; null when it has none, or is no object. */
    public function get(string $key): ?self
    {
        return $this->isDict() ? $this->children[$key] ?? null : null;
    }

    /** Whether an object has a member under `$key`. */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * An array's items, or an object's members' values; empty for a scalar.
     *
     * @return list<self>
     */
    public function items(): array
    {
        return array_values($this->children);
    }

    /**
     * The objects a value holds: itself when it is one, an array's items that are.
     *
     * @return list<self>
     */
    public function objects(): array
    {
        if ($this->isDict()) {
            return [$this];
        }

        return $this->isList() ? array_values(array_filter($this->children, static fn(self $item): bool => $item->isDict())) : [];
    }
}

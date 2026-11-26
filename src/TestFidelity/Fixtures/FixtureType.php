<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Fixtures;

/**
 * A type a fixture may hold records of: one with a whole, closed field list
 * (Nemesis's `DtoLike`, `staleFixtures.ts:193-203`), and what else the scan
 * says about how a deserialiser fills it.
 *
 * A type whose lineage answers to names it does not declare (`magic`: PHP
 * `__get` or `__set`, Python `__getattr__` or `__getattribute__`) takes any
 * key. One whose lineage declares a constructor (`constructed`) may set a
 * field the fixture leaves out, so only the fields the constructor itself
 * takes (`promoted`, PHP's promoted properties) are required of it. A PHP
 * constructor taking a parameter no field names (`keysRenamed`,
 * `__construct(int $amount)` setting `$this->cents`) says the wire's keys are
 * not the fields' names at all.
 */
final readonly class FixtureType
{
    /**
     * @param string $name the type's name as written, the last segment of its canonical name
     * @param string $language the packaged language that declares it: `typescript`, `php`, `python` or `rust`
     * @param string $file the project-relative file that declares it
     * @param array<string, array{type: ?string, required: bool, line: int, visibility?: string}> $fields every field, inherited ones included
     * @param list<string> $promoted the fields a constructor takes as its parameters
     */
    public function __construct(
        public string $name,
        public string $canonical,
        public string $language,
        public string $file,
        public array $fields,
        public bool $magic = false,
        public bool $constructed = false,
        public bool $keysRenamed = false,
        public array $promoted = [],
    ) {}

    /**
     * Whether a fixture must hold `$field`: the type requires it, it is
     * public (a protected or private field is set by the type's own code,
     * and is only a known key), and nothing else may fill it.
     */
    public function requires(string $field): bool
    {
        return ($this->fields[$field]['required'] ?? false) && !isset($this->fields[$field]['visibility']) && !$this->keysRenamed && (!$this->constructed || in_array($field, $this->promoted, true));
    }

    /** Whether a key no field names is drift: the type neither answers to any name nor reads its keys under other names. */
    public function refusesUnknownKeys(): bool
    {
        return !$this->magic && !$this->keysRenamed;
    }
}

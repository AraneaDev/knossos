<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Fixtures;

use Knossos\TestFidelity\Facts\EnumCases;
use Knossos\TestFidelity\TestFidelityGraph;

/**
 * The types and enums a project's fixtures are checked against, and how a
 * field's declared type names one of them.
 *
 * A name two types share is ambiguous (Decision 6): neither is a candidate,
 * nor what a field's type names. Nemesis keeps the last one indexed. Every
 * type outside test code counts toward that, whether it has fields or not,
 * enums included, so a `User` interface beside a `User` class settles
 * nothing.
 *
 * @phpstan-type Enum array{language: string, name: string, canonical: string, cases: ?EnumCases}
 */
final readonly class FixtureTypes
{
    /** A declared type reduced to the one name it holds: decoration, nullability and a list around it stripped. */
    private const NAME = '/^\\\\?[A-Za-z_][A-Za-z0-9_]*(?:(?:\\\\|::|\.)[A-Za-z_][A-Za-z0-9_]*)*$/D';

    /** @var list<FixtureType> */
    private array $candidates;

    /**
     * @param list<FixtureType> $types every type with a whole, closed field list, outside test code
     * @param list<Enum> $enums every enum outside test code, with its cases when they could be read
     * @param array<string, int> $names every type and enum name outside test code, with how many hold it
     */
    public function __construct(array $types, private array $enums, private array $names, private TestFidelityGraph $graph)
    {
        $candidates = array_values(array_filter($types, fn(FixtureType $type): bool => $type->fields !== [] && ($this->names[$type->name] ?? 1) === 1));
        usort($candidates, static fn(FixtureType $a, FixtureType $b): int => strcmp($a->canonical, $b->canonical));
        $this->candidates = $candidates;
    }

    /**
     * The types a fixture may match, by canonical name.
     *
     * @return list<FixtureType>
     */
    public function candidates(): array
    {
        return $this->candidates;
    }

    /**
     * The candidate a field's declared type names, when it names exactly one
     * (`nestedDto`, `staleFixtures.ts:583-597`): `Address`, `Address[]`,
     * `Address | null`, `?Address`, `list[Address]`, `Vec<Address>`.
     * `Record<string, Address>` is a map whose keys are data, not fields.
     */
    public function nested(FixtureType $owner, string $declared): ?FixtureType
    {
        $name = self::bare($declared);
        if ($name === null) {
            return null;
        }
        $hits = array_values(array_filter($this->candidates, static fn(FixtureType $type): bool => $type->language === $owner->language && self::names($type->language, $type->name, $type->canonical, $name)));

        return count($hits) === 1 ? $hits[0] : null;
    }

    /**
     * The enum a field's declared type names: its name and cases (null when
     * they could not be read), `false` when the name is an enum's but which
     * one is not settled, and null when no enum is named.
     *
     * @return array{name: string, cases: ?EnumCases}|false|null
     */
    public function enumOf(FixtureType $owner, string $declared): array|false|null
    {
        $name = self::bare($declared);
        if ($name === null) {
            return null;
        }
        $hits = array_values(array_filter($this->enums, static fn(array $enum): bool => $enum['language'] === $owner->language && self::names($enum['language'], $enum['name'], $enum['canonical'], $name)));
        if ($hits === []) {
            return null;
        }

        return count($hits) === 1 && ($this->names[$hits[0]['name']] ?? 1) === 1 ? ['name' => $hits[0]['name'], 'cases' => $hits[0]['cases']] : false;
    }

    /** Whether a value of fixture kind `$kind` fits `$declared` in `$language`; null when unknown ({@see KindLattice::fits()}). */
    public function fits(string $kind, string $declared, string $language): ?bool
    {
        return KindLattice::fits($kind, $declared, $language, $this->graph);
    }

    /** Whether how a field writes a type's name names it: a PHP type by its full name in any case, any other by its own name. */
    private static function names(string $language, string $name, string $canonical, string $written): bool
    {
        return $language === 'php' ? strcasecmp(ltrim($canonical, '\\'), ltrim($written, '\\')) === 0 : $name === self::lastSegment($written);
    }

    /** The one name a declared type holds once its decoration is stripped; null when it holds none, or more. */
    private static function bare(string $declared): ?string
    {
        $type = trim($declared);
        for ($pass = 0; $pass < 4; ++$pass) {
            $type = trim((string) preg_replace(
                ['/\s*\|\s*(?:null|undefined|None)\b/i', '/^(?:null|undefined|None)\s*\|\s*/i', '/^readonly\s+/i', '/\[\]$/', '/^\?/', '/\?$/', '/^(?:Array|Option|Vec|Box)<(.+)>$/D', '/^(?:List|list|Optional|Sequence)\[(.+)]$/D'],
                ['', '', '', '', '', '', '$1', '$1'],
                $type,
            ));
        }

        return preg_match(self::NAME, $type) === 1 ? $type : null;
    }

    /** A written name's last segment: `App\Address`, `models.Address` and `crate::Address` all end in `Address`. */
    private static function lastSegment(string $name): string
    {
        $parts = preg_split('/\\\\|::|\./', $name);

        return (string) end($parts);
    }
}

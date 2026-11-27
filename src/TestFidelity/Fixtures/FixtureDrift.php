<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Fixtures;

use Knossos\TestFidelity\FindingConfidence;
use Knossos\TestFidelity\OnRequest\OnRequestFinding;
use Knossos\TestFidelity\OnRequestCategory;

/**
 * `FIXTURE_DRIFT`: a fixture's records against the type they hold, the port
 * of Nemesis's `checkRecord` (`staleFixtures.ts:383-467`). Always a warning
 * (Decision 2, Q2(A)): nothing in the source ties a JSON file to a type, so
 * the match is a heuristic.
 *
 * Per field of the type, in order:
 * - a value of an enum-typed field that is no case of a backed enum is
 *   `not_a_case` (an enum without backing values is not judged);
 * - a value whose kind does not fit the declared type is `kind_mismatch`,
 *   except when the field is untyped, typed only by names, or an enum;
 * - an absent field the type requires is `missing_required`
 *   ({@see FixtureType::requires()}).
 *
 * Then each key the type has no field for is `unknown_key`, unless the type
 * answers to any name or reads its keys under other names
 * ({@see FixtureType::refusesUnknownKeys()}).
 *
 * A key that spells a field in another case style (`weightKg` for
 * `weight_kg`) is taken as that field: a name converter the scan cannot see
 * may map one to the other (Review Focus 2). An object under a field whose
 * type names another candidate is checked as a record of that type, to a
 * depth of {@see self::MAX_NESTING} and never through a type already being
 * checked; a `Record` or map is not. One finding per file, field and
 * message: a field missing from every record is said once, at the first.
 */
final class FixtureDrift
{
    /** The rule's id. */
    public const ID = 'test.fidelity.fixture_drift.v1';

    /** The sub-rules, in catalogue order. */
    public const SUB_RULES = ['not_a_case', 'kind_mismatch', 'missing_required', 'unknown_key'];

    /** How many types deep a nested record is read (`staleFixtures.ts:382`). */
    private const MAX_NESTING = 4;

    /** The longest name, in bytes, a near-name suggestion is sought for or made from: edit distance is quadratic. */
    public const MAX_SUGGESTED = 128;

    /** @var array<string, OnRequestFinding> by key, in the order found */
    private array $findings = [];

    private function __construct(private readonly string $file, private readonly FixtureTypes $types) {}

    /**
     * The drift of `$records`, records of `$type` in fixture `$file`.
     *
     * @param list<Value> $records
     * @return list<OnRequestFinding>
     */
    public static function check(string $file, array $records, FixtureType $type, FixtureTypes $types): array
    {
        $check = new self($file, $types);
        foreach ($records as $record) {
            $check->record($record, $type, '', [$type->canonical => true]);
        }

        return array_values($check->findings);
    }

    /**
     * One record against `$owner`, its labels prefixed by the path to it.
     *
     * @param array<string, true> $seen the types being checked above it
     */
    private function record(Value $record, FixtureType $owner, string $prefix, array $seen): void
    {
        $aliases = self::aliases($record, $owner);
        foreach ($owner->fields as $field => $meta) {
            $field = (string) $field;
            $key = $record->has($field) ? $field : ($aliases[$field] ?? null);
            $value = $key === null ? null : $record->get($key);
            if ($value !== null && $key !== null && !KindLattice::untyped($meta['type'])) {
                $this->value($value, $owner, $field, (string) $meta['type'], $prefix . $key, $seen);
            }
            if ($value === null && $owner->requires($field)) {
                $suggestion = self::nearest($field, $record->keys());
                $this->add('missing_required', $record->line, $owner, $field, sprintf("is missing required field '%s' of %s.", $prefix . $field, $owner->name), $suggestion === null ? '' : sprintf("'%s'", $suggestion));
            }
        }
        if (!$owner->refusesUnknownKeys()) {
            return;
        }
        $spelled = array_flip($aliases);
        foreach ($record->children as $key => $value) {
            $key = (string) $key;
            if (!isset($owner->fields[$key]) && !isset($spelled[$key])) {
                $suggestion = self::nearest($key, array_map(strval(...), array_keys($owner->fields)));
                $this->add('unknown_key', $value->line, $owner, $key, sprintf("has field '%s' which no longer exists on %s.", $prefix . $key, $owner->name), $suggestion === null ? '' : sprintf("'%s'", $suggestion));
            }
        }
    }

    /**
     * A present field's value against its declared type: a nested record, an enum case, or a kind.
     *
     * @param array<string, true> $seen
     */
    private function value(Value $value, FixtureType $owner, string $field, string $declared, string $label, array $seen): void
    {
        $nested = $this->types->nested($owner, $declared);
        if ($nested !== null && $value->objects() !== []) {
            if (count($seen) < self::MAX_NESTING && !isset($seen[$nested->canonical])) {
                foreach ($value->objects() as $child) {
                    $this->record($child, $nested, $label . '.', $seen + [$nested->canonical => true]);
                }
            }

            return;
        }
        $enum = $this->types->enumOf($owner, $declared);
        if ($enum !== null) {
            $this->enumCase($value, $owner, $field, $label, $enum);

            return;
        }
        if ($value->kind === Value::AMBIGUOUS || KindLattice::namesOnly($declared) || $this->types->fits($value->kind, $declared, $owner->language) !== false) {
            return;
        }
        $this->add('kind_mismatch', $value->line, $owner, $field, sprintf("has '%s' as %s but %s declares '%s'.", $label, $value->kind, $owner->name, $declared));
    }

    /**
     * A value against an enum's backing values (`enumValueCheck`,
     * `staleFixtures.ts:737-766`): only a string or a number is read, and
     * only against an enum whose cases carry values.
     *
     * @param array{name: string, cases: ?\Knossos\TestFidelity\Facts\EnumCases}|false $enum
     */
    private function enumCase(Value $value, FixtureType $owner, string $field, string $label, array|false $enum): void
    {
        if ($enum === false || $enum['cases'] === null || !$enum['cases']->backed() || !in_array($value->kind, [Value::STRING, Value::INT, Value::FLOAT], true)) {
            return;
        }
        $backing = array_map(strval(...), array_values($enum['cases']->cases()));
        $written = (string) $value->scalar;
        if (in_array($written, $backing, true)) {
            return;
        }
        $suggestion = self::nearest($written, $backing);
        $this->add('not_a_case', $value->line, $owner, $field, sprintf("has '%s' as %s, which is not a case of %s.", $label, self::json($value->scalar), $enum['name']), $suggestion === null ? '' : self::json($suggestion));
    }

    /**
     * The fields a record spells in another case style: field => the key
     * that spells it, for a field the record does not hold by its own name.
     *
     * @return array<string, string>
     */
    private static function aliases(Value $record, FixtureType $owner): array
    {
        $byStyle = [];
        foreach (array_keys($owner->fields) as $field) {
            $byStyle[FixtureType::styleless((string) $field)][] = (string) $field;
        }
        $aliases = [];
        foreach ($record->keys() as $key) {
            $fields = $byStyle[FixtureType::styleless($key)] ?? [];
            if (!isset($owner->fields[$key]) && count($fields) === 1 && !$record->has($fields[0])) {
                $aliases[$fields[0]] = $key;
            }
        }

        return $aliases;
    }

    /**
     * The candidate nearest `$name` by edit distance, within Nemesis's
     * threshold (`staleFixtures.ts:668-694`); null when none is that near.
     * A name or candidate over {@see self::MAX_SUGGESTED} bytes is never
     * measured, and one whose length alone puts it past the threshold is
     * skipped unmeasured, so a fixture's huge key or value costs no
     * quadratic work.
     *
     * @param list<string> $candidates
     */
    private static function nearest(string $name, array $candidates): ?string
    {
        if (strlen($name) > self::MAX_SUGGESTED) {
            return null;
        }
        $threshold = max(2, intdiv(strlen($name) * 2, 5));
        $best = null;
        $distance = PHP_INT_MAX;
        foreach ($candidates as $candidate) {
            if (strlen($candidate) > self::MAX_SUGGESTED || abs(strlen($candidate) - strlen($name)) > $threshold) {
                continue;
            }
            $edits = levenshtein($name, $candidate);
            if ($edits <= $threshold && $edits < $distance) {
                [$best, $distance] = [$candidate, $edits];
            }
        }

        return $best;
    }

    /** A scalar as JSON writes it. */
    private static function json(int|float|string|bool|null $scalar): string
    {
        return (string) json_encode($scalar, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Record one finding, the first time its sub-rule says `$said` of a field;
     * `$suggestion`, when not empty, is the name it may have meant.
     */
    private function add(string $subRule, int $line, FixtureType $owner, string $field, string $said, string $suggestion = ''): void
    {
        $this->findings[$subRule . "\0" . $owner->canonical . "\0" . $field . "\0" . $said] ??= new OnRequestFinding(
            OnRequestCategory::FixtureDrift,
            FindingConfidence::Warning,
            self::ID,
            $subRule,
            sprintf("Fixture '%s' %s%s", basename($this->file), $said, $suggestion === '' ? '' : sprintf(' Did you mean %s?', $suggestion)),
            $this->file,
            $line,
            null,
            $owner->name . '.' . $field,
            $owner->canonical . '.' . $field,
        );
    }
}

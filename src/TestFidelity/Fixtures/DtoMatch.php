<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Fixtures;

/**
 * Which type a fixture file holds records of, and where in the file the
 * records are: Nemesis's `matchDto`, `normalizeName` and `recordsOf`
 * (`staleFixtures.ts:230-282`, `545-572`, `610-666`), signals and
 * thresholds exact.
 *
 * A type matches when a name lines up (the file's, or a top-level key that
 * holds objects) or when the shapes overlap: at least three shared keys,
 * covering 60% of the fixture's keys and 50% of the type's fields. The best
 * score wins (a name is worth 10, each shared key 1, and the key coverage up
 * to 5); a tie matches nothing.
 */
final readonly class DtoMatch
{
    /** The fewest keys a shape match shares. */
    private const MIN_OVERLAP = 3;

    /** The least of the fixture's keys a shape match covers. */
    private const MIN_KEY_COVERAGE = 0.6;

    /** The least of the type's fields a shape match covers. */
    private const MIN_FIELD_COVERAGE = 0.5;

    /** What a name that lines up is worth. */
    private const NAMED = 10;

    public function __construct(private FixtureTypes $types) {}

    /**
     * The type `$root` holds records of, from a file named `$fixtureName`
     * (its base name without the extension); and the best near miss, with
     * its score, whether or not it matched.
     *
     * @return array{type: ?FixtureType, best: ?array{type: string, score: int}}
     */
    public function match(string $fixtureName, Value $root): array
    {
        $keys = $root->keys();
        $signals = array_map(strtolower(...), [$fixtureName, ...self::containerKeys($root)]);
        $best = null;
        $tied = false;
        $nearest = null;
        foreach ($this->types->candidates() as $type) {
            $short = strtolower($type->name);
            $overlap = count(array_filter($keys, static fn(string $key): bool => isset($type->fields[$key])));
            $keyCoverage = $keys === [] ? 0.0 : $overlap / count($keys);
            $fieldCoverage = $overlap / count($type->fields);
            $named = array_filter($signals, static fn(string $signal): bool => $signal === $short || self::normalizeName($signal) === self::normalizeName($short)) !== [];
            $score = ($named ? self::NAMED : 0) + $overlap + (int) floor($keyCoverage * 5 + 0.5);
            if ($score > 0 && ($nearest === null || $score > $nearest['score'])) {
                $nearest = ['type' => $type->canonical, 'score' => $score];
            }
            if (!$named && !($overlap >= self::MIN_OVERLAP && $keyCoverage >= self::MIN_KEY_COVERAGE && $fieldCoverage >= self::MIN_FIELD_COVERAGE)) {
                continue;
            }
            if ($best === null || $score > $best[1]) {
                [$best, $tied] = [[$type, $score], false];
            } elseif ($score === $best[1]) {
                $tied = true;
            }
        }

        return ['type' => $best !== null && !$tied ? $best[0] : null, 'best' => $nearest];
    }

    /** A name without the decoration around it, so `users.fixture.json` and `UserRecord` meet in the middle (`staleFixtures.ts:230-236`). */
    public static function normalizeName(string $value): string
    {
        return (string) preg_replace(['/[._-]?(?:fixtures?|data|sample|example)$/', '/(?:record|dto|model|entity|schema)$/', '/s$/'], '', strtolower($value), 1);
    }

    /**
     * Where the records of `$type` are in `$root` (`staleFixtures.ts:610-666`):
     * a top-level array's objects; the objects under a key that names the
     * type; else, when the object carries none of the type's fields, those of
     * any top-level array; else the object itself.
     *
     * @return list<Value>
     */
    public static function recordsOf(Value $root, FixtureType $type): array
    {
        if ($root->isList()) {
            return $root->objects();
        }
        if (!$root->isDict()) {
            return [];
        }
        $short = strtolower($type->name);
        $records = [];
        foreach ($root->children as $key => $value) {
            $normalized = (string) preg_replace('/[^a-z]/', '', strtolower((string) $key));
            if ($normalized === $short || $normalized === $short . 's' || $normalized === $short . 'es' || str_contains($normalized, $short)) {
                array_push($records, ...$value->objects());
            }
        }
        $carriesFields = array_intersect($root->keys(), array_map(strval(...), array_keys($type->fields))) !== [];
        if ($records === [] && !$carriesFields) {
            foreach ($root->children as $value) {
                if ($value->isList()) {
                    array_push($records, ...$value->objects());
                }
            }
        }

        return $records === [] ? [$root] : $records;
    }

    /**
     * The top-level keys whose value holds objects: they may name a record
     * collection (`staleFixtures.ts:559-572`). A scalar key is no signal.
     *
     * @return list<string>
     */
    private static function containerKeys(Value $root): array
    {
        $keys = [];
        foreach ($root->isDict() ? $root->children : [] as $key => $value) {
            if ($value->isDict() || ($value->isList() && $value->objects() !== [])) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }
}

<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Facts;

use Knossos\Scanner\Protocol\NodeFact;

/**
 * The cases a node that declares an enum lists in its `cases` attribute
 * (protocol "Field lists"): an `enum` node for TypeScript and PHP, a `class`
 * node for Rust and Python. A node carrying `cases` is an enum, whatever its
 * kind.
 *
 * A case's `value` is its backing literal as text. A worker sends values for
 * every case or for none, so a list that gives some cases a value and not
 * others is malformed. Absent, malformed and truncated lists read as null.
 */
final readonly class EnumCases
{
    /**
     * @param array<string, ?string> $cases name => backing value, null when the enum has none
     */
    public function __construct(private array $cases) {}

    /**
     * Read one node's list; null when it is absent, malformed or truncated.
     *
     * @param NodeFact|array<mixed> $attributes a node, or its decoded attribute block
     */
    public static function ofNode(NodeFact|array $attributes): ?self
    {
        if ($attributes instanceof NodeFact) {
            $attributes = $attributes->attributes;
        }
        $raw = $attributes['cases'] ?? null;
        if (!is_array($raw) || !array_is_list($raw) || !FieldList::isUnset($attributes, 'cases_truncated')) {
            return null;
        }
        $cases = [];
        foreach ($raw as $entry) {
            $name = is_array($entry) ? $entry['name'] ?? null : null;
            $value = is_array($entry) ? $entry['value'] ?? null : null;
            if (!is_string($name) || $name === '' || array_key_exists($name, $cases) || ($value !== null && !is_string($value))) {
                return null;
            }
            $cases[$name] = $value;
        }
        $valued = count(array_filter($cases, is_string(...)));

        return $valued === 0 || $valued === count($cases) ? new self($cases) : null;
    }

    /**
     * Every case by name, with its backing value or null.
     *
     * @return array<string, ?string>
     */
    public function cases(): array
    {
        return $this->cases;
    }

    /** Whether the cases carry backing values a fixture can be held against; false for an empty list. */
    public function backed(): bool
    {
        return $this->cases !== [] && !in_array(null, $this->cases, true);
    }
}

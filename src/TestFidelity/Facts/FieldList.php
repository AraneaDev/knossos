<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Facts;

use Knossos\Scanner\Protocol\NodeFact;

/**
 * The fields a class, interface, type alias or struct node says a
 * deserialiser fills, read from its `fields` attribute (protocol "Field
 * lists").
 *
 * Only a list that is present, well formed, whole and closed is read: a
 * worker that marks it `fields_open` (a wire name may differ, or a field may
 * come from elsewhere) or `fields_truncated` (cut at 64) has said the list
 * is not the wire's whole story, and a malformed one says nothing, so each
 * reads as null and the caller stays silent.
 */
final readonly class FieldList
{
    /** The visibilities a field may declare; an entry without one is public. */
    private const VISIBILITIES = ['public', 'protected', 'private'];

    /**
     * @param array<string, array{type: ?string, required: bool, line: int, visibility?: string}> $fields by name, in the order sent
     */
    public function __construct(private array $fields) {}

    /**
     * Read one node's own list; null when it is absent, malformed, truncated or open.
     *
     * @param NodeFact|array<mixed> $attributes a node, or its decoded attribute block
     */
    public static function ofNode(NodeFact|array $attributes): ?self
    {
        if ($attributes instanceof NodeFact) {
            $attributes = $attributes->attributes;
        }
        $raw = $attributes['fields'] ?? null;
        if (!is_array($raw) || !array_is_list($raw) || !self::isUnset($attributes, 'fields_open') || !self::isUnset($attributes, 'fields_truncated')) {
            return null;
        }
        $fields = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                return null;
            }
            $name = $entry['name'] ?? null;
            $type = $entry['type'] ?? null;
            $required = $entry['required'] ?? null;
            $line = $entry['line'] ?? null;
            $visibility = $entry['visibility'] ?? 'public';
            if (!is_string($name) || $name === '' || isset($fields[$name]) || ($type !== null && !is_string($type)) || !is_bool($required) || !is_int($line) || $line < 1 || !in_array($visibility, self::VISIBILITIES, true)) {
                return null;
            }
            $fields[$name] = ['type' => $type, 'required' => $required, 'line' => $line, ...($visibility === 'public' ? [] : ['visibility' => $visibility])];
        }

        return new self($fields);
    }

    /**
     * Every field by name: its type as written (null when the source gives
     * none), whether a deserialiser needs the key, its line, and, for a
     * protected or private field, its visibility.
     *
     * @return array<string, array{type: ?string, required: bool, line: int, visibility?: string}>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * Whether a flag is absent or false; anything else (true, or a value of the
     * wrong type) counts as set, so a malformed flag never reads as closed.
     *
     * @param array<mixed> $attributes
     */
    public static function isUnset(array $attributes, string $flag): bool
    {
        return ($attributes[$flag] ?? false) === false;
    }
}

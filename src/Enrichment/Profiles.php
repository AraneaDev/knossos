<?php

declare(strict_types=1);

namespace Knossos\Enrichment;

/**
 * What a profile is: the name an enricher tags an edge with to say which
 * configuration (a brand, say) the edge belongs to.
 *
 * The one owner of the name rule. The enrichment validator, the flow search
 * and the explain_flow tool schema all read it here, so they cannot drift.
 */
final class Profiles
{
    /** The edge attribute that carries the profile. */
    public const ATTRIBUTE = 'profile';

    /** One profile name, as a JSON Schema `pattern` (no delimiters). */
    public const PATTERN = '^[A-Za-z0-9_.-]{1,64}$';

    /** Whether `$name` is a valid profile name; a trailing newline is not, unlike PCRE's bare `$`. */
    public static function isName(mixed $name): bool
    {
        return is_string($name) && preg_match('/' . self::PATTERN . '/D', $name) === 1;
    }

    /**
     * The names a `profile` attribute value holds, deduplicated in their
     * original order, or null when the value is no valid profile: not a valid
     * name, nor a non-empty list of valid names.
     *
     * @return ?list<string>
     */
    public static function names(mixed $value): ?array
    {
        $names = is_array($value) && array_is_list($value) ? $value : [$value];
        if ($names === []) {
            return null;
        }
        foreach ($names as $name) {
            if (!self::isName($name)) {
                return null;
            }
        }

        /** @var list<string> $names */
        return array_values(array_unique($names));
    }
}

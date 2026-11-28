<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Rules;

/**
 * Text from a worker, made fit for a finding's message.
 */
final class DriftText
{
    /** The longest a cleaned text is, in characters. */
    public const MAX_LENGTH = 120;

    private function __construct() {}

    /** One line of printable text, cut at `$max` characters ({@see self::MAX_LENGTH} unless a caller keeps room for more). */
    public static function clean(string $text, int $max = self::MAX_LENGTH): string
    {
        $text = trim((string) preg_replace('/[\p{C}\p{Zl}\p{Zp}\s]+/u', ' ', $text));
        if (mb_strlen($text) > $max) {
            return mb_substr($text, 0, $max - 1) . '…';
        }

        return $text;
    }
}

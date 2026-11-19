<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Rules;

/**
 * Text from a worker, made fit for a finding's message.
 */
final class DriftText
{
    private const MAX_LENGTH = 120;

    private function __construct() {}

    /** One line of printable text, cut at {@see self::MAX_LENGTH} characters. */
    public static function clean(string $text): string
    {
        $text = trim((string) preg_replace('/[\p{C}\p{Zl}\p{Zp}\s]+/u', ' ', $text));
        if (mb_strlen($text) > self::MAX_LENGTH) {
            return mb_substr($text, 0, self::MAX_LENGTH - 1) . '…';
        }

        return $text;
    }
}

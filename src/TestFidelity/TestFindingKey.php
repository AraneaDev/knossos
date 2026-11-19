<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

/**
 * The identity of a test finding that survives a rescan and a line move.
 *
 * A finding's row id changes with every scan and its line with every edit above
 * it, so neither can name a finding to a person or a gate. What it says does:
 * the rule, the sub-rule, the file the double is in, the member it is about
 * (the message names it) and the thing it was checked against.
 */
final class TestFindingKey
{
    private function __construct() {}

    /** A 40-character sha1 of the five parts, joined by `|`; a finding without a target keys it as empty. */
    public static function of(string $ruleId, string $subRule, string $file, ?string $target, string $message): string
    {
        return sha1(implode('|', [$ruleId, $subRule, $file, $target ?? '', $message]));
    }
}

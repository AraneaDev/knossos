<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * Which scanner or enricher recorded a diagnostic, read off its owner key
 * (`knossos.typescript:file:src/a.ts` was recorded by `knossos.typescript`).
 * A diagnostic recorded with no owner has no known source: null, never a
 * guess.
 */
final class DiagnosticSource
{
    /** The scanner or enricher named before the owner key's first `:`, or null when the key names none. */
    public static function of(string $ownerKey): ?string
    {
        $source = strstr($ownerKey, ':', true);
        $source = $source === false ? $ownerKey : $source;

        return trim($source) === '' ? null : $source;
    }
}

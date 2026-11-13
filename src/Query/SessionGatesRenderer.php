<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * The gates block of the session brief, at most six lines.
 *
 * Five gates are all listed; more than that and the first four are listed with
 * a last line counting the rest, so the block never outgrows its six lines.
 */
final readonly class SessionGatesRenderer
{
    private const LISTED_WHEN_CUT = 4;
    private const FITS = 5;

    /**
     * The block's lines: the declared gates, the scripts found, or nothing.
     *
     * @param array{declared: list<array{name: string, command: string}>, found: list<string>} $gates
     * @return list<string>
     */
    public static function lines(array $gates): array
    {
        $declared = $gates['declared'];
        if ($declared === []) {
            if ($gates['found'] === []) {
                return [];
            }

            return [sprintf(
                'No gates declared. Scripts found: %s. Declare the ones that must pass under "gates" in knossos.json.',
                implode(', ', $gates['found']),
            )];
        }
        $shown = count($declared) > self::FITS ? array_slice($declared, 0, self::LISTED_WHEN_CUT) : $declared;
        $lines = ['Gates this project declares (run before pushing):'];
        foreach ($shown as $gate) {
            $lines[] = sprintf('- %s: %s', $gate['name'], $gate['command']);
        }
        if (count($shown) < count($declared)) {
            $lines[] = sprintf('- and %d more in knossos.json', count($declared) - count($shown));
        }

        return $lines;
    }
}

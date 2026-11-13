<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * The gates block of the session brief, at most six lines.
 *
 * Up to five gates are listed with their commands. More than that and the
 * block names every gate on one line and says where the commands are, so it
 * stays at three lines and hides no gate.
 */
final readonly class SessionGatesRenderer
{
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
        $heading = 'Gates this project declares (run before pushing):';
        if (count($declared) > self::FITS) {
            // Every name, because the gates an agent trips over are as likely to be the sixth as the first.
            return [
                $heading,
                '- ' . implode(', ', array_map(static fn(array $gate): string => $gate['name'], $declared)),
                '- commands: the "gates" list in knossos.json, or creation_brief for one path',
            ];
        }
        $lines = [$heading];
        foreach ($declared as $gate) {
            $lines[] = sprintf('- %s: %s', $gate['name'], $gate['command']);
        }

        return $lines;
    }
}

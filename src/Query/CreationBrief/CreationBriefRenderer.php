<?php

declare(strict_types=1);

namespace Knossos\Query\CreationBrief;

use Knossos\Query\SessionBriefRenderer;

/**
 * Renders a creation brief as the few lines an agent reads before it writes a file.
 *
 * A line with nothing to say is left out, and an empty brief renders as the
 * empty string, so a caller can tell "no brief" from a short one. The text is
 * cut from the bottom to 14 lines and 1,200 characters; the one line about a
 * stale graph is kept through the cut, because every other line may be wrong
 * when the graph is behind. A gates line too long to survive the cut is not
 * dropped: it falls back to the gate names and where their commands are, and
 * that line is kept through the cut as well.
 */
final class CreationBriefRenderer
{
    /** Most lines a rendering has, the stale line included. */
    public const MAX_LINES = 14;

    /** Most characters a rendering has, newlines included. */
    public const MAX_CHARACTERS = 1200;

    /** Widest a wrapped `here:` line grows, not counting its label, before it continues on the next. */
    private const WRAP = 80;

    /**
     * The brief as text.
     *
     * @param array<string, mixed> $data the `data` of a creation brief envelope
     * @param array<string, mixed>|null $staleness the envelope's staleness, when it carries one
     */
    public static function lines(array $data, ?array $staleness = null): string
    {
        $gates = is_array($data['gates'] ?? null) ? $data['gates'] : [];
        $full = $gates === [] ? null : self::oneLine(sprintf('gates: %s. Run: %s', implode(' · ', array_column($gates, 'name')), implode('; ', array_column($gates, 'command'))));
        $body = array_map(self::oneLine(...), self::body($data, $full));
        if ($body === []) {
            return '';
        }
        $tail = [];
        if (($staleness['state'] ?? null) === 'stale') {
            $tail[] = sprintf(
                'graph is behind (scanned %s ago): %d changed, %d added, %d deleted since the scan',
                SessionBriefRenderer::age(is_int($staleness['age_seconds'] ?? null) ? $staleness['age_seconds'] : null),
                (int) ($staleness['changed_files_since'] ?? 0),
                (int) ($staleness['added_files_since'] ?? 0),
                (int) ($staleness['deleted_files_since'] ?? 0),
            );
        }
        $kept = self::cut($body, $tail);
        if ($full === null || in_array($full, $kept, true)) {
            return implode("\n", [...$kept, ...$tail]);
        }
        $short = self::oneLine(sprintf('gates: %s. Commands: the "gates" list in knossos.json', implode(' · ', array_column($gates, 'name'))));
        $tail = [$short, ...$tail];

        return implode("\n", [...self::cut(array_map(self::oneLine(...), self::body($data, null)), $tail), ...$tail]);
    }

    /**
     * The lines of `$body` that fit beside `$tail`: cut from the bottom to MAX_LINES and MAX_CHARACTERS in all.
     *
     * @param list<string> $body
     * @param list<string> $tail lines kept whatever the cut, after the body
     * @return list<string>
     */
    private static function cut(array $body, array $tail): array
    {
        $body = array_slice($body, 0, max(0, self::MAX_LINES - count($tail)));
        while ($body !== [] && strlen(implode("\n", [...$body, ...$tail])) > self::MAX_CHARACTERS) {
            array_pop($body);
        }

        return $body;
    }

    /**
     * The lines of the brief before any cut, none when nothing is worth saying.
     *
     * @param array<string, mixed> $data
     * @param string|null $gatesLine the gates line to place among the others, or null to leave it out
     * @return list<string>
     */
    private static function body(array $data, ?string $gatesLine): array
    {
        $placement = is_array($data['placement'] ?? null) ? $data['placement'] : [];
        $boundary = is_string($placement['boundary'] ?? null) ? $placement['boundary'] : null;
        $denied = self::strings($placement['may_not_use'] ?? null);
        $neighbours = self::strings($data['neighbours'] ?? null);
        $conventions = is_array($data['conventions'] ?? null) ? $data['conventions'] : [];
        $goesWith = is_array($data['goes_with'] ?? null) ? $data['goes_with'] : [];
        $gates = is_array($data['gates'] ?? null) ? $data['gates'] : [];
        $notes = is_int($data['notes'] ?? null) ? $data['notes'] : 0;
        if ($boundary === null && $denied === [] && $neighbours === [] && $conventions === [] && $goesWith === [] && $gates === [] && $notes === 0) {
            return [];
        }

        $directory = (string) ($data['directory'] ?? '');
        $lines = ['knossos · new file in ' . ($boundary ?? ($directory === '' ? '.' : $directory))];
        if ($denied !== []) {
            $lines[] = 'may not use: ' . implode(', ', $denied);
        }
        if ($neighbours !== []) {
            $lines[] = 'like: ' . implode(', ', $neighbours);
        }
        if ($conventions !== []) {
            $basis = is_array($data['basis'] ?? null) ? $data['basis'] : [];
            $label = ($basis['borrowed'] ?? false) === true ? sprintf('here (in %s, the nearest folder with enough files): ', (string) ($basis['directory'] ?? '')) : 'here: ';
            array_push($lines, ...self::wrapped($label, array_map(self::convention(...), $conventions)));
        }
        if ($goesWith !== []) {
            $lines[] = 'goes with: ' . implode(' · ', array_map(static fn(array $entry): string => $entry['path'] . (isset($entry['commits'], $entry['of']) ? sprintf(' (%d of %d additions)', $entry['commits'], $entry['of']) : ''), $goesWith));
        }
        if ($gatesLine !== null) {
            $lines[] = $gatesLine;
        }
        if ($notes > 0) {
            $lines[] = sprintf('notes: %d on the neighbours and the boundary (list_annotations)', $notes);
        }

        return $lines;
    }

    /**
     * One convention as text: its count of the population, then what it says.
     *
     * @param array<string, mixed> $convention
     */
    private static function convention(array $convention): string
    {
        $text = (string) $convention['text'];

        return $convention['id'] === 'lines' ? $text : sprintf('%d of %d %s', $convention['files'], $convention['of'], $text);
    }

    /**
     * The items joined by a dot, broken onto indented lines when one grows past WRAP characters.
     *
     * @param list<string> $items
     * @return list<string>
     */
    private static function wrapped(string $label, array $items): array
    {
        $lines = [];
        $current = $label;
        $prefix = strlen($label);
        $fresh = true;
        foreach ($items as $item) {
            if (!$fresh && strlen($current . ' · ' . $item) - $prefix > self::WRAP) {
                $lines[] = $current;
                $current = '  ';
                $prefix = 2;
                $fresh = true;
            }
            $current .= ($fresh ? '' : ' · ') . $item;
            $fresh = false;
        }
        $lines[] = $current;

        return $lines;
    }

    /**
     * The text with every control character and Unicode line break replaced by a space.
     *
     * Paths, type names, boundary names and gate commands all come from the
     * repository, and one carrying a line break would forge a line of the brief
     * the reader takes for Knossos's own. Every line passes through here, so a
     * line of the brief is always one line of the output and the 14-line cap
     * counts real lines.
     */
    private static function oneLine(string $text): string
    {
        // Matched as bytes, so a string that is not valid UTF-8 is still cleaned rather than dropped.
        return (string) preg_replace('/[\x00-\x1F\x7F]|\xC2\x85|\xE2\x80[\xA8\xA9]/', ' ', $text);
    }

    /**
     * The strings of a list, or none for anything else.
     *
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}

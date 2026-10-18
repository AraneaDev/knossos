<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

/**
 * Reads an HTML page for the scripts it loads: the only thing that reaches
 * a browser front end's entry module.
 */
final class HtmlManifest implements ManifestReader
{
    /**
     * Elements whose text a browser running scripts never parses as tags, so
     * a script tag written there loads nothing.
     */
    private const UNREAD_TEXT_ELEMENTS = ['template', 'textarea', 'title', 'xmp', 'noscript'];

    /** Script types whose inline text a browser runs as JavaScript; no type at all is the classic default. */
    private const SCRIPT_TYPES = ['', 'module', 'text/javascript', 'application/javascript', 'text/ecmascript', 'application/ecmascript'];

    /**
     * The script references a page makes, each with the line it is on.
     *
     * Only recorded here, never resolved: what `/static/app.mjs` names on
     * disk depends on the other files in the scan, which a reader of one
     * file cannot see. {@see \Knossos\Discovery\EntryPointResolver} resolves
     * them and reports the ones it cannot.
     */
    public function read(string $relative, string $absolute, string $contents): array
    {
        return [
            'script_refs' => self::scriptRefs($contents),
        ];
    }

    /**
     * Every script the page loads, in document order: a `<script src>`, a
     * `<link rel="modulepreload">`, and each module an inline script imports.
     *
     * A browser front end is entered through these and through nothing else:
     * no module in the project imports `main.tsx`, so its in-degree is zero
     * however live it is, and the same is true of a plain `<script>` that
     * publishes runtime configuration onto `window`.
     *
     * The page is read as a browser tokenises it, front to back, so a script
     * tag inside a comment loads nothing and a `<!--` inside a script's text
     * is part of that text. Malformed markup is read leniently: an open tag
     * that never closes is passed over, and a script that never closes runs
     * to the end of the page. Stylesheets, images and links are not code and
     * are never read, so ordinary prose links cannot suppress anything.
     *
     * The same reference on the same line is recorded once.
     *
     * @return list<array{url: string, line: int}>
     */
    private static function scriptRefs(string $contents): array
    {
        $refs = [];
        $at = 0;
        $length = strlen($contents);
        while ($at < $length && preg_match('/<!--|<(script|link|template|textarea|title|xmp|noscript)(?=[\s\/>])/i', $contents, $match, PREG_OFFSET_CAPTURE, $at) === 1) {
            $start = $match[0][1];
            if ($match[0][0] === '<!--') {
                $at = self::commentEnd($contents, $start);
                continue;
            }
            if (preg_match('/\G<[a-z]+((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/i', $contents, $tag, 0, $start) !== 1) {
                $at = $start + 1;
                continue;
            }
            $at = $start + strlen($tag[0]);
            $name = strtolower($match[1][0]);
            if (in_array($name, self::UNREAD_TEXT_ELEMENTS, true)) {
                $close = stripos($contents, '</' . $name, $at);
                $at = $close === false ? $length : $close;
                continue;
            }
            $attributes = self::attributes($tag[1], $start + strlen($match[0][0]));
            if ($name === 'link') {
                $rel = ' ' . strtolower($attributes['rel'][0] ?? '') . ' ';
                if (isset($attributes['href']) && preg_match('/\smodulepreload\s/', $rel) === 1) {
                    self::record($refs, $contents, $attributes['href'][0], $attributes['href'][1]);
                }
                continue;
            }
            $close = stripos($contents, '</script', $at);
            $textEnd = $close === false ? $length : $close;
            if (isset($attributes['src'])) {
                // A browser never runs the text of a script that has a `src`.
                self::record($refs, $contents, $attributes['src'][0], $attributes['src'][1]);
            } elseif (in_array(strtolower(trim($attributes['type'][0] ?? '')), self::SCRIPT_TYPES, true)) {
                foreach (InlineScriptImports::specifiers(substr($contents, $at, $textEnd - $at)) as $import) {
                    self::record($refs, $contents, $import['specifier'], $at + $import['offset']);
                }
            }
            $at = $textEnd;
        }

        return array_values($refs);
    }

    /**
     * The offset just past the comment opening at `$start`. `<!-->` and
     * `<!--->` end at once, as a browser reads them; an unclosed comment
     * runs to the end of the page.
     */
    private static function commentEnd(string $contents, int $start): int
    {
        foreach (['>', '->'] as $abrupt) {
            if (substr_compare($contents, $abrupt, $start + 4, strlen($abrupt)) === 0) {
                return $start + 4 + strlen($abrupt);
            }
        }
        $end = strpos($contents, '-->', $start + 4);

        return $end === false ? strlen($contents) : $end + 3;
    }

    /**
     * A tag's attributes by lower-cased name, each with its value and the
     * value's offset in the page. The first of a repeated name wins, as in a
     * browser. A name only counts whole, so `data-src` is not `src`.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    private static function attributes(string $text, int $offset): array
    {
        $attributes = [];
        preg_match_all('/([^\s"\'>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
        foreach ($matches as $match) {
            $name = strtolower((string) $match[1][0]);
            if (isset($attributes[$name])) {
                continue;
            }
            foreach ([2, 3, 4] as $group) {
                if ($match[$group][0] !== null) {
                    $attributes[$name] = [$match[$group][0], $offset + $match[$group][1]];
                    continue 2;
                }
            }
            $attributes[$name] = ['', $offset + $match[1][1]];
        }

        return $attributes;
    }

    /**
     * Add one reference at a page offset, trimmed, unless it is empty or
     * already recorded on the same line.
     *
     * @param array<string, array{url: string, line: int}> $refs
     */
    private static function record(array &$refs, string $contents, string $url, int $offset): void
    {
        $url = trim($url);
        if ($url === '') {
            return;
        }
        $line = 1 + substr_count($contents, "\n", 0, $offset);
        $refs[$line . "\n" . $url] ??= ['url' => $url, 'line' => $line];
    }
}

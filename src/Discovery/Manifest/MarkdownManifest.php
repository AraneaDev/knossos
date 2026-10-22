<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

/**
 * Reads a markdown page for the modules its `<script>` blocks import.
 *
 * VitePress and VuePress compile each page into a Vue component, so a
 * `<script setup>` in a page is that component's script and the only thing
 * that reaches a component it imports and renders. Nothing else in the
 * project names the component, so its in-degree is zero however live it is.
 */
final class MarkdownManifest implements ManifestReader
{
    /**
     * The specifiers each page script imports, with the line each is on.
     *
     * Only recorded here, never resolved: whether a page is compiled at all
     * depends on a site config elsewhere in the tree, which a reader of one
     * file cannot see. {@see \Knossos\Discovery\EntryPointResolver} resolves
     * them for pages inside a VitePress or VuePress site.
     */
    public function read(string $relative, string $absolute, string $contents): array
    {
        return [
            'script_imports' => self::scriptImports($contents),
        ];
    }

    /**
     * Every import of every page script, in document order.
     *
     * A script counts only where markdown reads it as HTML: at the start of a
     * line, indented by at most three spaces, outside a fenced code block and
     * an HTML comment. Four spaces make an indented code block, and a tag
     * further along a line sits in prose or an inline code span; both are
     * text a page shows. A script that never closes runs to the end of the
     * page.
     *
     * @return list<array{specifier: string, line: int}>
     */
    private static function scriptImports(string $contents): array
    {
        $imports = [];
        $at = 0;
        $length = strlen($contents);
        $fence = null;
        while ($at < $length) {
            $end = strpos($contents, "\n", $at);
            $lineEnd = $end === false ? $length : $end;
            $line = substr($contents, $at, $lineEnd - $at);
            $next = $end === false ? $length : $end + 1;
            if ($fence !== null) {
                if (preg_match('/^ {0,3}(' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',})\s*$/', $line) === 1) {
                    $fence = null;
                }
                $at = $next;
                continue;
            }
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $open) === 1) {
                $fence = $open[1];
                $at = $next;
                continue;
            }
            if (preg_match('/^ {0,3}<!--/', $line, $comment) === 1) {
                $close = strpos($contents, '-->', $at + strlen($comment[0]));
                $at = $close === false ? $length : $close + 3;
                continue;
            }
            if (preg_match('/^ {0,3}<script(?=[\s>])(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i', $line, $tag) === 1) {
                $textStart = $at + strlen($tag[0]);
                $close = stripos($contents, '</script', $textStart);
                $textEnd = $close === false ? $length : $close;
                foreach (InlineScriptImports::specifiers(substr($contents, $textStart, $textEnd - $textStart)) as $import) {
                    $imports[] = [
                        'specifier' => trim($import['specifier']),
                        'line' => 1 + substr_count($contents, "\n", 0, $textStart + $import['offset']),
                    ];
                }
                $after = $close === false ? false : strpos($contents, "\n", $close);
                $at = $after === false ? $length : $after + 1;
                continue;
            }
            $at = $next;
        }

        return $imports;
    }
}

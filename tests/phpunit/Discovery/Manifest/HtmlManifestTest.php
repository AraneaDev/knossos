<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery\Manifest;

use Knossos\Discovery\Manifest\HtmlManifest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The script references an HTML page records, each with its line, before
 * anything resolves them against the scanned files.
 */
#[Group('discovery')]
final class HtmlManifestTest extends TestCase
{
    /**
     * The reader's `script_refs` for a page.
     *
     * @return list<array{url: string, line: int}>
     */
    private static function refs(string $contents): array
    {
        $metadata = (new HtmlManifest())->read('index.html', '/project/index.html', $contents);
        self::assertArrayNotHasKey('entry_points', $metadata);

        return $metadata['script_refs'];
    }

    /** Double, single and unquoted values, in any case, are all a `src`. */
    public function testReadsScriptSrcInEveryQuotingAndCase(): void
    {
        assertSame([
            ['url' => 'a.js', 'line' => 1],
            ['url' => 'b.mjs', 'line' => 2],
            ['url' => 'c.mjs', 'line' => 3],
        ], self::refs("<script src=\"a.js\"></script>\n<SCRIPT SRC='b.mjs'></SCRIPT>\n<script type=module src=c.mjs></script>\n"));
    }

    /** A modulepreload link names a module the page loads; a stylesheet does not. */
    public function testReadsModulePreloadLinks(): void
    {
        assertSame([['url' => '/x.mjs', 'line' => 1]], self::refs('<link rel="modulepreload" href="/x.mjs">'));
        assertSame([], self::refs('<link rel="stylesheet" href="s.css">'));
    }

    /** Static imports, re-exports and a dynamic import with a template prefix, each on its own line. */
    public function testReadsInlineModuleImports(): void
    {
        $page = str_repeat("\n", 9) . implode("\n", [
            '<script type="module">',
            'import a from "./a.mjs";',
            'import "./b.mjs";',
            "export { c } from './c.mjs';",
            'const { start } = await import(`/static/app.mjs${location.search}`);',
            '</script>',
            '',
        ]);

        assertSame([
            ['url' => './a.mjs', 'line' => 11],
            ['url' => './b.mjs', 'line' => 12],
            ['url' => './c.mjs', 'line' => 13],
            ['url' => '/static/app.mjs', 'line' => 14],
        ], self::refs($page));
    }

    /** A classic inline script can load a module with a dynamic import too. */
    public function testReadsInlineClassicScriptDynamicImport(): void
    {
        assertSame([['url' => './d.js', 'line' => 1]], self::refs('<script>import("./d.js")</script>'));
    }

    /** A script tag inside an HTML comment loads nothing. */
    public function testIgnoresScriptsInsideHtmlComments(): void
    {
        assertSame([['url' => 'live.js', 'line' => 3]], self::refs(implode("\n", [
            '<!-- <script src="old.js"></script>',
            '<script>import("./gone.js")</script> -->',
            '<script src="live.js"></script>',
        ])));
    }

    /** `data-src` and `srcset` end in `src` without being it. */
    public function testReadsOnlyTheSrcAttribute(): void
    {
        assertSame([], self::refs('<script data-src="x.js"></script><script srcset="y.js"></script>'));
        assertSame([['url' => 'z.js', 'line' => 1]], self::refs('<script data-src="x.js" src="z.js"></script>'));
    }

    /** An import written inside a string or a comment of an inline script is text. */
    public function testIgnoresImportsInsideInlineStringsAndComments(): void
    {
        assertSame([], self::refs(implode("\n", [
            '<script type="module">',
            "const text = \"import('./no.js')\";",
            "// import x from './no2.js'",
            "/* import('./no3.js') */",
            '</script>',
        ])));
    }

    /** A CRLF line ending is one line, not two. */
    public function testCountsLinesInCrlfPages(): void
    {
        assertSame([
            ['url' => 'a.js', 'line' => 2],
            ['url' => './b.mjs', 'line' => 4],
        ], self::refs("<!doctype html>\r\n<script src=\"a.js\"></script>\r\n<script type=\"module\">\r\nimport \"./b.mjs\";\r\n</script>\r\n"));
    }

    /** A template that starts with an expression, or an identifier, names no file the page can be read for. */
    public function testATemplateWithoutAStaticPrefixIsNotAReference(): void
    {
        assertSame([], self::refs('<script type="module">import(`${base}/x.js`); import(name);</script>'));
    }

    /** The same reference twice on one line is recorded once; on another line it is recorded again. */
    public function testDropsDuplicatesOnTheSameLine(): void
    {
        assertSame([
            ['url' => 'a.js', 'line' => 1],
            ['url' => 'a.js', 'line' => 2],
        ], self::refs("<script src=\"a.js\"></script><script src=\"a.js\"></script>\n<script src=\" a.js \"></script>"));
    }

    /** An unterminated inline script is read to the end of the page rather than dropped. */
    public function testReadsAnUnterminatedInlineScript(): void
    {
        assertSame([['url' => './e.mjs', 'line' => 2]], self::refs("<script type=\"module\">\nimport \"./e.mjs\";"));
    }

    /** A JSON or importmap block is data, and an external script's own text is never run. */
    public function testReadsNoImportsFromDataBlocksOrExternalScripts(): void
    {
        assertSame([], self::refs('<script type="importmap">{"imports": {"x": "./x.js"}}</script>'));
        assertSame([], self::refs('<script type="application/json">import("./y.js")</script>'));
        assertSame([['url' => 'z.js', 'line' => 1]], self::refs('<script src="z.js">import("./w.js")</script>'));
    }

    /** A custom element whose name starts with `script` or `link` is neither. */
    public function testATagNameMustEndAtItsName(): void
    {
        assertSame([], self::refs('<script-loader src="a.js"></script-loader><link-list rel="modulepreload" href="b.mjs">'));
        assertSame([['url' => 'c.js', 'line' => 1]], self::refs('<script/src="c.js"></script>'));
    }

    /**
     * The text of a template, a textarea, a title, an xmp or a noscript is
     * never parsed as tags by a browser running scripts, so a script tag
     * written there loads nothing.
     *
     * @return iterable<string, array{string}>
     */
    public static function rawTextElements(): iterable
    {
        foreach (['template', 'textarea', 'title', 'xmp', 'noscript', 'style', 'iframe', 'noembed', 'noframes'] as $element) {
            yield $element => [$element];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rawTextElements')]
    public function testSkipsScriptsInsideElementsWhoseTextIsNotLoaded(string $element): void
    {
        assertSame([['url' => 'after.js', 'line' => 2]], self::refs(
            "<{$element} class=\"x\"><script src=\"inside.js\"></script></" . strtoupper($element) . ">\n<script src=\"after.js\"></script>",
        ));
        // Unterminated: the rest of the page is that element's text.
        assertSame([], self::refs("<{$element}><script src=\"inside.js\"></script>"));
    }

    /** `<!-->` and `<!--->` are whole comments, as a browser reads them. */
    public function testAnAbruptCommentEndsAtOnce(): void
    {
        assertSame([
            ['url' => 'a.js', 'line' => 1],
            ['url' => 'b.js', 'line' => 2],
            ['url' => 'c.js', 'line' => 3],
        ], self::refs("<!--><script src=\"a.js\"></script>\n<!---><script src=\"b.js\"></script>\n<!-- --><script src=\"c.js\"></script>"));
    }

    /** A tag with an unclosed quote is passed over; a repeated or valueless attribute does not confuse `src`. */
    public function testReadsMalformedAndBareAttributesLeniently(): void
    {
        assertSame([['url' => 'b.js', 'line' => 2]], self::refs("<script src=\"a.js></script>\n<script src=\"b.js\"></script>"));
        assertSame([['url' => 'first.js', 'line' => 1]], self::refs('<script async defer src="first.js" src="second.js"></script>'));
        assertSame([], self::refs('<script src=""></script><script src="  "></script>'));
    }

    /** A template inside a template ends at its own close, so the outer one's text runs on to the outer close. */
    public function testSkipsNestedTemplatesToTheirMatchingClose(): void
    {
        assertSame([['url' => 'after.js', 'line' => 1]], self::refs(
            '<template><template></template><script src="between.js"></script></template><script src="after.js"></script>',
        ));
        assertSame([], self::refs('<template><TEMPLATE id="x"></template><script src="inside.js"></script>'));
    }

    /** `<plaintext>` makes the rest of the page text: nothing after it is read. */
    public function testPlaintextEndsReadingForTheRestOfThePage(): void
    {
        assertSame([['url' => 'before.js', 'line' => 1]], self::refs(
            "<script src=\"before.js\"></script><plaintext>\n<script src=\"after.js\"></script></plaintext><script src=\"later.js\"></script>",
        ));
    }
}

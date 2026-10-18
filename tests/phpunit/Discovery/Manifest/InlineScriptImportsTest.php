<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery\Manifest;

use Knossos\Discovery\Manifest\InlineScriptImports;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** The module specifiers a piece of inline JavaScript imports, read without a parser. */
#[Group('discovery')]
final class InlineScriptImportsTest extends TestCase
{
    /**
     * The specifiers only, for assertions that do not care where they are.
     *
     * @return list<string>
     */
    private static function specifiers(string $script): array
    {
        return array_column(InlineScriptImports::specifiers($script), 'specifier');
    }

    /** Every static and dynamic form, with the offset of each specifier's opening quote. */
    public function testReadsEveryImportFormWithItsOffset(): void
    {
        $script = "import a from \"./a.mjs\";\nimport './b.mjs';\nexport { c } from './c.mjs';\nexport * from \"./d.mjs\";\nimport(\"./e.js\");\n";

        assertSame([
            ['specifier' => './a.mjs', 'offset' => strpos($script, '"./a.mjs"')],
            ['specifier' => './b.mjs', 'offset' => strpos($script, "'./b.mjs'")],
            ['specifier' => './c.mjs', 'offset' => strpos($script, "'./c.mjs'")],
            ['specifier' => './d.mjs', 'offset' => strpos($script, '"./d.mjs"')],
            ['specifier' => './e.js', 'offset' => strpos($script, '"./e.js"')],
        ], InlineScriptImports::specifiers($script));
    }

    /** A named import list spanning lines, and a default plus a namespace import, still end at `from`. */
    public function testReadsImportClausesOfEveryShape(): void
    {
        assertSame(
            ['./f.mjs', './g.mjs', './h.mjs'],
            self::specifiers("import {\n  x,\n  y as z,\n} from './f.mjs';\nimport d, * as ns from './g.mjs';\nimport * as all from\"./h.mjs\""),
        );
    }

    /** A template literal's prefix is cut at its first `${`, and kept only when something is left. */
    public function testReadsATemplateLiteralPrefix(): void
    {
        assertSame(['/static/app.mjs'], self::specifiers('await import(`/static/app.mjs${location.search}`)'));
        assertSame(['./plain.js'], self::specifiers('import(`./plain.js`)'));
        assertSame([], self::specifiers('import(`${base}/x.js`)'));
    }

    /** A dynamic import of anything but a literal names no file. */
    public function testIgnoresANonLiteralDynamicImport(): void
    {
        assertSame([], self::specifiers('import(name); import(base + "/x.js")'));
    }

    /** Text in strings, templates, and both kinds of comment is skipped. */
    public function testSkipsStringsTemplatesAndComments(): void
    {
        assertSame([], self::specifiers(implode("\n", [
            "const a = \"import('./no.js')\";",
            "const b = 'import \"./no.js\"';",
            'const c = `import("./no.js") ${"x"}`;',
            "// import x from './no2.js'",
            "/* import('./no3.js') */",
            "const d = 'it\\'s import(\"./no4.js\")';",
        ])));
    }

    /** `import` as part of a longer name, or as a property, is not the keyword. */
    public function testReadsOnlyTheKeyword(): void
    {
        assertSame([], self::specifiers('reimport("./x.js"); obj.import("./y.js"); importer("./z.js")'));
        assertSame([], self::specifiers('const importFrom = "./x.js"; exports.from = "./y.js"'));
    }

    /** An `export` without `from` declares something rather than loading a module. */
    public function testAnExportWithoutFromLoadsNothing(): void
    {
        assertSame([], self::specifiers('export const x = "./a.js"; export function f() { return "./b.js"; }'));
    }

    /** Unterminated text ends the read without failing. */
    public function testToleratesUnterminatedText(): void
    {
        assertSame(['./ok.js'], self::specifiers("import './ok.js'; const s = \"open"));
        assertSame([], self::specifiers('import("./open'));
        assertSame([], self::specifiers('/* never closed import("./x.js")'));
        assertSame([], self::specifiers('import'));
    }
}

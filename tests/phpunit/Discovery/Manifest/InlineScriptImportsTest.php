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

    /** A regular expression literal is skipped like a string, so a quote or `import(` in it is not code. */
    public function testSkipsRegularExpressionLiterals(): void
    {
        assertSame(['./y.js'], self::specifiers("const r = /[\"'\\/]import(\"\\.\\/x.js\")/g; import \"./y.js\";"));
        // A slash after a name or a closing bracket divides; the import after it still counts.
        assertSame(['./z.js'], self::specifiers('const half = total / 2; const q = (a) / (b); import "./z.js";'));
        // An unterminated literal ends at its line, or at the end of the script.
        assertSame(['./n.js'], self::specifiers("const r = /never closed\nimport \"./n.js\";"));
        assertSame([], self::specifiers('const r = /never closed'));
        // After `return` an operand follows, so `/` opens a literal there.
        assertSame(['./r.js'], self::specifiers("function f() { return /'/; }\nimport \"./r.js\";"));
    }

    /** An escaped backtick does not end a template, and an unterminated template ends the read. */
    public function testReadsEscapesInTemplates(): void
    {
        assertSame(['./t.js'], self::specifiers('const t = `a\\`import("./no.js")`; import "./t.js";'));
        assertSame([], self::specifiers('const t = `never closed import("./no.js")'));
        // An import inside a substitution is code, and is read.
        assertSame(['./in.js'], self::specifiers('const t = `${await import("./in.js")}`;'));
    }

    /** Comments between the keyword and the specifier, and strings inside a named list, are passed over. */
    public function testReadsAroundCommentsAndQuotedNames(): void
    {
        assertSame(['./c.js', './d.js', './q.js'], self::specifiers(implode("\n", [
            'import /* c */ "./c.js";',
            'import // d',
            '  "./d.js";',
            'import { "a-b" as ab, "}" as brace } from "./q.js";',
        ])));
        assertSame([], self::specifiers('import /* never closed "./x.js"'));
        assertSame([], self::specifiers('import // to the end'));
        assertSame([], self::specifiers('import { a, b'));
        assertSame([], self::specifiers('import { a } from'));
        // Anything else in a clause ends it: this reads as no import at all.
        assertSame([], self::specifiers('import x = require("./cjs.js");'));
    }
}

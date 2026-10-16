<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The dependency count the max_dependency_fanout budget gates on. */
final class DependencyFanoutTest extends KnossosTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once self::repositoryRoot() . '/tools/lib/dependency-fanout.php';
    }

    /** A JavaScript import spread over several lines is one dependency, as is a side-effect import. */
    #[Group('quality-tools')]
    public function testJavaScriptImportsCountWhateverTheirShape(): void
    {
        $source = "import {\n    a,\n    b,\n} from \"./x.js\";\nimport c from './y.js';\nimport \"./side-effect.js\";\nconst d = 'import e from \"./not.js\"';\n";

        self::assertSame(3, \dependencyFanout('js', $source));
    }

    /** A re-export reads another module as surely as an import does. */
    #[Group('quality-tools')]
    public function testJavaScriptReExportsCountAsDependencies(): void
    {
        $source = "export { a, b } from \"./x.js\";\nexport * from './y.js';\nexport const c = 1;\n";

        self::assertSame(2, \dependencyFanout('js', $source));
    }

    #[Group('quality-tools')]
    public function testPhpAndPythonCountTheirImportLines(): void
    {
        self::assertSame(2, \dependencyFanout('php', "<?php\nuse A\\B;\nuse C\\D;\n\$x = 1;\n"));
        self::assertSame(2, \dependencyFanout('py', "import os\nfrom typing import Any\nx = 1\n"));
        self::assertSame(0, \dependencyFanout('rs', "use std::io;\n"));
    }

    /**
     * An import that only appears in a comment or inside a template literal is
     * no dependency, and an apostrophe in a comment between the bindings does
     * not hide the one that is.
     */
    #[Group('quality-tools')]
    public function testImportsInCommentsAndTemplateLiteralsDoNotCount(): void
    {
        $source = "/*\nimport a from \"./x.js\";\n*/\nconst t = `\nimport b from \"./y.js\";\n`;\nimport {\n    c, // don't drop this\n    d,\n} from \"./z.js\";\n// import e from \"./w.js\";\n";

        self::assertSame(1, \dependencyFanout('js', $source));
    }
}

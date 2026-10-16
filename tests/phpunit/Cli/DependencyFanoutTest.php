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

    #[Group('quality-tools')]
    public function testPhpAndPythonCountTheirImportLines(): void
    {
        self::assertSame(2, \dependencyFanout('php', "<?php\nuse A\\B;\nuse C\\D;\n\$x = 1;\n"));
        self::assertSame(2, \dependencyFanout('py', "import os\nfrom typing import Any\nx = 1\n"));
        self::assertSame(0, \dependencyFanout('rs', "use std::io;\n"));
    }
}

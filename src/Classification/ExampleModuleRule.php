<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;

/**
 * Tags example code: what a repository keeps to show how it is used, which
 * nothing in it imports by design.
 *
 * A file under `examples/`, `example/`, `samples/` or `demo/` outside a
 * package's source roots, or under an `examples/` directory below `docs/`, is
 * an example. Under a source root (`src/demo/`) the same name is product
 * code. Every declaration in such a file has the role, as a test file's do.
 */
final readonly class ExampleModuleRule implements ClassificationRule
{
    public const ROLE = 'quality.example_module';

    /** Directory names a repository keeps its examples in. */
    private const EXAMPLE_SEGMENTS = ['examples', 'example', 'samples', 'demo'];

    /** {@inheritDoc} */
    public function id(): string
    {
        return 'core.example.modules.v1';
    }

    /** {@inheritDoc} */
    public function classify(NodeFact $node): array
    {
        $path = $node->evidence->relativePath;
        if (!self::isExamplePath($path)) {
            return [];
        }

        return [
            new ClassificationFact(
                $node->localId,
                self::ROLE,
                $this->id(),
                Origin::Derived,
                Confidence::Probable,
                $node->evidence,
                ['matched_path' => $path],
            ),
        ];
    }

    /** Whether a project-relative file path sits in example code. */
    public static function isExamplePath(string $path): bool
    {
        $segments = explode('/', strtolower($path));
        array_pop($segments);
        $underSourceRoot = false;
        $underDocs = false;
        foreach ($segments as $segment) {
            if (in_array($segment, TestModuleRule::SOURCE_ROOTS, true)) {
                $underSourceRoot = true;
            }
            if ($segment === 'docs') {
                $underDocs = true;
            }
            if ($segment === 'examples' && $underDocs) {
                return true;
            }
            if (in_array($segment, self::EXAMPLE_SEGMENTS, true) && !$underSourceRoot) {
                return true;
            }
        }

        return false;
    }
}

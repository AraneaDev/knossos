<?php

declare(strict_types=1);

namespace Knossos\Query\CreationBrief;

use Knossos\Scan\Conventions\PathParts;
use Knossos\Store\FolderFactsStore;
use PDO;

/**
 * Whether a path is a test or a source file, for a path that may not exist yet.
 *
 * A file the graph holds is a test when it holds a node classified as a test
 * module. A path that is not there is judged by what the folders show: first by
 * its own folder, then by the shape of the test names the project's source
 * folders have.
 */
final readonly class PathRole
{
    private const TEST_MODULE = 'quality.test_module';

    public function __construct(private PDO $pdo, private FolderFactsStore $facts) {}

    /** `test` or `source` for the project-relative path. */
    public function of(string $projectId, string $path): string
    {
        $recorded = $this->recorded($projectId, $path);
        if ($recorded !== null) {
            return $recorded;
        }
        $directory = PathParts::directory($path);
        $extension = PathParts::extension($path);
        if ($this->facts->of($projectId, $directory, $extension, 'test') !== null && $this->facts->of($projectId, $directory, $extension, 'source') === null) {
            return 'test';
        }
        foreach ($this->facts->testPathPatterns($projectId, $extension) as $pattern) {
            if (preg_match(self::regex($pattern), $path) === 1) {
                return 'test';
            }
        }

        return 'source';
    }

    /** The role the graph recorded for a scanned file, or null when the file is not in it. */
    private function recorded(string $projectId, string $path): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT EXISTS (SELECT 1 FROM classifications c JOIN nodes n ON n.id = c.node_id WHERE n.file_id = f.id AND c.role = ?) FROM files f WHERE f.project_id = ? AND f.relative_path = ?',
        );
        $statement->execute([self::TEST_MODULE, $projectId, $path]);
        $test = $statement->fetchColumn();

        return $test === false ? null : ((int) $test === 1 ? 'test' : 'source');
    }

    /** The regular expression of the paths a test-path pattern could produce, `<name>` any file name part and `<dir>` any directory. */
    private static function regex(string $pattern): string
    {
        $parts = preg_split('/(<dir>\/|<name>)/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex = '';
        foreach ($parts as $part) {
            $regex .= match ($part) {
                '<dir>/' => '(?:.*/)?',
                '<name>' => '[^/]+',
                default => preg_quote($part, '#'),
            };
        }

        return '#^' . $regex . '$#';
    }
}

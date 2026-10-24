<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\FileContextService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/** One file's context for the model's tool: boundary, dependents, tests and latest commits, each cut short. */
final class FileContextServiceTest extends KnossosTestCase
{
    private const FIXTURE = 'turn-brief';

    #[Group('query')]
    public function testAFileIsAnsweredWithItsBoundaryDependentsTestsAndCommits(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            // Without git the commits are simply absent.
            $plain = (new FileContextService($pdo))->context($root . '/src/Core/Greeter.php');
            assertSame([], $plain['file']['commits']);
            $git = ['git', '-C', $root, '-c', 'user.name=Knossos Test', '-c', 'user.email=test@example.test', '-c', 'commit.gpgsign=false'];
            $this->runFixtureCommand(['git', 'init', '--quiet', $root]);
            $this->runFixtureCommand([...$git, 'add', '.']);
            $this->runFixtureCommand([...$git, 'commit', '--quiet', '-m', 'first']);
            $this->runFixtureCommand([...$git, 'commit', '--quiet', '--allow-empty', '-m', 'unrelated']);
            $context = (new FileContextService($pdo))->context($root . '/src/Core/Greeter.php');
            assertSame('ok', $context['status']);
            $file = $context['file'];
            assertSame(['src/Core/Greeter.php', 'php', 'Core', 2], [$file['path'], $file['language'], $file['boundary'], $file['components']]);
            assertSame(2, $file['dependents']['count']);
            assertSame(['src/Edge/Caller.php', 'tests/GreeterTest.php'], $file['dependents']['top']);
            assertSame(['tests/GreeterTest.php'], array_column($file['tests']['items'], 'path'));
            // The class and its method; only the commits that touched the file, newest first.
            assertSame(['first'], array_column($file['commits'], 'subject'));
            assertSame('not-found', (new FileContextService($pdo))->context($root . '/src/Nope.php')['status']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * A script only a workflow runs has no dependent file, and the answer
     * says what enters it instead. "0 dependent files" alone read as dead
     * code for a release script CI runs on every push.
     */
    #[Group('query')]
    public function testAFileAManifestRunsSaysWhatEntersIt(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-entered-' . bin2hex(random_bytes(6));
        foreach ([
            '.forgejo/workflows/release.yml' => "jobs:\n  release:\n    steps:\n      - run: php tools/release.php\n",
            'tools/release.php' => "<?php\nfunction release(): void {}\nrelease();\n",
            'src/Plain.php' => "<?php\nfinal class Plain {}\n",
        ] as $path => $contents) {
            @mkdir(dirname($root . '/' . $path), 0o777, true);
            file_put_contents($root . '/' . $path, $contents);
        }
        try {
            $pdo = $this->freshTestDatabase();
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

            $entered = (new FileContextService($pdo))->context($root . '/tools/release.php')['file'];
            assertSame(0, $entered['dependents']['count']);
            assertSame(['.forgejo/workflows/release.yml'], $entered['entered_by']);
            assertSame([], (new FileContextService($pdo))->context($root . '/src/Plain.php')['file']['entered_by']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A file is named by its own path: a name that reads as a glob does not take in the commits of the files it would match. */
    #[Group('query')]
    public function testAFileWhoseNameReadsAsAGlobHasOnlyItsOwnCommits(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-glob-path-' . bin2hex(random_bytes(6));
        mkdir($root);
        try {
            $git = ['git', '-C', $root, '-c', 'user.name=Knossos Test', '-c', 'user.email=test@example.test', '-c', 'commit.gpgsign=false'];
            $this->runFixtureCommand(['git', 'init', '--quiet', $root]);
            foreach (['a.php' => 'plain', '[ab].php' => 'bracketed'] as $file => $subject) {
                file_put_contents($root . '/' . $file, "<?php\n");
                $this->runFixtureCommand([...$git, 'add', '--', $file]);
                $this->runFixtureCommand([...$git, 'commit', '--quiet', '-m', $subject]);
            }

            assertSame(['bracketed'], array_column((new FileContextService($this->freshTestDatabase()))->commits($root, '[ab].php'), 'subject'));
        } finally {
            $this->removeTempTree($root);
        }
    }
}

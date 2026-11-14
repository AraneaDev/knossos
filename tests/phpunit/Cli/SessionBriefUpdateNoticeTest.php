<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\Command\SessionCommand;
use Knossos\Install\UpdateCheck;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Runtime\Version;
use Knossos\Tests\Phpunit\Install\InstallFixtures;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The one line the session brief adds when the cache names a newer release. */
final class SessionBriefUpdateNoticeTest extends KnossosTestCase
{
    use InstallFixtures;

    #[Group('cli')]
    public function testAnInstalledReleaseWithANewerCachedOneSaysSo(): void
    {
        $data = $this->installed('d', Version::CURRENT);
        (new UpdateCheck($data))->record('999.0.0');

        $brief = $this->brief($data, $data . '/versions/' . Version::CURRENT);

        assertSame(true, str_ends_with($brief, 'knossos 999.0.0 is available (you have ' . Version::CURRENT . '): run knossos self-update'));
    }

    #[Group('cli')]
    public function testNoLineWithoutACacheWithAnOlderOneOrInACheckout(): void
    {
        $data = $this->installed('d', Version::CURRENT);
        $installed = $data . '/versions/' . Version::CURRENT;

        assertSame(false, str_contains($this->brief($data, $installed), 'self-update'));
        (new UpdateCheck($data))->record(Version::CURRENT);
        assertSame(false, str_contains($this->brief($data, $installed), 'self-update'));
        (new UpdateCheck($data))->record('999.0.0');
        assertSame(false, str_contains($this->brief($data, self::repositoryRoot()), 'self-update'));
    }

    /**
     * The brief for an empty project, with the code taken to live at `$root`.
     *
     * The graph is kept in a directory of its own, away from the install
     * directory `$data`: the notice is found through where the code lives,
     * never through KNOSSOS_DATA_DIR.
     */
    private function brief(string $data, string $root): string
    {
        $previous = getenv('KNOSSOS_DATA_DIR');
        @mkdir($this->work . '/graph', 0o755, true);
        putenv('KNOSSOS_DATA_DIR=' . $this->work . '/graph');
        self::assertNotSame($data, $this->work . '/graph');
        @mkdir($this->work . '/project', 0o755, true);
        $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory($root), null);
        $errors = fopen('php://memory', 'w+');
        self::assertIsResource($errors);
        ob_start();
        try {
            (new SessionCommand($errors))->run('session-brief', [$this->work . '/project'], ['json' => ['true']], $context);
        } finally {
            $output = (string) ob_get_clean();
            putenv($previous === false ? 'KNOSSOS_DATA_DIR' : 'KNOSSOS_DATA_DIR=' . $previous);
        }

        return (string) json_decode($output, true)['brief'];
    }
}

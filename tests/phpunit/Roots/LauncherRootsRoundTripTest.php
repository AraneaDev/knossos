<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Roots;

use Knossos\Roots\AllowedRoots;
use Knossos\Roots\RootsFile;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The container launcher writes the allow-list in shell, and the server reads it in PHP.
 *
 * A container install grants roots on the host: tools/launcher/knossos writes
 * `<install dir>/roots.json` itself and chooses each project root from it with
 * its own reader, while the server in every container reads the same file
 * through {@see RootsFile} and {@see AllowedRoots}. A file the two read
 * differently either mounts a root the server then refuses, or, written as
 * invalid JSON, makes the server allow nothing in every project. Here the
 * launcher's `allow-root --execute` grants awkward folder names, against a
 * fake docker, and PHP must read back exactly the list the launcher's own
 * reader prints, in the same order. No container runtime and no network.
 */
final class LauncherRootsRoundTripTest extends KnossosTestCase
{
    private string $work = '';

    protected function setUp(): void
    {
        $work = sys_get_temp_dir() . '/knossos-launcher-roots-' . bin2hex(random_bytes(6));
        mkdir($work . '/home', 0o700, true);
        mkdir($work . '/data/install/plugin', 0o700, true);
        mkdir($work . '/data/install/containers', 0o700, true);
        mkdir($work . '/bin', 0o700, true);
        mkdir($work . '/docker', 0o700, true);
        $this->work = (string) realpath($work);
        file_put_contents($this->work . '/data/install/install.json', json_encode([
            'mode' => 'container',
            'layout' => 'separate',
            'version' => '1.0.0',
            'image' => 'knossos:test',
            'runtime' => 'docker',
            'bin_dir' => $this->work . '/bin',
        ]));
        copy(self::repositoryRoot() . '/tests/shell/fixtures/fake-docker', $this->work . '/bin/docker');
        chmod($this->work . '/bin/docker', 0o755);
    }

    protected function tearDown(): void
    {
        if ($this->work !== '') {
            self::removeTree($this->work);
        }
    }

    #[Group('roots')]
    public function testThePhpReaderReadsTheListTheLauncherWroteAsTheLauncherReadsIt(): void
    {
        $projects = $this->work . '/projects';
        $names = [
            'with space',
            'quote"inside',
            'back\\slash',
            'dollar$HOME',
            '-leading-dash',
            'caf' . "\u{e9}" . ' ' . "\u{65e5}\u{672c}",
            'outer',
            'outer/inner',
            'trailing space ',
        ];
        foreach ($names as $name) {
            mkdir($projects . '/' . $name, 0o700, true);
        }
        mkdir($projects . '/trailing space', 0o700, true);
        $granted = [];
        foreach ($names as $name) {
            $granted[] = $projects . '/' . $name;
        }
        // A duplicate, spelled with a trailing slash: one root to both sides.
        $granted[] = $projects . '/with space/';
        foreach ($granted as $folder) {
            [$status, $output] = $this->launcher(['allow-root', $folder, '--execute', '--json']);
            assertSame(0, $status, $folder . ': ' . $output);
        }

        $file = $this->work . '/data/install/roots.json';
        $expected = array_map(static fn(string $name): string => AllowedRoots::normaliseRoot($projects . '/' . $name), $names);
        assertSame($expected, (new RootsFile($file))->read());
        assertSame($expected, $this->launcherReader($file));
        assertSame($expected, (new AllowedRoots([], $file))->current());
    }

    #[Group('roots')]
    public function testANameTheListCannotHoldIsRefusedAndTheListIsLeftAsItWas(): void
    {
        $projects = $this->work . '/projects';
        mkdir($projects . '/kept', 0o700, true);
        [$status] = $this->launcher(['allow-root', $projects . '/kept', '--execute']);
        assertSame(0, $status);
        $file = $this->work . '/data/install/roots.json';
        $before = (string) file_get_contents($file);
        foreach (["tab\there", "new\nline", 'not-utf8-' . "\xff", "bell\x07"] as $name) {
            mkdir($projects . '/' . $name, 0o700, true);
            [$status, $output] = $this->launcher(['allow-root', $projects . '/' . $name, '--execute']);
            assertSame(2, $status, $output);
            assertSame(true, str_contains($output, 'cannot be recorded in the allow-list; rename the folder'), $output);
            assertSame($before, (string) file_get_contents($file));
        }
        assertSame([$projects . '/kept'], (new RootsFile($file))->read());
        assertSame([$projects . '/kept'], $this->launcherReader($file));
        assertSame([$projects . '/kept'], (new AllowedRoots([], $file))->current());
    }

    /**
     * Run the launcher in container mode for this install, with the fake docker first on PATH.
     *
     * @param list<string> $arguments
     * @return array{int, string} the exit status and stdout with stderr
     */
    private function launcher(array $arguments): array
    {
        return $this->runCommand(
            ['sh', self::repositoryRoot() . '/tools/launcher/knossos', ...$arguments],
            [
                'KNOSSOS_INSTALL_DIR' => $this->work . '/data/install',
                'HOME' => $this->work . '/home',
                'FAKE_DOCKER_STATE' => $this->work . '/docker',
                'FAKE_ANY_IMAGE' => '1',
            ],
        );
    }

    /**
     * The entries the launcher's own reader, its roots() function, prints for a file.
     *
     * @return list<string>
     */
    private function launcherReader(string $file): array
    {
        $launcher = (string) file_get_contents(self::repositoryRoot() . '/tools/launcher/knossos');
        assertSame(1, preg_match("/^BYTES_AWK='.*?^'\n/ms", $launcher, $library), 'BYTES_AWK not found in the launcher');
        assertSame(1, preg_match("/^roots\\(\\) \\{\n.*?^\\}\n/ms", $launcher, $reader), 'roots() not found in the launcher');
        [$status, $output] = $this->runCommand(['sh', '-c', "set -f\n" . $library[0] . $reader[0] . 'roots'], ['KNOSSOS_ROOTS_FILE' => $file]);
        assertSame(0, $status, $output);

        return $output === '' ? [] : explode("\n", rtrim($output, "\n"));
    }

    /**
     * Run a command with a small environment of its own, from the work directory.
     *
     * @param list<string> $command
     * @param array<string, string> $environment
     * @return array{int, string}
     */
    private function runCommand(array $command, array $environment): array
    {
        $environment += ['PATH' => $this->work . '/bin:' . (string) getenv('PATH')];
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->work, $environment);
        assertSame(true, is_resource($process));
        // Both are a few lines at most, so reading one after the other cannot block.
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    /** Remove a directory tree this test made. */
    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}

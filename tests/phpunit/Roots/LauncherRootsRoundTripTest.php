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
 * `<install dir>/roots.json` itself (the install directory lies outside the
 * data directory) and chooses each project root from it with
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
        $work = self::scratchPath('knossos-launcher-roots');
        mkdir($work . '/home', 0o700, true);
        mkdir($work . '/data', 0o700, true);
        mkdir($work . '/share/knossos/plugin', 0o700, true);
        mkdir($work . '/share/knossos/containers', 0o700, true);
        mkdir($work . '/bin', 0o700, true);
        mkdir($work . '/docker', 0o700, true);
        $this->work = (string) realpath($work);
        $install = $this->work . '/share/knossos';
        file_put_contents($install . '/install.json', json_encode([
            'mode' => 'container',
            'layout' => 'install-dir',
            'version' => '1.0.0',
            'image' => 'knossos:test',
            'runtime' => 'docker',
            'bin_dir' => $this->work . '/bin',
            'data_dir' => $this->work . '/data',
        ], JSON_UNESCAPED_SLASHES));
        copy(self::repositoryRoot() . '/tests/shell/fixtures/fake-docker', $this->work . '/bin/docker');
        chmod($this->work . '/bin/docker', 0o755);
        // The launcher as the installer writes it: the install directory and its inode baked in.
        $stat = stat($install);
        assertSame(true, is_array($stat));
        $template = (string) file_get_contents(self::repositoryRoot() . '/tools/launcher/knossos');
        file_put_contents($this->work . '/bin/knossos', str_replace(
            ["INSTALL_DIR='__KNOSSOS_INSTALL_DIR__'", "INSTALL_PIN='__KNOSSOS_INSTALL_PIN__'"],
            ["INSTALL_DIR='" . $install . "'", "INSTALL_PIN='" . $stat['dev'] . ':' . $stat['ino'] . "'"],
            $template,
        ));
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

        $file = $this->work . '/share/knossos/roots.json';
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
        $file = $this->work . '/share/knossos/roots.json';
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

    #[Group('roots')]
    public function testAHandEditedListGrantsOnlyItsRootsOnBothSides(): void
    {
        $projects = $this->work . '/projects';
        foreach (['one', 'two', 'wide', 'nested', 'listed elsewhere'] as $name) {
            mkdir($projects . '/' . $name, 0o700, true);
        }
        $file = $this->work . '/share/knossos/roots.json';
        // Keys a person might add by hand, holding strings that look like roots.
        file_put_contents($file, json_encode([
            '_note' => $projects . '/wide',
            'roots' => [$projects . '/one', 7, null, ['nested' => $projects . '/nested']],
            'meta' => ['where' => $projects . '/nested', 'also' => [$projects . '/listed elsewhere']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        assertSame([$projects . '/one'], (new RootsFile($file))->read());
        assertSame([$projects . '/one'], $this->launcherReader($file));

        [$status, $output] = $this->launcher(['allow-root', $projects . '/two', '--execute']);
        assertSame(0, $status, $output);
        $expected = [$projects . '/one', $projects . '/two'];
        assertSame($expected, (new RootsFile($file))->read());
        assertSame($expected, $this->launcherReader($file));
        assertSame($expected, (new AllowedRoots([], $file))->current());

        // Without a "roots" key the server reads the object's own string values, and so does the launcher.
        file_put_contents($file, json_encode(['a' => $projects . '/one', 'b' => ['x' => $projects . '/nested']], JSON_UNESCAPED_SLASHES));
        assertSame([$projects . '/one'], (new RootsFile($file))->read());
        assertSame([$projects . '/one'], $this->launcherReader($file));
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
            ['sh', $this->work . '/bin/knossos', ...$arguments],
            [
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

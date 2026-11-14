<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Install;

use Knossos\Install\ProcessReleaseProbe;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The real probe, against stand-in release binaries. */
final class ProcessReleaseProbeTest extends KnossosTestCase
{
    use InstallFixtures;

    private function release(string $script): string
    {
        $dir = $this->work . '/rel-' . bin2hex(random_bytes(3));
        mkdir($dir . '/bin', 0o755, true);
        file_put_contents($dir . '/bin/knossos', "#!/bin/sh\n" . $script . "\n");
        chmod($dir . '/bin/knossos', 0o755);

        return $dir;
    }

    #[Group('install')]
    public function testTheVersionAnsweredIsReturned(): void
    {
        $dir = $this->release('echo "Knossos 3.1.4"');

        assertSame('3.1.4', (new ProcessReleaseProbe())->version($dir, $this->work . '/scratch'));
        assertSame(null, (new ProcessReleaseProbe())->version($this->release('echo hello'), $this->work . '/scratch'));
        assertSame(null, (new ProcessReleaseProbe())->version($this->release('echo "Knossos 1.0.0"; exit 1'), $this->work . '/scratch'));
    }

    #[Group('install')]
    public function testDoctorRunsAgainstTheScratchDataDirectory(): void
    {
        $dir = $this->release('[ "$1" = doctor ] && [ "$KNOSSOS_DATA_DIR" = "' . $this->work . '/scratch" ] && [ -z "${KNOSSOS_ROOTS_FILE:-}" ] && exit 0; echo "wrong: $1 $KNOSSOS_DATA_DIR"; exit 1');
        $previous = getenv('KNOSSOS_ROOTS_FILE');
        putenv('KNOSSOS_ROOTS_FILE=/should/not/leak');
        try {
            assertSame(null, (new ProcessReleaseProbe())->doctor($dir, $this->work . '/scratch'));
        } finally {
            putenv($previous === false ? 'KNOSSOS_ROOTS_FILE' : 'KNOSSOS_ROOTS_FILE=' . $previous);
        }
    }

    #[Group('install')]
    public function testThePluginIsWrittenByTheReleaseItselfAndRegistersNothing(): void
    {
        $dir = $this->release('printf "%s\\n" "$@" > "$KNOSSOS_DATA_DIR/args"; [ "$1" = install-agent-plugin ] || exit 1');

        assertSame(null, (new ProcessReleaseProbe())->plugin($dir, '/i/plugin', '/b/knossos', '/i', $this->work . '/scratch'));
        assertSame(
            "install-agent-plugin\n--execute\n--no-register\n--plugin-dir=/i/plugin\n--bin=/b/knossos\n--data-dir=/i\n",
            file_get_contents($this->work . '/scratch/args'),
        );
        assertSame('nope', (new ProcessReleaseProbe())->plugin($this->release('echo nope; exit 2'), '/i/plugin', '/b/knossos', '/i', $this->work . '/scratch'));
        // The child's own error code is dropped: the caller reports the failure under its own.
        assertSame('Unable to create /i/plugin.', (new ProcessReleaseProbe())->plugin($this->release('echo "KNOSSOS_INVALID_ARGUMENT: Unable to create /i/plugin." >&2; exit 2'), '/i/plugin', '/b/knossos', '/i', $this->work . '/scratch'));
        assertSame('install-agent-plugin did not finish', (new ProcessReleaseProbe())->plugin($this->release('exit 2'), '/i/plugin', '/b/knossos', '/i', $this->work . '/scratch'));
    }

    #[Group('install')]
    public function testAFailingDoctorReturnsWhatItPrinted(): void
    {
        $dir = $this->release('echo "[ERROR] node.version: too old"; exit 1');
        assertSame('[ERROR] node.version: too old', (new ProcessReleaseProbe())->doctor($dir, $this->work . '/scratch'));
        assertSame('doctor did not finish', (new ProcessReleaseProbe())->doctor($this->release('exit 3'), $this->work . '/scratch'));
    }

    #[Group('install')]
    public function testAMissingBinaryOrScratchDirectoryIsAFailureNotAThrow(): void
    {
        mkdir($this->work . '/nobin');
        assertSame(true, (new ProcessReleaseProbe())->doctor($this->work . '/nobin', $this->work . '/scratch') !== null);
        file_put_contents($this->work . '/file', 'x');
        assertSame('could not create ' . $this->work . '/file/s', (new ProcessReleaseProbe())->doctor($this->release('exit 0'), $this->work . '/file/s'));
    }
}

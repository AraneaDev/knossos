<?php

declare(strict_types=1);

namespace Knossos\Install;

/**
 * Runs a release's own `bin/knossos` as a child process.
 *
 * The child sees this process's environment except for the data directory,
 * which is the scratch one: doctor opens and migrates the database it finds,
 * and a release that is not installed yet must not migrate the real one. The
 * plugin a release writes is told the real data directory by option.
 */
final class ProcessReleaseProbe implements ReleaseProbe
{
    /** {@inheritDoc} */
    public function doctor(string $releaseDir, string $scratchDir): ?string
    {
        [$status, $output] = $this->run($releaseDir, $scratchDir, ['doctor'], 180);

        return $status === 0 ? null : ($output === '' ? 'doctor did not finish' : $output);
    }

    /** {@inheritDoc} */
    public function version(string $releaseDir, string $scratchDir): ?string
    {
        [$status, $output] = $this->run($releaseDir, $scratchDir, ['version'], 30);

        return $status === 0 && str_starts_with($output, 'Knossos ') ? trim(substr($output, strlen('Knossos '))) : null;
    }

    /** {@inheritDoc} */
    public function plugin(string $releaseDir, string $pluginDir, string $launcher, string $dataDir, string $scratchDir): ?string
    {
        [$status, $output] = $this->run($releaseDir, $scratchDir, [
            'install-agent-plugin',
            '--execute',
            '--no-register',
            '--plugin-dir=' . $pluginDir,
            '--bin=' . $launcher,
            '--data-dir=' . $dataDir,
        ], 60);

        return $status === 0 ? null : ($output === '' ? 'install-agent-plugin did not finish' : $output);
    }

    /**
     * Run one command of the release under a deadline, output kept to a bounded file.
     *
     * @param non-empty-list<string> $arguments the command and its options
     * @return array{int, string} exit status and the first 4000 bytes of output
     */
    private function run(string $releaseDir, string $scratchDir, array $arguments, int $seconds): array
    {
        if (!is_dir($scratchDir) && !@mkdir($scratchDir, 0o700, true) && !is_dir($scratchDir)) {
            return [-1, 'could not create ' . $scratchDir];
        }
        $log = $scratchDir . '/probe.' . $arguments[0] . '.out';
        $environment = getenv();
        unset($environment['KNOSSOS_ROOTS_FILE']);
        $environment['KNOSSOS_DATA_DIR'] = $scratchDir;
        $process = @proc_open(
            [$releaseDir . '/bin/knossos', ...$arguments],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
            $pipes,
            $scratchDir,
            $environment,
        );
        if (!is_resource($process)) {
            return [-1, 'could not start ' . $releaseDir . '/bin/knossos'];
        }
        $deadline = time() + $seconds;
        $status = proc_get_status($process);
        while ($status['running'] && time() < $deadline) {
            usleep(50_000);
            $status = proc_get_status($process);
        }
        if ($status['running']) {
            proc_terminate($process, 9);
        }
        $closed = proc_close($process);
        $exit = $status['running'] ? -1 : ($status['exitcode'] >= 0 ? $status['exitcode'] : $closed);
        $output = (string) @file_get_contents($log, false, null, 0, 4000);

        return [$exit, trim($output)];
    }
}

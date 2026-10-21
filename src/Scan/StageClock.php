<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Reconciliation\Milliseconds;

/**
 * The milliseconds each stage of one scan took, keyed by stage name in the
 * order the stages ran.
 */
final class StageClock
{
    /** @var array<string, float> */
    private array $milliseconds = [];

    /**
     * Runs `$stage` and records how long it took, once it returns.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function time(string $stage, callable $work): mixed
    {
        $started = hrtime(true);
        $result = $work();
        $this->milliseconds[$stage] = Milliseconds::since($started);

        return $result;
    }

    /** Records `$milliseconds` for `$stage`, measured elsewhere. */
    public function record(string $stage, float $milliseconds): void
    {
        $this->milliseconds[$stage] = $milliseconds;
    }

    /**
     * Records stages measured elsewhere, each name prefixed with `$prefix`,
     * never replacing a stage already recorded.
     *
     * @param array<string, float> $milliseconds
     */
    public function recordAll(array $milliseconds, string $prefix = ''): void
    {
        foreach ($milliseconds as $stage => $elapsed) {
            $this->milliseconds[$prefix . $stage] ??= $elapsed;
        }
    }

    /**
     * Every stage recorded so far.
     *
     * @return array<string, float>
     */
    public function stages(): array
    {
        return $this->milliseconds;
    }
}

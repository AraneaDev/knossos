<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\Drift;

use Knossos\Git\GitProcessRunnerInterface;

/** A Git runner that records every call it is asked to make and answers each with an empty listing. */
final class CapturingGitRunner implements GitProcessRunnerInterface
{
    /** @var list<list<string>> */
    public array $commands = [];
    /** @var list<int> */
    public array $timeouts = [];
    /** @var list<string> */
    public array $operations = [];

    public function run(array $command, int $timeoutMs, string $operation): string
    {
        $this->commands[] = $command;
        $this->timeouts[] = $timeoutMs;
        $this->operations[] = $operation;

        return '';
    }
}

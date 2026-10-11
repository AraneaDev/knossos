<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Git;

use Knossos\Git\GitProcessRunnerInterface;

/** A Git runner that records every call it is asked to make and answers through `$respond`, or with nothing. */
final class RecordingGitRunner implements GitProcessRunnerInterface
{
    /** @var list<list<string>> */
    public array $commands = [];
    /** @var list<int> */
    public array $timeouts = [];
    /** @var list<string> */
    public array $operations = [];

    /** @var ?callable(list<string>): string */
    private $respond;

    /** @param ?callable(list<string>): string $respond */
    public function __construct(?callable $respond = null)
    {
        $this->respond = $respond;
    }

    public function run(array $command, int $timeoutMs, string $operation): string
    {
        $this->commands[] = $command;
        $this->timeouts[] = $timeoutMs;
        $this->operations[] = $operation;

        return $this->respond === null ? '' : ($this->respond)($command);
    }

    /** @return list<list<string>> the recorded commands whose subcommand is `$subcommand` */
    public function commandsWith(string $subcommand): array
    {
        return array_values(array_filter($this->commands, static fn(array $command): bool => in_array($subcommand, $command, true)));
    }
}

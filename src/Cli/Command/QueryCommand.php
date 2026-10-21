<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\ProjectReference;
use Knossos\Git\ProcessGitHistoryProvider;
use Knossos\Git\ProcessGitWorkingTreeProvider;
use Knossos\Mcp\ToolArgumentPreflight;
use Knossos\Mcp\ToolArgumentSpec;
use Knossos\Mcp\ToolBindings;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Result\ResultEnvelope;

/**
 * Every read-only query as a CLI command.
 *
 * One command class for all of them because they share option parsing, envelope
 * rendering, and the `--json` contract; splitting them would duplicate that three
 * dozen times.
 *
 * Each command is the MCP tool of the same name in snake case, and is built
 * from that tool's row in {@see ToolBindings}: its positionals and options
 * become the tool's arguments, which are then read, preflighted and passed
 * to the service exactly as an MCP call's are. So the CLI refuses what the
 * tool refuses (a bound, a length, a list too long, an enum) with the same
 * message, except that an integer option keeps its own `--name must be
 * between` wording.
 */
final class QueryCommand implements CliCommand
{
    private const COMMANDS = [
        'list-projects', 'list-snapshots', 'snapshot-diff', 'quality-gate', 'architecture-trends',
        'find-component', 'inspect-component', 'list-usages', 'architecture-summary', 'file-metrics', 'explain-flow', 'impact-analysis',
        'dependency-cycles', 'architecture-health', 'check-architecture', 'suggest-location', 'change-impact',
        'changed-files-impact', 'test-impact', 'review-diff', 'architecture-context', 'export-diagram', 'export-agent-brief', 'list-boundaries',
        'search-architecture', 'annotate-component', 'list-annotations',
    ];

    /** {@inheritDoc} */
    public function supports(string $command): bool
    {
        return in_array($command, self::COMMANDS, true);
    }

    /**
     * The options only the CLI has, beside the tool's own: `--out` writes the
     * brief to a file, with no tool argument behind it.
     */
    private const CLI_OPTIONS = ['export-agent-brief' => ['out']];

    /** {@inheritDoc} */
    public function allowedOptions(string $command): array
    {
        return ['db', 'json', ...array_keys(self::optionArguments($command))];
    }

    /**
     * The tool argument each of `$command`'s own options sets, null for a
     * CLI-only one; the tool is the command's name in snake case. Read from
     * the tool's binding, so an argument added to a tool is an option here
     * at once, and QueryCommandContractTest holds the two to the schema.
     *
     * @return array<string, string|null>
     */
    public static function optionArguments(string $command): array
    {
        // Unreachable through the router, which asks only for supported
        // commands: a new command without an entry fails its first test
        // instead of borrowing another command's list.
        if (!in_array($command, self::COMMANDS, true)) {
            throw new \LogicException(sprintf('No option allowlist for %s.', $command));
        }

        return [...ToolBindings::options(self::tool($command)), ...array_fill_keys(self::CLI_OPTIONS[$command] ?? [], null)];
    }

    /** {@inheritDoc} */
    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        $tool = self::tool($command);
        // Every command but list-projects names its project first: read the graph that holds it.
        if (isset(ToolBindings::arguments($tool)['project_id'], $positionals[0])) {
            $context = $context->forTarget($positionals[0], $options);
        }
        $arguments = $this->arguments($tool, $positionals, $options, $context);
        $values = ToolBindings::read($tool, $arguments);
        ToolArgumentPreflight::check($tool, $arguments);
        $result = ToolBindings::call($tool, $this->queries($command, $context), $values);

        return $this->render($command, $result, $options, $context);
    }

    /**
     * The MCP-shaped arguments the command line names: the positionals
     * first, in order (a project resolved to its id, as given), then each
     * option the command was given, parsed as its argument is declared. An
     * absent option is left out, so it takes the tool's default.
     *
     * @param list<string> $positionals @param array<string, list<string>> $options
     * @return array<string, mixed>
     */
    private function arguments(string $tool, array $positionals, array $options, CliCommandContext $context): array
    {
        $arguments = [];
        foreach (ToolBindings::positionals($tool) as $at => ['argument' => $argument, 'missing' => $missing, 'rest' => $rest]) {
            if ($rest) {
                $arguments[$argument] = array_slice($positionals, $at);
            } elseif (isset($positionals[$at])) {
                $arguments[$argument] = $argument === 'project_id'
                    ? (new ProjectReference($context->database(), $context->databasePath()))->resolve($positionals[$at])['id']
                    : $positionals[$at];
            } elseif ($missing !== null) {
                throw new InvalidArgumentException($missing);
            }
        }
        $spec = ToolArgumentSpec::for($tool);
        foreach (ToolBindings::options($tool) as $option => $argument) {
            $value = $this->option($spec, $argument, $option, $options, $context);
            if ($value !== null) {
                $arguments[$argument] = $value;
            }
        }

        return $arguments;
    }

    /**
     * One option as the value its argument's schema declares, null when it
     * was not given: an integer within the advertised bounds, a flag, the
     * values of a repeatable option for a list of strings, the contents of a
     * file for policies (a list of objects) or budgets (an object).
     *
     * @param array<string, list<string>> $options
     */
    private function option(ToolArgumentSpec $spec, string $argument, string $option, array $options, CliCommandContext $context): mixed
    {
        $type = $spec->type($argument);
        if ($type === 'object' || $type === 'array' && $spec->itemType($argument) !== 'string') {
            $file = $context->options->single($options, $option);
            if ($file === null && $spec->isRequired($argument)) {
                throw new InvalidArgumentException(sprintf('--%s=FILE is required.', $option));
            }

            return $file === null ? null : ($type === 'object' ? $context->input->jsonObject($file) : $context->input->policies($file));
        }
        if (!isset($options[$option])) {
            return null;
        }

        return match ($type) {
            'integer' => $context->options->integer($options, $option, ...array_values($spec->bounds($argument))),
            'boolean' => $context->options->flag($options, $option, $spec->booleanDefault($argument)),
            'array' => $context->options->values($options, $option),
            default => $context->options->single($options, $option),
        };
    }

    /**
     * Print the result and pick the exit code. Most commands print the
     * summary; a few print the artefact they produce, and the two gates
     * exit non-zero when they fail.
     *
     * @param array<string, list<string>> $options
     */
    private function render(string $command, ResultEnvelope $result, array $options, CliCommandContext $context): int
    {
        $json = $context->options->flag($options, 'json');
        $text = match ($command) {
            'list-projects' => self::projectLines($result),
            'architecture-trends' => $result->data['release_notes']['markdown'] ?? $result->summary,
            'export-diagram' => $result->data['diagram'],
            'export-agent-brief' => self::writeBrief($result, $context->options->single($options, 'out')),
            default => $result->summary,
        };
        $context->output($result->jsonSerialize(), $json, $text);

        return match ($command) {
            'quality-gate' => $result->data['passed'] ? CliCommand::EXIT_OK : CliCommand::EXIT_GATE_FAILED,
            // Exit non-zero when declared-policy violations exist so the "check"
            // command can gate CI on its own result, mirroring quality-gate. The
            // authoritative count is the (possibly larger) bounds.violation_count.
            'check-architecture' => ($result->data['bounds']['violation_count'] ?? count($result->data['violations'])) > 0 ? CliCommand::EXIT_GATE_FAILED : CliCommand::EXIT_OK,
            default => CliCommand::EXIT_OK,
        };
    }

    /** list-projects' text: the summary, then one line per project. */
    private static function projectLines(ResultEnvelope $result): string
    {
        $text = $result->summary;
        foreach ($result->data['projects'] as $project) {
            $text .= sprintf(
                "\n%s  %s  %s  files=%d nodes=%d edges=%d%s",
                $project['id'],
                $project['name'],
                $project['freshness'],
                $project['counts']['files'],
                $project['counts']['nodes'],
                $project['counts']['edges'],
                isset($project['root']) ? '  root=' . $project['root'] : '',
            );
        }

        return $text;
    }

    /** The brief's markdown, written to `$out` first when one was named. */
    private static function writeBrief(ResultEnvelope $result, ?string $out): string
    {
        if ($out !== null && file_put_contents($out, $result->data['markdown']) === false) {
            throw new InvalidArgumentException(sprintf('Unable to write brief to %s.', $out));
        }

        return $result->data['markdown'];
    }

    /**
     * The query facade for this invocation, given the git readers the change
     * commands need: history for change-impact, the working tree for the
     * commands that read changed files.
     */
    private function queries(string $command, CliCommandContext $context): ArchitectureQueryService
    {
        return ArchitectureQueryService::forDatabase(
            $context->database(),
            gitHistory: $command === 'change-impact' ? new ProcessGitHistoryProvider() : null,
            gitWorkingTree: in_array($command, ['changed-files-impact', 'test-impact', 'review-diff'], true) ? new ProcessGitWorkingTreeProvider() : null,
        );
    }

    /** The tool `$command` mirrors: the command's name in snake case. */
    private static function tool(string $command): string
    {
        return str_replace('-', '_', $command);
    }
}

<?php

declare(strict_types=1);

namespace Knossos\Mcp;

/**
 * The shared read options a tool call carries beside its own arguments: how
 * much evidence to return (`verbosity`) and the result's size budget
 * (`max_chars`), both applied by {@see ResultEnricher} after the tool answers.
 *
 * Parsed apart from the tool's own arguments so a handler never sees them,
 * and only for a tool whose schema declares them: passing one to a tool that
 * does not (e.g. verbosity to maintain_database) is left among the arguments,
 * where the key validation rejects it as unknown.
 */
final readonly class ResponseOptions
{
    /**
     * The tools whose `max_chars` is their own argument, a budget the answer
     * is built to rather than one the enricher trims a finished answer to.
     */
    private const OWN_BUDGET = ['architecture_context', 'export_agent_brief'];

    /**
     * @param string $verbosity `compact` or `full`
     * @param int|null $maxChars the budget the enricher trims to; null for a tool that budgets itself or takes none
     */
    public function __construct(
        public string $verbosity,
        public ?int $maxChars,
    ) {}

    /**
     * The options `$tool` was called with, and its arguments without them.
     *
     * @param list<string> $declared the properties the tool's schema declares
     * @param array<string, mixed> $arguments
     * @return array{0: self, 1: array<string, mixed>}
     */
    public static function take(string $tool, array $declared, array $arguments): array
    {
        $verbosity = 'compact';
        if (in_array('verbosity', $declared, true) && array_key_exists('verbosity', $arguments)) {
            // Through the same normalisation every other literal string
            // argument gets: whitespace is invisible in a JSON payload, and
            // this value is then matched literally against the allowed set.
            $verbosity = ToolArguments::normalized($arguments['verbosity']);
            unset($arguments['verbosity']);
            if ($verbosity !== 'compact' && $verbosity !== 'full') {
                throw new ToolInputException('verbosity must be "compact" or "full".');
            }
        }
        $maxChars = null;
        if (in_array('max_chars', $declared, true) && !in_array($tool, self::OWN_BUDGET, true)) {
            // Budgeted even when the caller says nothing. An unbounded result is
            // bounded by the graph rather than by anything the host can take:
            // changed_files_impact over a ten-file diff serialized to ~70,000
            // characters and was rejected outright by the client, so the caller
            // got nothing at all instead of a trimmed answer that says what it
            // dropped. Raise it explicitly to trade context window for detail.
            // The default is sized to stay well inside a host's per-result cap
            // while leaving room for a substantial answer.
            ['default' => $maxChars, 'minimum' => $minimum, 'maximum' => $maximum] = ToolArgumentSpec::for($tool)->bounds('max_chars');
            if (array_key_exists('max_chars', $arguments)) {
                $maxChars = $arguments['max_chars'];
                unset($arguments['max_chars']);
                if (!is_int($maxChars) || $maxChars < $minimum || $maxChars > $maximum) {
                    throw new ToolInputException(sprintf('max_chars must be an integer between %d and %d.', $minimum, $maximum));
                }
            }
        }

        return [new self($verbosity, $maxChars), $arguments];
    }
}

<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Facts;

/**
 * One operand of a test assertion and where its value came from.
 */
final readonly class Operand
{
    private const SOURCES = ['mock-config', 'mock-call', 'production', 'literal', 'parameter', 'unknown'];

    /**
     * @param ?string $text the operand's own source text, absent when evaluating it again could give another value
     * @param ?string $value for a stubbed operand, the source text of the value the stub was configured to return; for a literal local, the literal it holds
     */
    public function __construct(
        public string $source,
        public ?string $text,
        public ?string $double,
        public bool $unconfiguredSpy,
        public ?string $value = null,
    ) {}

    /**
     * Read one operand from a decoded attribute block; null when it is malformed.
     */
    public static function fromArray(mixed $raw): ?self
    {
        if (!is_array($raw)) {
            return null;
        }
        $source = $raw['source'] ?? null;
        $text = $raw['text'] ?? null;
        $double = $raw['double'] ?? null;
        $unconfigured = $raw['unconfigured_spy'] ?? false;
        $value = $raw['value'] ?? null;
        if (!is_string($source) || !in_array($source, self::SOURCES, true)) {
            return null;
        }
        if (($text !== null && !is_string($text)) || ($double !== null && !is_string($double)) || !is_bool($unconfigured)) {
            return null;
        }
        if ($value !== null && !is_string($value)) {
            return null;
        }

        return new self($source, $text, $double, $unconfigured, $value);
    }
}

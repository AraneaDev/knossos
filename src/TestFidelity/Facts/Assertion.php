<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Facts;

/**
 * One assertion in a test case: the matcher called and the operands it compares.
 */
final readonly class Assertion
{
    /** @param list<Operand> $operands */
    public function __construct(
        public string $api,
        public int $line,
        public array $operands,
    ) {}

    /**
     * Read one assertion from a decoded attribute block; null when it is malformed.
     */
    public static function fromArray(mixed $raw): ?self
    {
        if (!is_array($raw)) {
            return null;
        }
        $api = $raw['api'] ?? null;
        $line = $raw['line'] ?? null;
        $rawOperands = $raw['operands'] ?? null;
        if (!is_string($api) || $api === '' || !is_int($line) || !is_array($rawOperands) || !array_is_list($rawOperands)) {
            return null;
        }
        $operands = [];
        foreach ($rawOperands as $rawOperand) {
            $operand = Operand::fromArray($rawOperand);
            if ($operand === null) {
                return null;
            }
            $operands[] = $operand;
        }

        return new self($api, $line, $operands);
    }
}

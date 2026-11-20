<?php

declare(strict_types=1);

namespace KnossosPhpScanner\TestFacts;

/**
 * A double as an expression holds it: the double, and whether it is a
 * Prophecy double already revealed, whose calls are calls on the double
 * rather than configuration.
 */
final readonly class HeldDouble
{
    public function __construct(
        public DoubleRecord $double,
        public bool $revealed,
    ) {}
}

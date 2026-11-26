<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Fixtures;

/**
 * One fixture file as core's own JSON or YAML reader parsed it: a tree of
 * {@see Value}s, read as data and never evaluated (no YAML tag is expanded).
 */
final readonly class Document
{
    public function __construct(public Value $root) {}
}

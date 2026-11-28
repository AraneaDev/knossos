<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

/**
 * How sure a test-fidelity finding is.
 *
 * `definite` when the facts alone prove it, every time, so it is fit to fail
 * a build; `warning` when it rests on a heuristic, an inferred type or an
 * untyped side.
 */
enum FindingConfidence: string
{
    case Definite = 'definite';
    case Warning = 'warning';
}

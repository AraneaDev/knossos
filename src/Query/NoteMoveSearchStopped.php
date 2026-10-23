<?php

declare(strict_types=1);

namespace Knossos\Query;

use RuntimeException;

/**
 * Thrown from inside a snapshot read to end it early, when the caller of
 * {@see NoteMoveSuggestions::forMissing()} has no time left; caught there,
 * never seen outside it.
 */
final class NoteMoveSearchStopped extends RuntimeException {}

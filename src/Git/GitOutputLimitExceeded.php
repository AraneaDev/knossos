<?php

declare(strict_types=1);

namespace Knossos\Git;

use RuntimeException;

/**
 * A git answer that outgrew the byte limit its runner reads under.
 *
 * Its own type because it is the one failure a caller can do something about:
 * the same question asked for less (fewer commits, a narrower listing) may
 * fit, whereas a missing binary or a directory that is no repository fails the
 * same way however it is asked.
 */
final class GitOutputLimitExceeded extends RuntimeException {}

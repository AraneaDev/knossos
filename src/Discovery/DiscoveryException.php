<?php

declare(strict_types=1);

namespace Knossos\Discovery;

use RuntimeException;

/**
 * Discovery cannot proceed: a configuration it cannot read, an ignore pattern
 * it refuses, or a tree past its limits.
 *
 * A refused root is not one of these: {@see \Knossos\Roots\RootGuard}
 * raises {@see \Knossos\Roots\RootException} for that.
 */
final class DiscoveryException extends RuntimeException {}

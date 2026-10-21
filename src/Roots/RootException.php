<?php

declare(strict_types=1);

namespace Knossos\Roots;

use RuntimeException;

/**
 * A requested root is refused: missing, or outside the allow-list.
 *
 * Deliberately not final, and the only class in Roots that is not. It is the
 * type every caller of {@see RootGuard} catches, so a narrower refusal has to
 * be reachable through it: {@see RootNotFoundException} says which of the two
 * failures happened without asking a single one of those catch sites to
 * change. Subclasses stay inside this namespace; nothing outside it may widen
 * what `catch (RootException)` means.
 */
class RootException extends RuntimeException {}

<?php

declare(strict_types=1);

namespace Knossos\Install;

use RuntimeException;

/** An install, update or rollback that cannot go on; its message says why and what was left as it was. */
final class InstallException extends RuntimeException {}

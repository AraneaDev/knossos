<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

/**
 * Pins whether the code under test believes it runs in a container.
 *
 * {@see \Knossos\Roots\RootGuard::containerised()} reads `KNOSSOS_CONTAINER`
 * first (`0` means no, any other value yes) and the `/.dockerenv` marker only
 * when it is unset. A suite that runs inside a container would otherwise
 * answer differently from one on a developer host, so a test that depends on
 * the answer pins it here and both branches run everywhere. The variable is a
 * process environment entry, so a subprocess the test starts inherits it.
 *
 * Call {@see self::restoreContainer()} from tearDown().
 */
trait PinsContainer
{
    private string|false|null $containerBefore = null;

    /** Makes the process a container (true) or a plain host (false) until restored. */
    protected function pinContainer(bool $container): void
    {
        $this->containerBefore ??= getenv('KNOSSOS_CONTAINER');
        putenv('KNOSSOS_CONTAINER=' . ($container ? '1' : '0'));
    }

    /** Puts `KNOSSOS_CONTAINER` back to what it was before the first pin, or unsets it. */
    protected function restoreContainer(): void
    {
        if ($this->containerBefore === null) {
            return;
        }
        putenv(is_string($this->containerBefore) ? 'KNOSSOS_CONTAINER=' . $this->containerBefore : 'KNOSSOS_CONTAINER');
        $this->containerBefore = null;
    }
}

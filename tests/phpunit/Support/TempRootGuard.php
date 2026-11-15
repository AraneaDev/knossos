<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Fails the run when its tests left anything in the run's temp root.
 *
 * tests/phpunit/bootstrap.php gives each run a temp root of its own and points
 * TMPDIR at it, so everything a test or a process it starts writes to "the
 * temp directory" lands there. When the last test is over, the root holds the
 * run's HOME and nothing else unless a test failed to clean up after itself.
 * This lists what is left and raises it as a PHPUnit warning, which fails the
 * run (phpunit.xml keeps failOnPhpunitWarning at its default of true).
 *
 * Only the process that made the root checks it. A PHPUnit started by a test
 * inherits the root while its parent's tests are still using it, and a check
 * there would report the parent's files as its own.
 *
 * The root itself is removed by the bootstrap once the run ends, after this
 * check has named what was in it.
 */
final class TempRootGuard implements Extension, ExecutionFinishedSubscriber
{
    /** The longest list printed; the count says how many there were in all. */
    private const int SHOWN = 40;

    /** {@inheritDoc} */
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber($this);
    }

    /** Reports what the run left in its temp root, if anything. */
    public function notify(ExecutionFinished $event): void
    {
        $message = self::leftover(getenv('KNOSSOS_TEST_TEMP_ROOT'), getenv('KNOSSOS_TEST_TEMP_OWNER'), getmypid());
        if ($message !== null) {
            EventFacade::emitter()->testRunnerTriggeredPhpunitWarning($message);
        }
    }

    /**
     * The warning for what is left in $root, or null when nothing is or when
     * this process is not $owner.
     *
     * Two entries are not the tests' to clean up: the run's HOME, and the
     * `phpunit_` file in which PHPUnit caches its source map for the tests it
     * runs in a process of their own, which PHPUnit writes once per run and
     * never removes. Both go with the root.
     */
    public static function leftover(string|false $root, string|false $owner, int|false $pid): ?string
    {
        if (!is_string($root) || $root === '' || !is_dir($root) || $owner !== (string) $pid) {
            return null;
        }
        $left = array_values(array_filter(
            scandir($root) ?: [],
            static fn(string $entry): bool => !in_array($entry, ['.', '..', 'home'], true) && !str_starts_with($entry, 'phpunit_'),
        ));
        if ($left === []) {
            return null;
        }
        sort($left);
        $shown = array_slice($left, 0, self::SHOWN);

        return sprintf(
            "The tests left %d %s in the run's temp directory %s. A test that needs a temporary path asks scratchPath() or scratchDirectory() for one, which removes it after the test; a process a test starts writes under the same root through TMPDIR.\n  %s%s",
            count($left),
            count($left) === 1 ? 'entry' : 'entries',
            $root,
            implode("\n  ", $shown),
            count($left) > count($shown) ? "\n  ..." : '',
        );
    }
}

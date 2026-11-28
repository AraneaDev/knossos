<?php

declare(strict_types=1);

// PHPUnit bootstrap: load the Composer autoloader first, then the global
// assertion helper functions. Assertions.php stays out of composer.json's
// autoload-dev 'files' so the helpers load only for the test suite and never
// for the shipped autoloader.

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/Support/Assertions.php';

// One temp directory per run. TMPDIR points at it before anything asks PHP
// for the temp directory (PHP reads TMPDIR once and keeps the answer), so a
// test's sys_get_temp_dir() and tempnam(), and every process a test starts,
// write under this root and nowhere else in the system temp directory. The
// run removes the root when it ends, which takes with it whatever a test
// left; Support\TempRootGuard names what that was first, and fails the run.
//
// A process PHPUnit starts to run a test in isolation, or a PHPUnit a test
// starts, inherits the variables and works in the same root. Only the process
// that made the root removes it.
$root = getenv('KNOSSOS_TEST_TEMP_ROOT');
if (!is_string($root) || $root === '' || !is_dir($root)) {
    $system = ini_get('sys_temp_dir') ?: (getenv('TMPDIR') ?: '/tmp');
    $root = rtrim($system, '/') . '/knossos-phpunit-' . bin2hex(random_bytes(4));
    if (!mkdir($root, 0o700) || !mkdir($root . '/home', 0o700)) {
        fwrite(STDERR, "Unable to create the test run's temp directory {$root}.\n");
        exit(1);
    }
    putenv('KNOSSOS_TEST_TEMP_ROOT=' . $root);
    putenv('KNOSSOS_TEST_TEMP_OWNER=' . getmypid());
    $owner = getmypid();
    register_shutdown_function(static function () use ($root, $owner): void {
        if (getmypid() === $owner) {
            Knossos\Tests\Phpunit\Support\Scratch::remove($root);
        }
    });
}
putenv('TMPDIR=' . $root);
$_ENV['TMPDIR'] = $_SERVER['TMPDIR'] = $root;
// ParaTest's runner settles a temp directory of its own, for its workers'
// files, before it loads this bootstrap to list the tests. It runs no test,
// and each worker it starts inherits TMPDIR from here, so only a process that
// runs tests is held to the root.
$paratestRunner = class_exists(ParaTest\WrapperRunner\SuiteLoader::class, false);
if (sys_get_temp_dir() !== $root && !$paratestRunner) {
    fwrite(STDERR, 'PHP had settled on ' . sys_get_temp_dir() . " as its temp directory before the bootstrap ran, so the tests would write there instead of {$root}.\n");
    exit(1);
}

// The CLI falls back to ~/.knossos/knossos.sqlite when it exists, which on a
// developer machine is the real graph. Every test, and every process a test
// starts, gets a home of its own instead.
$home = $root . '/home';
putenv('HOME=' . $home);
$_ENV['HOME'] = $_SERVER['HOME'] = $home;

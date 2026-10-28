<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Application;

use Knossos\Application;
use Knossos\Runtime\Version;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('application')]
final class ApplicationTest extends TestCase
{
    // ----- shape -----

    public function testClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(Application::class);

        $this->assertTrue($reflection->isFinal());
    }

    public function testVersionConstantIsExposedAsPublicString(): void
    {
        $reflection = new \ReflectionClass(Application::class);
        $constant = $reflection->getConstant('VERSION');

        $this->assertIsString($constant);
        // version.txt and the x-release-please-version sentinel in
        // src/Runtime/Version.php are bumped together by release-please; pinning a
        // literal here failed every release build, so assert consistency with
        // the managed file instead.
        $this->assertSame(trim((string) file_get_contents(dirname(__DIR__, 3) . '/version.txt')), $constant);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $constant);
    }

    public function testVersionIsTheRuntimeVersion(): void
    {
        // release-please bumps Runtime\Version; the entry point re-exports it.
        $this->assertSame(Version::CURRENT, Application::VERSION);
    }

    public function testRunIsAPublicInstanceMethodReturningInteger(): void
    {
        $reflection = new \ReflectionMethod(Application::class, 'run');

        $this->assertTrue($reflection->isPublic());
        $this->assertFalse($reflection->isStatic());

        [$return] = self::runHelp(['help']);

        $this->assertSame('int', (string) $reflection->getReturnType());
        $this->assertSame(0, $return);
    }

    // ----- meta-command: help -----
    //
    // The actual help-banner rendering is tested separately by the
    // CliHelpRenderer and MetaCommand unit tests. Here we verify only
    // that Application.run() delegates the 5 meta-command entrypoints
    // without throwing and returns the agreed exit code 0.

    public function testRunWithUsageCommandPrintsTheHelpListAndReturnsZero(): void
    {
        // Bare `knossos` opens the pane (CliTest runs it without a terminal); the list is `usage` now.
        [$exit, $help] = self::runHelp(['usage']);
        assertSame(0, $exit);
        self::assertStringContainsString('knossos boundary-couplings [path]', $help);
    }

    public function testRunWithHelpCommandReturnsZero(): void
    {
        [$exit, $help] = self::runHelp(['help']);
        assertSame(0, $exit);
        self::assertStringContainsString('knossos boundary-couplings [path]', $help);
    }

    public function testRunWithDoubleDashHelpReturnsZero(): void
    {
        [$exit, $help] = self::runHelp(['--help']);
        assertSame(0, $exit);
        self::assertStringContainsString('knossos boundary-couplings [path]', $help);
    }

    public function testRunWithShortDashHReturnsZero(): void
    {
        [$exit, $help] = self::runHelp(['-h']);
        assertSame(0, $exit);
        self::assertStringContainsString('knossos boundary-couplings [path]', $help);
    }

    // ----- meta-command: version -----

    public function testRunWithVersionCommandReturnsZero(): void
    {
        $this->expectOutputString('Knossos ' . Application::VERSION . PHP_EOL);
        assertSame(0, (new Application())->run(['version']));
    }

    public function testRunWithDoubleDashVersionReturnsZero(): void
    {
        $this->expectOutputString('Knossos ' . Application::VERSION . PHP_EOL);
        assertSame(0, (new Application())->run(['--version']));
    }

    public function testRunWithVersionAndJsonFlagReturnsZero(): void
    {
        // The --json flag switches CliCommandContext::output to JSON.
        $this->expectOutputString('{"name":"knossos","version":"' . Application::VERSION . '"}' . PHP_EOL);
        assertSame(0, (new Application())->run(['version', '--json']));
    }

    // ----- error paths -----
    //
    // Application takes an optional error stream, which these tests point at an
    // in-memory stream. That makes the rendered diagnostic assertable in-process
    // (it previously could not be — PHP's predefined STDERR constant is not
    // redirectable and PHPUnit's output expectations do not capture it), and it
    // keeps the suite from writing to the real STDERR, which
    // `infection --filter` treats as a signal to SIGTERM the initial test run.

    /**
     * The exit code and the help text of a run, the help written to an
     * in-memory stream so none of it reaches the terminal.
     *
     * @param list<string> $arguments
     * @return array{0: int, 1: string}
     */
    private static function runHelp(array $arguments): array
    {
        $stream = fopen('php://memory', 'w+');
        $exit = (new Application(null, $stream))->run($arguments);
        rewind($stream);
        $written = stream_get_contents($stream);
        fclose($stream);

        return [$exit, $written === false ? '' : $written];
    }

    /** @param list<string> $arguments @return array{0: int, 1: string} */
    private static function runApplication(array $arguments): array
    {
        $stream = fopen('php://memory', 'w+');
        $exit = (new Application($stream))->run($arguments);
        rewind($stream);
        $written = stream_get_contents($stream);
        fclose($stream);

        return [$exit, $written === false ? '' : $written];
    }

    public function testRunWithUnknownCommandReturnsTwo(): void
    {
        // CliCommandRouter::route() throws InvalidArgumentException when
        // no registered command supports() the name; Application catches
        // it and hands it to CliErrorRenderer::render() which returns 2.
        [$exit, $written] = self::runApplication(['this-command-does-not-exist']);

        assertSame(2, $exit);
        assertContains('KNOSSOS_INVALID_ARGUMENT: ', $written);
    }

    public function testRunCatchesInvalidEmptyOptionFromParserAndReturnsTwo(): void
    {
        // '--=value' has an empty option name, so CliOptionParser::parse()
        // raises InvalidArgumentException('Invalid empty option.') — caught by
        // Application, rendered by CliErrorRenderer, returns 2. (A lone '--' is
        // now the end-of-options marker and no longer throws.)
        [$exit, $written] = self::runApplication(['help', '--=value']);

        assertSame(2, $exit);
        assertContains('KNOSSOS_INVALID_ARGUMENT: Invalid empty option.', $written);
    }
}

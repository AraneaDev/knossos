<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use InvalidArgumentException;
use Knossos\Cli\CliCommandRouter;
use Knossos\Cli\CliHelpRenderer;
use Knossos\Cli\CliOptionParser;
use PHPUnit\Framework\Attributes\Group;

/**
 * Direct tests for /root/Knossos-MCP/src/Cli/CliCommandRouter.php —
 * Batch 10c, closes the Cli module after batch 10b's *Command
 * implementations.
 *
 * Strategy mirrors batch 10b: real `CliCommandRouter` class against a
 * `new CliOptionParser` / `new CliHelpRenderer` /
 * `self::repositoryRoot()` fixture. Throw paths for the handlers
 * reachable from the router (so the router's dispatch is exercised
 * even where the inner handler would otherwise need a database
 * query, stdio stream, or filesystem).
 *
 * Conventions match batches 1-10: bare global helpers from
 * `tests/phpunit/Support/Assertions.php`; class-level
 * `#[Group('cli-router')]`. NO `#[CoversClass]`. NO `assertTrue`.
 */
#[Group('cli-router')]
final class CommandRouterTest extends \Knossos\Tests\Phpunit\KnossosTestCase
{
    /** @var resource|null where the router's help text goes, read back by {@see self::helpWritten()} */
    private $help = null;

    private function newRouter(): CliCommandRouter
    {
        $this->help = fopen('php://memory', 'w+') ?: null;

        return new CliCommandRouter(
            self::repositoryRoot(),
            new CliOptionParser(),
            new CliHelpRenderer($this->help),
            '0.0.0-test',
        );
    }

    /** The help text the router has written so far, kept off the terminal. */
    private function helpWritten(): string
    {
        if ($this->help === null) {
            return '';
        }
        rewind($this->help);

        return (string) stream_get_contents($this->help);
    }

    // ===== Constructor + dispatch routes =================================

    public function testRouterRoutesVersionCommandWithoutEagerDbInit(): void
    {
        // The routed command prints to stdout; expecting it keeps the run's output clean.
        $this->expectOutputRegex('/^Knossos 0\.0\.0-test/');
        // M1 / route() meta branch: 'version' is in the meta names list,
        // so databasePath is null + eager database init is skipped.
        // MetaCommand.version returns 0.
        $router = $this->newRouter();
        assertSame(0, $router->route('version', [], []));
    }

    public function testRouterRoutesAllFiveMetaCommandVariants(): void
    {
        // The routed command prints to stdout; expecting it keeps the run's output clean.
        $this->expectOutputRegex('/^Knossos 0\.0\.0-test/');
        // M2 / route() meta branch coverage: every meta command name
        // routes to MetaCommand. 'help' / '--help' / '-h' arm calls
        // help->render() (smoke test, batch 10 fwrite limitation);
        // '--version' arm constructs the version tuple (returned 0).
        $router = $this->newRouter();
        assertSame(0, $router->route('--version', [], []));
        assertSame(0, $router->route('help', [], []));
        assertSame(0, $router->route('--help', [], []));
        assertSame(0, $router->route('-h', [], []));
        assertSame(3, substr_count($this->helpWritten(), 'knossos boundary-couplings [path]'));
    }

    public function testRouterRoutesScanCommandAndPropagatesHandlerThrow(): void
    {
        // M3 / route() forwards known command + supports() match to
        // ScanCommand. ScanCommand.run() throws InvalidArgumentException
        // for empty positional[0] BEFORE reaching ProjectScanService.
        $router = $this->newRouter();
        assertThrows(
            fn() => $router->route('scan', [], []),
            InvalidArgumentException::class,
        );
    }

    public function testRouterRoutesWatchCommandAndPropagatesHandlerThrow(): void
    {
        // M4 / route() -> WatchCommand. WatchCommand.run() throws on
        // empty positional[0] BEFORE WatchService construction.
        $router = $this->newRouter();
        assertThrows(
            fn() => $router->route('watch', [], []),
            InvalidArgumentException::class,
        );
    }

    public function testRouterRoutesBundleCommandExportAndPropagatesHandlerThrow(): void
    {
        // M5 / route() -> BundleCommand -> export(). positional[0] OK
        // but --output=FILE missing -> BundleCommand throws.
        $router = $this->newRouter();
        assertThrows(
            fn() => $router->route('export-bundle', ['proj-1'], []),
            InvalidArgumentException::class,
        );
    }

    public function testRouterRoutesQueryCommandListProjects(): void
    {
        // The routed command prints to stdout; expecting it keeps the run's output clean.
        $this->expectOutputRegex('/^Found 0 persisted projects\./');
        // M6 / route() -> QueryCommand -> listProjects(). No explicit
        // positional required; with empty :memory: db, query returns
        // empty list and command returns 0.
        $router = $this->newRouter();
        assertSame(0, $router->route('list-projects', [], ['db' => [':memory:']]));
    }

    public function testRouterRoutesQueryCommandFindComponentAndPropagatesThrow(): void
    {
        // M7 / route() -> QueryCommand -> findComponent(). positional[0]
        // missing -> throw.
        $router = $this->newRouter();
        assertThrows(
            fn() => $router->route('find-component', [], []),
            InvalidArgumentException::class,
        );
    }

    public function testRouterRoutesMaintenanceCommandAndPropagatesThrow(): void
    {
        // M8 / route() -> MaintenanceCommand -> removeProject().
        // positional[0] missing -> throw.
        $router = $this->newRouter();
        assertThrows(
            fn() => $router->route('remove-project', [], []),
            InvalidArgumentException::class,
        );
    }

    public function testRouterRoutesServeCommandAndPropagatesHandlerThrow(): void
    {
        // M9 / route() -> ServeCommand. After getenv check, the route()
        // reaches ServeCommand.run() which throws 'serve requires at
        // least one --allow-root=PATH...'. withoutAmbientAllowedRoots()
        // removes both root sources for this run: the environment
        // variable inherited from the user's shell, and the roots file
        // beside the default database, which the router resolves from
        // this checkout and which `knossos allow-root` writes.
        $this->withoutAmbientAllowedRoots(function (): void {
            $router = $this->newRouter();
            assertThrows(
                fn() => $router->route('serve', [], []),
                InvalidArgumentException::class,
            );
        });
    }

    // ===== Option allowlist validation ===================================

    public function testRouterRejectsUnknownOptionForKnownCommand(): void
    {
        // A typo'd option must be rejected before dispatch rather than silently
        // ignored (which would apply defaults).
        $router = $this->newRouter();
        assertThrows(
            fn() => $router->route('list-projects', [], ['bogus-option' => ['true']]),
            InvalidArgumentException::class,
        );
    }

    public function testRouterAcceptsKnownOptionForKnownCommand(): void
    {
        // The routed command prints to stdout; expecting it keeps the run's output clean.
        $this->expectOutputRegex('/^Found 0 persisted projects\./');
        // A valid option passes validation and the command runs normally.
        $router = $this->newRouter();
        assertSame(0, $router->route('list-projects', [], ['limit' => ['5'], 'db' => [':memory:']]));
    }

    // ===== Foreach dispatch + throw ======================================

    public function testExistingDirectoryRoutesToTheTerminalPane(): void
    {
        // A directory where a command would be goes to `tui`, whose options are the proof: it refuses the one
        // `web` takes, by name, rather than the directory as an unknown command. (The pane itself needs a terminal.)
        $router = $this->newRouter();
        $error = captureThrows(fn() => $router->route(self::repositoryRoot(), [], ['port' => ['1']]), InvalidArgumentException::class);
        assertSame('Unknown option: --port', $error->getMessage());
    }

    public function testUnknownWordStillFails(): void
    {
        $router = $this->newRouter();
        $error = captureThrows(fn() => $router->route('no-such-directory-or-command', [], []), InvalidArgumentException::class);
        assertSame('Unknown command: no-such-directory-or-command', $error->getMessage());
    }

    public function testUsageIsTheHelpList(): void
    {
        $router = $this->newRouter();
        assertSame(0, $router->route('usage', [], []));
        assertSame(1, substr_count($this->helpWritten(), "  knossos usage\n"));
    }

    public function testRouterThrowsOnUnknownCommand(): void
    {
        // M10 / route() foreach miss: no command in $this->commands
        // supports the requested name -> final InvalidArgumentException
        // throw with 'Unknown command: %s' message.
        $router = $this->newRouter();
        assertThrows(
            fn() => $router->route('completely-unknown-command', [], []),
            InvalidArgumentException::class,
        );
    }

    // ===== Constructor wiring ============================================

    public function testRouterConstructsAllSevenDefaultCommands(): void
    {
        // The routed command prints to stdout; expecting it keeps the run's output clean.
        $this->expectOutputRegex('/Knossos 0\.0\.0-test\nFound 0 persisted projects\./');
        // M11 / route() foreach iteration: at least 7 commands in the
        // dispatch table. We verify by exercising each command name
        // through the router and confirming the dispatch reaches a
        // CliCommand handler (either by returning 0 or by
        // InvalidArgumentException from the handler). NOTE: this test
        // is structurally redundant with M3-M9 by intent — the
        // reviewer round 1 flagged the duplication but per the
        // batches 5-9 'document-not-block' pattern, retaining the
        // comprehensive audit traversal provides a single-failure
        // signal if any of the 7 dispatches breaks (vs needing to
        // trace which of the 6 individual tests is failing).
        $router = $this->newRouter();
        assertSame(0, $router->route('version', [], []));
        $this->withoutAmbientAllowedRoots(function () use ($router): void {
            assertThrows(fn() => $router->route('scan', [], []), InvalidArgumentException::class);
            assertThrows(fn() => $router->route('watch', [], []), InvalidArgumentException::class);
            assertThrows(fn() => $router->route('export-bundle', ['proj-1'], []), InvalidArgumentException::class);
            assertSame(0, $router->route('list-projects', [], ['db' => [':memory:']]));
            assertThrows(fn() => $router->route('remove-project', [], []), InvalidArgumentException::class);
            assertThrows(fn() => $router->route('serve', [], []), InvalidArgumentException::class);
        });
    }
}

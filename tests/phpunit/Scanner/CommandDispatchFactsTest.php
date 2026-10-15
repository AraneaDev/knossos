<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A console command is run by its name, never by its class: a command that
 * calls another, or code that starts `php artisan <name>` or
 * `bin/console <name>` as a process, named nothing the graph could follow,
 * so the command read as unused by anything but its tests.
 */
final class CommandDispatchFactsTest extends KnossosTestCase
{
    #[Group('scanner')]
    public function testLaravelCommandsCalledByNameOrStartedAsAProcessHaveCallers(): void
    {
        $root = $this->project([
            'composer.json' => '{"require":{"laravel/framework":"^12.0"},"autoload":{"psr-4":{"App\\\\":"app/"}}}',
            'app/Console/Commands/PollOrders.php' => <<<'PHP'
                <?php
                namespace App\Console\Commands;
                use Illuminate\Console\Command;
                final class PollOrders extends Command
                {
                    protected $signature = 'orders:poll {--dry-run}';
                    public function handle(): int { return 0; }
                }
                PHP,
            'app/Console/Commands/Nightly.php' => <<<'PHP'
                <?php
                namespace App\Console\Commands;
                use Illuminate\Console\Command;
                final class Nightly extends Command
                {
                    protected $signature = 'nightly';
                    public function handle(): int { return $this->call('orders:poll', ['--dry-run' => true]); }
                }
                PHP,
            'app/Services/Scheduler.php' => <<<'PHP'
                <?php
                namespace App\Services;
                final class Scheduler
                {
                    public function start(): void { proc_open([PHP_BINARY, 'artisan', 'orders:poll'], [], $pipes); }
                }
                PHP,
            'artisan' => "#!/usr/bin/env php\n<?php\nrequire __DIR__ . '/vendor/autoload.php';\n",
        ]);
        try {
            $callers = $this->callers($root, 'App\\Console\\Commands\\PollOrders::handle');

            self::assertContains('App\\Console\\Commands\\Nightly::handle', $callers, '$this->call() in a command runs the command it names.');
            self::assertContains('App\\Services\\Scheduler::start', $callers, 'An argv to artisan with a command name runs that command.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('scanner')]
    public function testASymfonyCommandNamedByDefaultNameAndStartedThroughBinConsoleHasACaller(): void
    {
        $root = $this->project([
            'composer.json' => '{"require":{"symfony/console":"^7.0","symfony/framework-bundle":"^7.0"},"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/Command/SyncCommand.php' => <<<'PHP'
                <?php
                namespace App\Command;
                use Symfony\Component\Console\Command\Command;
                final class SyncCommand extends Command
                {
                    protected static $defaultName = 'app:sync';
                    protected function execute($input, $output): int { return 0; }
                }
                PHP,
            'src/Runner.php' => <<<'PHP'
                <?php
                namespace App;
                final class Runner
                {
                    public function start(): void { proc_open(['php', 'bin/console', 'app:sync'], [], $pipes); }
                }
                PHP,
            'bin/console' => "#!/usr/bin/env php\n<?php\nrequire dirname(__DIR__) . '/vendor/autoload.php';\n",
        ]);
        try {
            self::assertContains('App\\Runner::start', $this->callers($root, 'app:sync'), 'bin/console <name> runs the command declaring that name.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** @return list<string> the canonical names of everything that uses `$symbol` */
    private function callers(string $root, string $symbol): array
    {
        $pdo = $this->freshTestDatabase();
        $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;
        $usages = ArchitectureQueryService::forDatabase($pdo)->listUsages($projectId, $symbol)->data['usages'] ?? [];

        return array_map(static fn(array $usage): string => (string) $usage['source']['canonical_name'], $usages);
    }

    /** @param array<string, string> $files */
    private function project(array $files): string
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        foreach ($files as $path => $contents) {
            if (!is_dir(dirname($root . '/' . $path))) {
                mkdir(dirname($root . '/' . $path), 0o700, true);
            }
            file_put_contents($root . '/' . $path, $contents);
        }

        return $root;
    }
}

<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The PHP worker's own facts for a console command run by name, read from
 * PhpScanner in process. CommandDispatchFactsTest proves the whole path
 * through a scan; these pin each fact and the inputs that must give none.
 */
#[Group('php-scanner')]
final class PhpCommandDispatchFactsTest extends KnossosTestCase
{
    public function testAnArgvToArtisanCallsTheCommandItNames(): void
    {
        $edges = $this->edges(<<<'PHP'
            <?php
            final class Scheduler
            {
                public function start(): void
                {
                    proc_open([PHP_BINARY, 'artisan', 'orders:poll --dry-run'], [], $pipes);
                    proc_open([PHP_BINARY, 'artisan', '--version'], [], $pipes);
                    proc_open([PHP_BINARY, 'artisan', $name], [], $pipes);
                }
            }
            PHP);

        self::assertContains(['calls', 'php:method:Scheduler::start', 'php:artisan_command:orders:poll'], $edges);
        self::assertSame(1, count(array_filter($edges, static fn(array $edge): bool => str_starts_with($edge[2], 'php:artisan_command:'))), 'An option or a variable names no command.');
    }

    public function testAnArgvToBinConsoleCallsTheSymfonyCommandItNames(): void
    {
        $edges = $this->edges(<<<'PHP'
            <?php
            final class Runner
            {
                public function start(): void
                {
                    proc_open(['php', 'bin/console', 'app:sync'], [], $pipes);
                    proc_open(['php', 'bin/tool', 'app:other'], [], $pipes);
                }
            }
            PHP);

        self::assertContains(['calls', 'php:method:Runner::start', 'php:command:app:sync'], $edges);
        self::assertNotContains(['calls', 'php:method:Runner::start', 'php:command:app:other'], $edges, 'Only bin/console dispatches Symfony commands by name.');
    }

    public function testACommandCallingAnotherByNameCallsIt(): void
    {
        $edges = $this->edges(<<<'PHP'
            <?php
            final class Nightly
            {
                public function handle(): int
                {
                    $this->callSilently('orders:poll');
                    $this->call('reports:send', ['--now' => true]);
                    $other->call('not:this');
                    return 0;
                }
            }
            PHP, laravel: true);

        self::assertContains(['calls', 'php:method:Nightly::handle', 'php:artisan_command:orders:poll'], $edges);
        self::assertContains(['calls', 'php:method:Nightly::handle', 'php:artisan_command:reports:send'], $edges);
        self::assertNotContains(['calls', 'php:method:Nightly::handle', 'php:artisan_command:not:this'], $edges, 'Only a call on $this runs a command.');
    }

    public function testASymfonyDefaultNameDeclaresTheCommand(): void
    {
        $scan = $this->scan(<<<'PHP'
            <?php
            final class SyncCommand
            {
                protected static $defaultName = 'app:sync';
                protected static $other = 'not:a:name';
            }
            PHP, symfony: true);

        self::assertContains('php:command:app:sync', $scan['nodes']);
        self::assertContains(['handles', 'php:command:app:sync', 'php:class:SyncCommand'], $scan['edges']);
        self::assertNotContains('php:command:not:a:name', $scan['nodes']);
    }

    /** @return list<array{string, string, string}> */
    private function edges(string $source, bool $laravel = false): array
    {
        return $this->scan($source, $laravel)['edges'];
    }

    /** @return array{edges: list<array{string, string, string}>, nodes: list<string>} */
    private function scan(string $source, bool $laravel = false, bool $symfony = false): array
    {
        require_once self::repositoryRoot() . '/workers/php/vendor/autoload.php';
        $root = sys_get_temp_dir() . '/knossos-stale-php-facts-' . bin2hex(random_bytes(6));
        mkdir($root);
        file_put_contents($root . '/Subject.php', $source);
        try {
            $contribution = (new \KnossosPhpScanner\PhpScanner())->scan($root, $root . '/Subject.php', 'Subject.php', $laravel, $symfony);
        } finally {
            $this->removeTempTree($root);
        }

        return [
            'edges' => array_map(static fn(array $edge): array => [$edge['kind'], $edge['source'], $edge['target']], $contribution['edges']),
            'nodes' => array_map(static fn(array $node): string => (string) $node['local_id'], $contribution['nodes']),
        ];
    }
}

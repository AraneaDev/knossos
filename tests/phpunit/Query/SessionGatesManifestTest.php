<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\SessionGates;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** The bounded reading of composer.json and package.json behind the "scripts found" line. */
final class SessionGatesManifestTest extends KnossosTestCase
{
    /** @return iterable<string, array{string}> */
    public static function unusableManifests(): iterable
    {
        yield 'invalid json' => ['{ nope'];
        yield 'scripts is a string' => ['{"scripts":"test"}'];
        yield 'scripts is a number' => ['{"scripts":3}'];
        yield 'scripts is a list' => ['{"scripts":["test","lint"]}'];
        yield 'no scripts' => ['{"name":"x"}'];
        yield 'not an object' => ['[1,2]'];
        yield 'over the size cap' => ['{"scripts":{"test":"' . str_repeat('x', 1_000_001) . '"}}'];
    }

    #[DataProvider('unusableManifests')]
    #[Group('query')]
    public function testAManifestThatCannotBeUsedGivesNoNames(string $contents): void
    {
        $root = $this->root();
        try {
            file_put_contents($root . '/package.json', $contents);

            assertSame(SessionGates::none(), SessionGates::forRoot($root));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAMissingManifestGivesNoNames(): void
    {
        $root = $this->root();
        try {
            assertSame(SessionGates::none(), SessionGates::forRoot($root));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testASymlinkedManifestIsNotFollowed(): void
    {
        $root = $this->root();
        try {
            file_put_contents($root . '/real.json', '{"scripts":{"test":"x"}}');
            symlink($root . '/real.json', $root . '/package.json');
            file_put_contents($root . '/composer.json', '{"scripts":{"cs":"x"}}');

            assertSame(['declared' => [], 'found' => ['cs']], SessionGates::forRoot($root));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** The file passes the checks and then cannot be read (removed, or unreadable): no names, no failure. */
    #[Group('query')]
    public function testAManifestThatCannotBeReadGivesNoNames(): void
    {
        $root = $this->root();
        try {
            file_put_contents($root . '/package.json', '{"scripts":{"test":"x"}}');
            $asked = [];

            $result = SessionGates::forRoot($root, static function (string $path, int $limit) use (&$asked): false {
                $asked[] = [basename($path), $limit];

                return false;
            });

            assertSame(SessionGates::none(), $result);
            assertSame([['package.json', 1_000_001]], $asked);
        } finally {
            $this->removeTempTree($root);
        }
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(4));
        mkdir($root);

        return $root;
    }
}

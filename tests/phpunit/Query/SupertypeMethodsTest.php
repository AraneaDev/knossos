<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\SupertypeMethods;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertEqualsCanonicalizing;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/** The supertype methods a method fulfils: the same-named methods of its class's ancestors. */
final class SupertypeMethodsTest extends KnossosTestCase
{
    #[Group('query')]
    public function testAMethodFulfilsTheSameNamedMethodsOfItsClasssAncestorsOnly(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $owner = 'php:file:src/Checkout.php';
        $node = function (string $kind, string $name, string $display) use ($repository, $ids, $owner): string {
            $id = StableId::symbol($ids['project'], 'php', $kind, $name);
            $repository->saveNode($id, $ids['project'], 'php', $kind, $name, $display, null, $ids['file'], 1, 1, 'ast', 'certain', [], $owner, $ids['scan']);
            return $id;
        };
        $edge = function (string $kind, string $from, string $to) use ($repository, $ids, $owner): void {
            $repository->saveEdge(StableId::edge($ids['project'], $kind, $from, $to, 'x'), $ids['project'], $kind, $from, $to, $ids['file'], 1, 1, 'ast', 'certain', [], $owner, $ids['scan']);
        };
        $class = function (string $kind, string $name, array $methods) use ($node, $edge): array {
            $id = $node($kind, $name, $name);
            $members = [];
            foreach ($methods as $method) {
                $members[$method] = $node('method', "{$name}::{$method}", $method);
                $edge('contains', $id, $members[$method]);
            }
            return [$id, $members];
        };
        [$reads, $readsM] = $class('interface', 'App\\Reads', ['read']);
        [$base, $baseM] = $class('class', 'App\\Base', ['read', 'helper']);
        [$impl, $implM] = $class('class', 'App\\Impl', ['read', 'helper', 'own']);
        [, $otherM] = $class('class', 'App\\Other', ['read']);
        [$a, $aM] = $class('class', 'App\\A', ['m']);
        [$b, $bM] = $class('class', 'App\\B', ['m']);
        $edge('implements', $base, $reads);
        $edge('extends', $impl, $base);
        $edge('extends', $a, $b);
        $edge('extends', $b, $a);
        $repository->completeScan($ids['project'], $ids['scan']);

        $of = SupertypeMethods::of($pdo, $ids['project'], [$implM['read'], $implM['helper'], $implM['own'], $baseM['read'], $otherM['read'], $impl, $aM['m']]);

        assertEqualsCanonicalizing([$baseM['read'], $readsM['read']], $of[$implM['read']]);
        assertSame([$baseM['helper']], $of[$implM['helper']]);
        assertSame([$readsM['read']], $of[$baseM['read']]);
        // A cycle of parents ends, each finding the other's method once.
        assertSame([$bM['m']], $of[$aM['m']]);
        foreach ([$implM['own'], $otherM['read'], $impl] as $none) {
            assertArrayNotHasKey($none, $of);
        }
        assertSame([], SupertypeMethods::of($pdo, $ids['project'], []));
    }
}

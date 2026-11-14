<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * find_component, inspect_component and search_architecture say how to call a
 * function: the stored signature comes back as one `signature` line, in place
 * of its parts among the attributes.
 */
#[Group('query')]
final class ComponentSignatureTest extends KnossosTestCase
{
    public function testAFunctionIsAnsweredWithItsSignatureLine(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $name = 'App\\Checkout::capture';
        $attributes = ['visibility' => 'public', 'signature' => [
            'params' => [['name' => 'order', 'type' => 'App\\Order'], ['name' => 'retries', 'type' => 'int', 'optional' => true]],
            'returns' => '?App\\Receipt',
        ]];
        $repository->saveNode(StableId::symbol($ids['project'], 'php', 'method', $name), $ids['project'], 'php', 'method', $name, 'capture', $ids['checkout'], $ids['file'], 4, 9, 'ast', 'certain', $attributes, 'php:file:src/Checkout.php', $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = ArchitectureQueryService::forDatabase($pdo);
        $line = 'capture(order: App\\Order, retries?: int): ?App\\Receipt';

        $found = $queries->findComponent($ids['project'], $name)->data['components'][0];
        assertSame($line, $found['signature']);
        assertSame(['visibility' => 'public'], $found['attributes']);

        $inspected = $queries->inspectComponent($ids['project'], $name)->data['component'];
        assertSame($line, $inspected['signature']);
        assertSame(['visibility' => 'public'], $inspected['attributes']);

        $searched = $queries->searchArchitecture($ids['project'], 'capture')->data['results'][0];
        assertSame($line, $searched['signature']);

        $class = $queries->findComponent($ids['project'], 'App\\Checkout')->data['components'][0];
        assertSame(false, array_key_exists('signature', $class), 'A component without a stored signature has no line.');
    }
}

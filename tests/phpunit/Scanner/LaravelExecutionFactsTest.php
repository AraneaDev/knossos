<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('php-scanner')]
final class LaravelExecutionFactsTest extends KnossosTestCase
{
    public function testHttpHelpersKeepVerbsTemplatesAndCallingMethods(): void
    {
        $facts = $this->scan(<<<'SOURCE'
            <?php
            class FeatureTest {
                function requests($id, $user) {
                    $this->withHeaders([])->actingAs($user)->getJson("/orders/pre-{$id}?page=2#top");
                    $this->json('patch', '/orders/1');
                    $this->call('DELETE', '/orders/2');
                    $this->post('/orders');
                    $this->optionsJson('/orders');
                    $this->artisan('orders:poll --dry-run');
                    Artisan::queue('orders:queue');
                    Artisan::call('orders:call');
                }
            }
            SOURCE);
        $endpoints = array_values(array_filter($facts['nodes'], static fn(array $n): bool => $n['kind'] === 'endpoint'));
        self::assertCount(5, $endpoints);
        self::assertSame(['GET', 'PATCH', 'DELETE', 'POST', 'OPTIONS'], array_column(array_column($endpoints, 'attributes'), 'method'));
        self::assertSame('/orders/{}', ((array) $endpoints[0]['attributes'])['template']);
        self::assertSame('/orders/pre-{}?page=2#top', ((array) $endpoints[0]['attributes'])['uri']);
        $requests = array_values(array_filter($facts['edges'], static fn(array $e): bool => $e['kind'] === 'calls_endpoint'));
        self::assertCount(5, $requests);
        self::assertSame(['php:method:FeatureTest::requests'], array_values(array_unique(array_column($requests, 'source'))));
        foreach (['orders:poll', 'orders:queue', 'orders:call'] as $name) {
            self::assertContains('php:artisan_command:' . $name, array_column($facts['edges'], 'target'));
        }
    }

    public function testDynamicInvalidAndCallableRequestsProduceNoExecutionEdges(): void
    {
        $facts = $this->scan(<<<'SOURCE'
            <?php
            $this->get('/outside');
            Artisan::call('outside');
            class FeatureTest {
                function requests($uri, $verb, $client, $helper, $command) {
                    $client->get('/other-client');
                    $this->get('key');
                    $this->get($uri);
                    $this->json($verb, '/unknown-verb');
                    $this->json('GET');
                    $this->get(...);
                    $this->$helper('/dynamic');
                    $this->artisan(...);
                    Artisan::call(...);
                    Artisan::call($command);
                    Artisan::call('   ');
                    $this->artisan('');
                    $client->artisan('foreign');
                    Artisan::other('foreign');
                }
            }
            SOURCE);
        self::assertSame([], array_values(array_filter($facts['nodes'], static fn(array $n): bool => $n['kind'] === 'endpoint')));
        self::assertSame([], array_values(array_filter($facts['edges'], static fn(array $e): bool => $e['kind'] === 'calls_endpoint' || str_starts_with($e['target'], 'php:artisan_command:'))));
    }

    public function testEloquentRelationNamesAndFactoryModelsAreInferredConservatively(): void
    {
        $facts = $this->scan(<<<'SOURCE'
            <?php
            namespace App;
            class FeatureTest {
                function models(Order $order, $unknown, $name) {
                    $order->load('lines.product', 'creator:id,name', ['owner' => fn($q) => $q, 'tags', null, ...$unknown, '']);
                    $order->whereRelation('items', 'status', 'open');
                    $order->load(...);
                    $order?->product;
                    $unknown->untypedRelation;
                    $order->$name;
                    $query = Order::query()->where('id', 1);
                    $query->with('customer');
                    $query = null;
                    $query->load('stale');
                    $model = Order::factory()->state([])->create();
                    $model->parent;
                    Order::factory()->make()->inlineRelation;
                    $many = Order::factory()->count(2)->create();
                    $many->counted;
                    $many = Order::factory()->times(2)->make();
                    $many->timed;
                    $many = Order::factory(3)->create();
                    $many->multiple;
                    $other = Order::query()->create();
                    $other->notFactory;
                    $callable = Order::factory(...);
                }
            }
            SOURCE);
        $references = array_values(array_filter($facts['edges'], static fn(array $e): bool => $e['kind'] === 'references' && str_starts_with($e['target'], 'php:method:App\\Order::')));
        $names = array_column($references, 'target');
        foreach (['lines', 'creator', 'owner', 'tags', 'items', 'product', 'customer', 'parent', 'inlineRelation'] as $name) {
            self::assertContains('php:method:App\\Order::' . $name, $names);
        }
        foreach (['status', 'open', 'stale', 'counted', 'timed', 'multiple', 'notFactory'] as $name) {
            self::assertNotContains('php:method:App\\Order::' . $name, $names);
        }
        foreach ($references as $reference) {
            self::assertTrue(((array) $reference['attributes'])['speculative']);
        }
    }

    /** @return array<string, mixed> */
    private function scan(string $source): array
    {
        require_once self::repositoryRoot() . '/workers/php/vendor/autoload.php';
        $root = sys_get_temp_dir() . '/knossos-stale-laravel-execution-' . bin2hex(random_bytes(6));
        mkdir($root);
        file_put_contents($root . '/Subject.php', $source);
        try {
            return (new \KnossosPhpScanner\PhpScanner())->scan($root, $root . '/Subject.php', 'Subject.php', true);
        } finally {
            $this->removeTempTree($root);
        }
    }
}

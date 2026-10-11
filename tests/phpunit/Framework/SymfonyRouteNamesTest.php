<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Framework;

use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertSame;

/**
 * A reference to a route by its name becomes an `endpoint` node carrying that
 * name, so the core can join it to the route the name belongs to.
 */
#[Group('symfony')]
final class SymfonyRouteNamesTest extends KnossosTestCase
{
    public function testEachGeneratedRouteNameBecomesAnEndpointCalledFromItsMethod(): void
    {
        [$nodes, $edges] = $this->scan();

        $names = [];
        foreach ($nodes as $node) {
            if ($node->kind === 'endpoint') {
                $names[] = $node->attributes['route_name'];
                assertSame('symfony', $node->attributes['framework']);
            }
        }
        sort($names);
        assertSame(['order_copy', 'order_edit', 'order_index', 'order_new', 'order_show'], $names);

        $called = [];
        foreach ($edges as $edge) {
            if ($edge->kind === 'calls_endpoint' && $edge->sourceReference === 'php:method:App\\Controller\\OrderController::store') {
                $called[] = $edge->targetReference;
            }
        }
        sort($called);
        assertSame(
            array_map(fn(string $name): string => 'php:endpoint:route symfony:' . $name, $names),
            $called,
        );
    }

    public function testTheRouteNodeNamesTheFrameworkItBelongsTo(): void
    {
        [$nodes] = $this->scan();

        $routes = array_values(array_filter($nodes, fn(NodeFact $node): bool => $node->kind === 'route'));
        assertSame(1, count($routes));
        assertSame(['symfony', 'order_show'], [$routes[0]->attributes['framework'], $routes[0]->attributes['name']]);
    }

    /** @return array{0: list<NodeFact>, 1: list<EdgeFact>} */
    private function scan(): array
    {
        $root = sys_get_temp_dir() . '/knossos-stale-symfony-names-' . bin2hex(random_bytes(4));
        mkdir($root . '/src/Controller', 0o777, true);
        file_put_contents($root . '/src/Controller/OrderController.php', <<<'PHP'
            <?php
            namespace App\Controller;

            use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
            use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
            use Symfony\Component\Routing\RouterInterface;
            use Symfony\Component\Routing\Attribute\Route;

            final class OrderController extends AbstractController
            {
                public function __construct(private UrlGeneratorInterface $urls, private RouterInterface $router) {}

                #[Route('/orders/{id}', name: 'order_show')]
                public function show(int $id) { return $id; }

                public function store(UrlGeneratorInterface $generator)
                {
                    $this->generateUrl('order_show', ['id' => 1]);
                    $this->redirectToRoute('order_index');
                    $this->urls->generate('order_edit');
                    $this->router->generate('order_new');
                    $generator->generate('order_copy');
                    $this->other->generate('not_a_route');
                }
            }
            PHP);
        try {
            $client = $this->phpWorkerClient();
            $contributions = iterator_to_array($client->scan([
                'root' => $root,
                'files' => ['src/Controller/OrderController.php'],
                'frameworks' => ['symfony'],
            ]));
            $client->shutdown();
        } finally {
            $this->removeTempTree($root);
        }

        return [
            array_merge(...array_map(fn(ScanContribution $item): array => $item->nodes, $contributions)),
            array_merge(...array_map(fn(ScanContribution $item): array => $item->edges, $contributions)),
        ];
    }
}

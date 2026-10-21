<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Framework;

use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

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

    public function testAFirstClassCallableNextToAReferenceKeepsTheFactsOfTheFile(): void
    {
        [$nodes] = $this->scan(['src/Controller/ListController.php' => <<<'PHP'
            <?php
            namespace App\Controller;

            use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

            final class ListController extends AbstractController
            {
                public function index(array $items)
                {
                    $format = array_map($this->format(...), $items);
                    $link = $this->generateUrl(...);

                    return [$format, $link, $this->generateUrl('list_show')];
                }

                private function format($item) { return $item; }
            }
            PHP], assertions: true);

        assertSame(['list_show'], $this->routeNames($nodes));
    }

    public function testANameBuiltAtRuntimeNamesNoRoute(): void
    {
        [$nodes] = $this->scan(['src/Controller/DynamicController.php' => <<<'PHP'
            <?php
            namespace App\Controller;

            use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

            final class DynamicController extends AbstractController
            {
                public function go(string $name)
                {
                    return [$this->generateUrl($name), $this->generateUrl('order_' . $name), $this->redirectToRoute("x_{$name}")];
                }
            }
            PHP]);

        assertSame([], $this->routeNames($nodes));
    }

    public function testANullableGeneratorPropertyIsRecognised(): void
    {
        [$nodes] = $this->scan(['src/Service/Links.php' => <<<'PHP'
            <?php
            namespace App\Service;

            use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

            final class Links
            {
                private ?UrlGeneratorInterface $urls = null;

                public function show(): string
                {
                    return $this->urls->generate('order_show');
                }
            }
            PHP]);

        assertSame(['order_show'], $this->routeNames($nodes));
    }

    public function testARouteNameReferencedTwiceKeepsItsFirstDeclaration(): void
    {
        // A Symfony node keeps its first declaration, as a Laravel one does.
        [$nodes, $edges] = $this->scan(['src/Controller/TwiceController.php' => <<<'PHP'
            <?php
            namespace App\Controller;

            use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

            final class TwiceController extends AbstractController
            {
                public function go()
                {
                    $first = $this->generateUrl('order_show');

                    return [$first, $this->redirectToRoute('order_show')];
                }
            }
            PHP]);

        $endpoints = array_values(array_filter($nodes, fn(NodeFact $node): bool => $node->kind === 'endpoint'));
        assertSame(1, count($endpoints));
        assertSame(10, $endpoints[0]->evidence->startLine);
        $calls = array_values(array_filter($edges, fn(EdgeFact $edge): bool => $edge->kind === 'calls_endpoint'));
        assertSame([10, 12], array_map(fn(EdgeFact $edge): int => $edge->evidence->startLine, $calls));
    }

    /**
     * @param list<NodeFact> $nodes
     * @return list<string>
     */
    private function routeNames(array $nodes): array
    {
        $names = [];
        foreach ($nodes as $node) {
            if ($node->kind === 'endpoint') {
                $names[] = $node->attributes['route_name'];
            }
        }
        sort($names);

        return $names;
    }

    /**
     * @param array<string, string>|null $files the tree to scan, or null for the default controller
     * @return array{0: list<NodeFact>, 1: list<EdgeFact>}
     */
    private function scan(?array $files = null, bool $assertions = false): array
    {
        $files ??= [
            'src/Controller/OrderController.php' => <<<'PHP'
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
            PHP,
        ];
        $root = sys_get_temp_dir() . '/knossos-stale-symfony-names-' . bin2hex(random_bytes(4));
        foreach ($files as $path => $contents) {
            @mkdir(dirname($root . '/' . $path), 0o777, true);
            file_put_contents($root . '/' . $path, $contents);
        }
        try {
            $client = $this->phpWorkerClient($assertions);
            $contributions = iterator_to_array($client->scan([
                'root' => $root,
                'files' => array_keys($files),
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

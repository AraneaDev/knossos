<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;

/**
 * Records a reference to a route by its name: `route('orders.show')`,
 * `to_route(...)`, `redirect()->route(...)`, `URL::route(...)`, `URL::signedRoute(...)`,
 * `URL::temporarySignedRoute(...)` and `Redirect::route(...)`. Each is an endpoint the core joins to the route
 * of that name, so the controller a redirect lands on is reached. A name
 * built at runtime names nothing, and `routeIs()` only tests the current one.
 */
final readonly class LaravelRouteNameFactCollector
{
    private const FACADES = ['url', 'redirect'];
    private const SIGNED = ['signedroute', 'temporarysignedroute'];

    public function __construct(
        private LaravelFactStore $facts,
        private LaravelTraversalContext $context,
    ) {}

    /** Records an endpoint and a calls_endpoint edge for each literal route name reference. */
    public function enterNode(Node $node): void
    {
        $name = match (true) {
            $node instanceof Expr\FuncCall => $this->helperName($node),
            $node instanceof Expr\StaticCall => $this->facadeName($node),
            $node instanceof Expr\MethodCall => $this->redirectorName($node),
            default => null,
        };
        $source = $this->context->currentSource();
        if ($name === null || $source === null) {
            return;
        }
        $id = 'php:endpoint:route laravel:' . $name;
        $this->facts->addNode($id, 'endpoint', 'route laravel:' . $name, "route('{$name}')", $node, [
            'framework' => 'laravel',
            'route_name' => $name,
        ]);
        $this->facts->addEdge('calls_endpoint', $source, $id, $node);
    }

    /** The literal name given to route() or to_route(), if any. */
    private function helperName(Expr\FuncCall $node): ?string
    {
        return $node->name instanceof Name && in_array(strtolower($node->name->toString()), ['route', 'to_route'], true)
            ? self::literal($node)
            : null;
    }

    /** The literal name given to a facade route() call such as URL::route, if any. */
    private function facadeName(Expr\StaticCall $node): ?string
    {
        if (!$node->class instanceof Name || !$node->name instanceof Identifier) {
            return null;
        }
        $facade = strtolower($node->class->getLast());
        $called = strtolower($node->name->toString());
        $names = ($called === 'route' && in_array($facade, self::FACADES, true))
            || ($facade === 'url' && in_array($called, self::SIGNED, true));

        return $names ? self::literal($node) : null;
    }

    /** The literal name given to redirect()->route(), if any. */
    private function redirectorName(Expr\MethodCall $node): ?string
    {
        return $node->name instanceof Identifier && strtolower($node->name->toString()) === 'route'
            && $node->var instanceof Expr\FuncCall && $node->var->name instanceof Name
            && strtolower($node->var->name->toString()) === 'redirect'
            ? self::literal($node)
            : null;
    }

    /** The first argument when it is a plain string literal. */
    private static function literal(Expr\FuncCall|Expr\StaticCall|Expr\MethodCall $node): ?string
    {
        // A first-class callable such as `route(...)` has no arguments to read.
        if ($node->isFirstClassCallable()) {
            return null;
        }
        $first = $node->getArgs()[0]->value ?? null;

        return $first instanceof String_ && $first->value !== '' ? $first->value : null;
    }
}

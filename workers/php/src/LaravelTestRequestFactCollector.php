<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;

/**
 * Records the requests a Laravel test makes through its HTTP helpers:
 * `$this->postJson('/api/orders')`, `$this->actingAs($u)->get("/orders/{$id}")`,
 * `$this->json('PATCH', '/orders/1')`. Each is an endpoint the core joins to
 * the route that serves it, so the controller a feature test drives is reached
 * by that test. A path built at runtime keeps a parameter where its value goes;
 * a string that is not a path (`$this->get('key')` on some other object) names
 * nothing, since only a call on a chain rooted at `$this` and a literal
 * starting with `/` count.
 */
final readonly class LaravelTestRequestFactCollector
{
    /** Helper (lower-cased) => the HTTP method it sends. */
    private const HELPERS = [
        'get' => 'GET', 'getjson' => 'GET', 'post' => 'POST', 'postjson' => 'POST',
        'put' => 'PUT', 'putjson' => 'PUT', 'patch' => 'PATCH', 'patchjson' => 'PATCH',
        'delete' => 'DELETE', 'deletejson' => 'DELETE', 'options' => 'OPTIONS', 'optionsjson' => 'OPTIONS',
    ];

    /** Helpers (lower-cased) taking the method as their first argument and the path second. */
    private const WITH_METHOD = ['json', 'call'];

    public function __construct(
        private LaravelFactStore $facts,
        private LaravelTraversalContext $context,
    ) {}

    /**
     * Records an endpoint and a calls_endpoint edge for each test request with
     * a readable path, and a speculative call to the command an Artisan call names.
     */
    public function enterNode(Node $node): void
    {
        $this->artisanCall($node);
        // `$this->format(...)` is a first-class callable: it has no arguments to read.
        if (!$node instanceof Expr\MethodCall || $node->isFirstClassCallable() || !$node->name instanceof Identifier || !self::onThis($node->var)) {
            return;
        }
        $helper = strtolower($node->name->toString());
        $arguments = $node->getArgs();
        if (isset(self::HELPERS[$helper])) {
            $method = self::HELPERS[$helper];
            $path = self::path($arguments[0]->value ?? null);
        } elseif (in_array($helper, self::WITH_METHOD, true)) {
            $verb = $arguments[0]->value ?? null;
            $method = $verb instanceof String_ ? strtoupper($verb->value) : null;
            $path = self::path($arguments[1]->value ?? null);
        } else {
            return;
        }
        $source = $this->context->currentSource();
        if ($method === null || $path === null || $source === null) {
            return;
        }
        $id = 'php:endpoint:' . $method . ' ' . $path;
        $this->facts->addNode($id, 'endpoint', $method . ' ' . $path, $method . ' ' . $path, $node, [
            'framework' => 'laravel',
            'method' => $method,
            'uri' => $path,
            'template' => self::template($path),
            'prefix' => 'known',
            'external' => false,
        ]);
        $this->facts->addEdge('calls_endpoint', $source, $id, $node);
    }

    /**
     * `$this->artisan('orders:poll --dry-run')` or `Artisan::call('orders:poll')`:
     * a call to whichever command declares that name, settled by the core,
     * which sees every command's signature, and dropped there when no command
     * of the project declares it (a framework command such as `migrate`).
     */
    private function artisanCall(Node $node): void
    {
        $named = match (true) {
            $node instanceof Expr\MethodCall => $node->name instanceof Identifier && strtolower($node->name->toString()) === 'artisan' && self::onThis($node->var),
            $node instanceof Expr\StaticCall => $node->class instanceof Node\Name && strtolower($node->class->getLast()) === 'artisan'
                && $node->name instanceof Identifier && in_array(strtolower($node->name->toString()), ['call', 'queue'], true),
            default => false,
        };
        $first = $named && ($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall) && !$node->isFirstClassCallable()
            ? ($node->getArgs()[0]->value ?? null)
            : null;
        $source = $this->context->currentSource();
        if (!$first instanceof String_ || $source === null) {
            return;
        }
        $command = preg_split('/\s/', trim($first->value), 2)[0] ?? '';
        if ($command !== '') {
            $this->facts->addEdge('calls', $source, 'php:artisan_command:' . $command, $node, ['speculative' => true]);
        }
    }

    /** Whether a receiver is `$this` or a chain of calls on it: `$this->withHeaders(...)->actingAs($u)`. */
    private static function onThis(Expr $receiver): bool
    {
        while ($receiver instanceof Expr\MethodCall) {
            $receiver = $receiver->var;
        }

        return $receiver instanceof Expr\Variable && $receiver->name === 'this';
    }

    /**
     * The path a literal or an interpolated string spells, a runtime value
     * written `{}`; null for anything else, or a string that is not a path.
     */
    private static function path(?Expr $value): ?string
    {
        if ($value instanceof String_) {
            $path = $value->value;
        } elseif ($value instanceof InterpolatedString) {
            $path = '';
            foreach ($value->parts as $part) {
                $path .= $part instanceof InterpolatedStringPart ? $part->value : '{}';
            }
        } else {
            return null;
        }

        return str_starts_with($path, '/') ? $path : null;
    }

    /** The path template the core matches against routes: the query dropped, a segment holding a runtime value a parameter. */
    private static function template(string $path): string
    {
        $bare = (string) preg_replace('/[?#].*$/s', '', $path);
        $segments = array_map(
            static fn(string $segment): string => str_contains($segment, '{}') ? '{}' : $segment,
            array_values(array_filter(explode('/', $bare), static fn(string $segment): bool => $segment !== '')),
        );

        return '/' . implode('/', $segments);
    }
}

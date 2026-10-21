<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

/**
 * Tracks Eloquent query builders and factories through one file: the
 * relations a query or a model names in a string, the class a chain of calls
 * started from with a static call, and the model a factory chain builds.
 *
 * The relations it reads are methods on a model that nothing calls by name,
 * and a factory is vendor code, so without this the relations read as dead
 * code and the model a factory returns types nothing.
 */
final class LaravelEloquentFactCollector
{
    /** Eloquent query and model methods whose string arguments name relations, lower-cased. */
    private const RELATION_NAMING_METHODS = [
        'with', 'without', 'withonly', 'load', 'loadmissing', 'loadcount', 'withcount', 'withexists',
        'has', 'orhas', 'doesnthave', 'ordoesnthave', 'wherehas', 'orwherehas', 'wheredoesnthave',
        'orwheredoesnthave', 'withwherehas', 'whererelation', 'orwhererelation',
    ];

    /** Factory methods that build and return one model, lower-cased. */
    private const FACTORY_BUILDERS = ['create', 'make', 'createone', 'makeone', 'createquietly', 'createonequietly'];

    /** The members of {@see self::RELATION_NAMING_METHODS} where every argument may name relations. */
    private const VARIADIC_RELATION_METHODS = ['with', 'without', 'withonly', 'load', 'loadmissing', 'loadcount', 'withcount', 'withexists'];

    /**
     * Per enclosing function, the variables holding a query begun with a
     * static call on a class: `$query = Order::where(...)`, keyed by name.
     *
     * @var list<array<string, string>>
     */
    private array $builders = [];

    /**
     * @param VariableTypeScope $variables what each local variable holds, typed here when a factory builds it
     * @param \Closure(Name): string $resolvedClassName a class name read against the current class
     */
    public function __construct(
        private readonly VariableTypeScope $variables,
        private readonly \Closure $resolvedClassName,
    ) {}

    /** Open the builder scope of a function the traversal enters. */
    public function enterFunction(): void
    {
        $this->builders[] = [];
    }

    /** Close the builder scope of the function the traversal leaves. */
    public function leaveFunction(): void
    {
        array_pop($this->builders);
    }

    /**
     * Eloquent relations a query or a model names in a string: `with('lines')`,
     * `load(['creator:id,name'])`, `whereHas('lines.product', ...)`.
     *
     * The relation is a method on the receiver's class that nothing calls by
     * name. Only a path's first segment is the receiver's own relation; the
     * rest belong to whatever that relation returns, which this file cannot
     * see.
     *
     * @return list<string> the relation method names, in the order written
     */
    public function relations(Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall $node): array
    {
        // `$order->load(...)` is a first-class callable: it has no arguments to read.
        if ($node->isFirstClassCallable() || !$node->name instanceof Identifier
            || !in_array(strtolower($node->name->toString()), self::RELATION_NAMING_METHODS, true)) {
            return [];
        }
        $names = [];
        $arguments = $node->getArgs();
        // `whereRelation('lines', 'status', 'open')` and `has('lines', '>', 2)`
        // name one relation and then columns and values; the eager loads take
        // any number of relations.
        if (!in_array(strtolower($node->name->toString()), self::VARIADIC_RELATION_METHODS, true)) {
            $arguments = array_slice($arguments, 0, 1);
        }
        foreach ($arguments as $argument) {
            $value = $argument->value;
            if ($value instanceof Node\Scalar\String_) {
                $names[] = $value->value;
            } elseif ($value instanceof Expr\Array_) {
                foreach ($value->items as $item) {
                    $key = $item?->key instanceof Node\Scalar\String_ ? $item->key->value : null;
                    $names[] = $key ?? ($item?->value instanceof Node\Scalar\String_ ? $item->value->value : null);
                }
            }
        }
        $relations = [];
        foreach (array_unique(array_filter($names, 'is_string')) as $path) {
            $relation = explode('.', explode(':', $path, 2)[0], 2)[0];
            if ($relation !== '') {
                $relations[] = $relation;
            }
        }

        return $relations;
    }

    /**
     * Remember a variable assigned a query that starts with a static call on a
     * class, and type one assigned the model a factory builds.
     */
    public function assignment(Expr\Assign $node): void
    {
        if (!$node->var instanceof Expr\Variable || !is_string($node->var->name) || $this->builders === []) {
            return;
        }
        $model = $this->factoryModel($node->expr);
        if ($model !== null) {
            // Inferred from local flow, like `$x = new Y()`, so only probable.
            $this->variables->set($node->var->name, $model, 'probable');
        }
        $root = $this->builderRoot($node->expr);
        $scope = array_key_last($this->builders);
        if ($root === null) {
            unset($this->builders[$scope][$node->var->name]);

            return;
        }
        $this->builders[$scope][$node->var->name] = $root;
    }

    /**
     * The model an Eloquent factory chain builds: `Product::factory()->bundle()->create()`
     * or `->make()`. The factory is vendor code, so nothing in the graph says
     * what `create()` returns. A chain that asks for several, through
     * `count()`, `times()` or `factory(3)`, builds a collection instead, and
     * gives null like any other expression.
     */
    public function factoryModel(Expr $expression): ?string
    {
        if (!$expression instanceof Expr\MethodCall || !$expression->name instanceof Identifier
            || !in_array(strtolower($expression->name->toString()), self::FACTORY_BUILDERS, true)) {
            return null;
        }
        do {
            $expression = $expression->var;
            if ($expression instanceof Expr\MethodCall && $expression->name instanceof Identifier
                && in_array(strtolower($expression->name->toString()), ['count', 'times'], true)) {
                return null;
            }
        } while ($expression instanceof Expr\MethodCall);
        if (!$expression instanceof Expr\StaticCall || $expression->isFirstClassCallable() || !$expression->class instanceof Name
            || !$expression->name instanceof Identifier || strtolower($expression->name->toString()) !== 'factory') {
            return null;
        }
        $first = $expression->getArgs()[0]->value ?? null;

        return $first instanceof Node\Scalar\Int_ ? null : ($this->resolvedClassName)($expression->class);
    }

    /**
     * The class a chain of calls starts from with a static call, `Order::where()->where()`,
     * or that a variable holding such a chain was assigned; null for any other receiver.
     */
    public function builderRoot(Expr $expression): ?string
    {
        while ($expression instanceof Expr\MethodCall || $expression instanceof Expr\NullsafeMethodCall) {
            $expression = $expression->var;
        }
        if ($expression instanceof Expr\StaticCall && $expression->class instanceof Name) {
            return ($this->resolvedClassName)($expression->class);
        }
        if ($expression instanceof Expr\Variable && is_string($expression->name) && $this->builders !== []) {
            return $this->builders[array_key_last($this->builders)][$expression->name] ?? null;
        }

        return null;
    }
}

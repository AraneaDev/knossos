<?php

declare(strict_types=1);

namespace KnossosPhpScanner\TestFacts;

use KnossosPhpScanner\FrameworkFactStore;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * The test doubles of a test file, as `test_double` nodes with a `contains`
 * edge from the file's module and a `doubles` edge to the class each stands
 * in for.
 *
 * Ported from Momus's PHP mock extraction and Nemesis's PHP double
 * extractor. One pass over the name-resolved syntax tree records the doubles
 * made, the variables and `$this` properties assigned, the chains of calls
 * made on anything and the places a value is handed on; a second, once every
 * double is known, reads each chain against the double it starts on. Parses
 * only: nothing in the file is loaded or run.
 */
final class TestDoubleCollector extends NodeVisitorAbstract
{
    /** How deep one binding may lead to another before the collector stops following them. */
    private const MAX_BINDING_DEPTH = 16;

    /** @var array<int, DoubleRecord> the doubles, by the id of the call that makes each */
    private array $doubles = [];

    /** @var array<string, DoubleRecord> one double per facade class, by its lower-case name */
    private array $facades = [];

    /** @var list<array{Expr\MethodCall|Expr\StaticCall, Site}> the outermost call of each chain */
    private array $chains = [];

    /** @var array<int, true> the calls that sit inside a longer chain */
    private array $inner = [];

    /** @var list<array{Expr, Site, int}> values handed on: to a call, a `return`, an array or another object's property */
    private array $handOffs = [];

    private readonly ScopeStack $scopes;

    private readonly Bindings $bindings;

    private function __construct(private readonly string $relativePath, private readonly string $source)
    {
        $this->bindings = new Bindings();
        $this->scopes = new ScopeStack();
    }

    /**
     * Read a test file's doubles: one traversal records what the file makes,
     * binds, calls and hands on, then every chain is read against the double
     * it starts on. Parses only: nothing in the file is loaded or run.
     *
     * @param list<Node> $ast the file's syntax tree, its names resolved
     */
    public static function read(array $ast, string $relativePath, string $source): self
    {
        $collector = new self($relativePath, $source);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector);
        $traverser->traverse($ast);
        $collector->resolve();

        return $collector;
    }

    /** What the file's variables and `$this` properties hold, by where they are assigned. */
    public function bindings(): Bindings
    {
        return $this->bindings;
    }

    /** Record what this node makes, binds, calls or hands on, as the traversal enters it. */
    #[\Override]
    public function enterNode(Node $node): ?int
    {
        $this->scopes->enter($node);
        if ($node instanceof Expr) {
            $this->expression($node);
        } elseif ($node instanceof Stmt\Return_ && $node->expr !== null) {
            $this->handOffs[] = [$node->expr, $this->scopes->site(), $node->getStartLine()];
        }

        return null;
    }

    /** Leave the function, class or method the traversal was in. */
    #[\Override]
    public function leaveNode(Node $node): ?int
    {
        $this->scopes->leave($node);

        return null;
    }

    /** Record an expression: a double made, a binding, a chain of calls, a value handed on. */
    private function expression(Expr $node): void
    {
        $double = DoubleFactories::recognise($node);
        if ($double !== null) {
            $this->doubles[spl_object_id($node)] = $double;
            $closure = $node instanceof Expr\CallLike ? DoubleFactories::configuringClosure($node) : null;
            $parameter = $closure?->getParams()[0]->var ?? null;
            if ($closure !== null && $parameter instanceof Expr\Variable && is_string($parameter->name) && in_array($double->framework, ['mockery', 'pest'], true)) {
                $this->bindings->bindVariable('f' . spl_object_id($closure), $parameter->name, $closure->getStartFilePos(), $double);
            }
        }
        $facade = DoubleFactories::facade($node);
        if ($facade !== null) {
            $this->doubles[spl_object_id($node)] = $this->facades[strtolower($facade)] ??= new DoubleRecord($node, 'mockery', 'Facade::shouldReceive', null, false, $facade);
        }
        if ($node instanceof Expr\Assign) {
            $this->assignment($node);
        }
        if (($node instanceof Expr\MethodCall || ($facade !== null && $node instanceof Expr\StaticCall)) && !isset($this->inner[spl_object_id($node)])) {
            $this->chain($node);
        }
        if ($node instanceof Expr\CallLike && !$node->isFirstClassCallable() && !self::isAssertion($node)) {
            $site = $this->scopes->site();
            foreach ($node->getArgs() as $argument) {
                $this->handOffs[] = [$argument->value, $site, $node->getStartLine()];
            }
        } elseif ($node instanceof Expr\Array_) {
            $site = $this->scopes->site();
            foreach ($node->items as $item) {
                $this->handOffs[] = [$item->value, $site, $item->getStartLine()];
            }
        }
    }

    /** Bind a variable or `$this` property to what is assigned to it; a property of another object holds the value it is handed. */
    private function assignment(Expr\Assign $node): void
    {
        $target = $node->var;
        $site = $this->scopes->site();
        if ($target instanceof Expr\Variable && is_string($target->name)) {
            $this->bindings->bindVariable($site->variableScope($target->name), $target->name, $node->getEndFilePos(), [$node->expr, $site]);
        } elseif ($target instanceof Expr\PropertyFetch && self::isThis($target->var) && $target->name instanceof Identifier) {
            $this->bindings->bindProperty($site, $target->name->toString(), $node->getEndFilePos(), [$node->expr, $site]);
        } elseif ($target instanceof Expr\PropertyFetch || $target instanceof Expr\StaticPropertyFetch) {
            $this->handOffs[] = [$node->expr, $site, $node->getStartLine()];
        }
    }

    /** Keep the outermost call of a chain, and mark the calls inside it so none is read as a chain of its own. */
    private function chain(Expr\MethodCall|Expr\StaticCall $node): void
    {
        $this->chains[] = [$node, $this->scopes->site()];
        $current = $node;
        while ($current instanceof Expr\MethodCall) {
            $current = $current->var;
            $this->inner[spl_object_id($current)] = true;
        }
    }

    /** Read every chain against the double it starts on, then every value handed on. */
    private function resolve(): void
    {
        foreach ($this->chains as [$outer, $site]) {
            [$double, $revealed, $calls] = $this->start($outer, $site, 0);
            if ($double !== null) {
                ConfigurationChain::walk($double, $calls, true, $revealed);
            }
        }
        foreach ($this->handOffs as [$value, $site, $line]) {
            $this->valueOf($value, $site, 0)?->double->reach($line);
        }
    }

    /**
     * The file's doubles, each once, in the order the file makes them.
     *
     * @return list<DoubleRecord>
     */
    public function records(): array
    {
        // A facade's one double is listed under every call that configures it.
        $doubles = [];
        foreach ($this->doubles as $double) {
            $doubles[spl_object_id($double)] = $double;
        }
        usort($doubles, static fn(DoubleRecord $a, DoubleRecord $b): int => $a->node->getStartFilePos() <=> $b->node->getStartFilePos());

        return $doubles;
    }

    /** A double's canonical name: its file, then the line and column it is made at. */
    public function canonicalOf(DoubleRecord $double): string
    {
        return sprintf('%s#double:%d:%d', $this->relativePath, $double->node->getStartLine(), $this->column($double->node));
    }

    /**
     * The double an expression read at a site holds, if any: a double made
     * there, a variable or `$this` property bound to one, or a chain of calls
     * that hands it back.
     */
    public function held(Expr $value, Site $site): ?HeldDouble
    {
        return $this->valueOf($value, $site, 0);
    }

    /** The double a facade class is swapped for in this file, by its fully qualified name; null when none is. */
    public function facade(string $class): ?DoubleRecord
    {
        return $this->facades[strtolower($class)] ?? null;
    }

    /**
     * Whether an expression makes a double, or is a chain of calls that
     * configures one rather than calling it: what it is handed builds or
     * configures the double, and runs no code under test.
     */
    public function configures(Expr $node, Site $site): bool
    {
        if (isset($this->doubles[spl_object_id($node)])) {
            return true;
        }
        if (!$node instanceof Expr\MethodCall) {
            return false;
        }
        [$double, $revealed, $calls] = $this->start($node, $site, 0);

        return $double !== null && ConfigurationChain::expects($double, $calls, $revealed);
    }

    /**
     * The file's doubles as `test_double` nodes, each with a `contains` edge
     * from the module and a `doubles` edge to the class it stands in for.
     *
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    public function facts(): array
    {
        $nodes = $edges = [];
        foreach ($this->records() as $double) {
            $canonical = $this->canonicalOf($double);
            $id = 'php:test_double:' . $canonical;
            $evidence = FrameworkFactStore::evidenceIn($this->relativePath, $double->node);
            $nodes[] = ['local_id' => $id, 'kind' => 'test_double', 'canonical_name' => $canonical, 'display_name' => $double->displayName(), 'origin' => 'ast', 'confidence' => 'certain', 'evidence' => $evidence, 'attributes' => (object) $double->attributes()];
            $edges[] = self::edge('contains', 'php:module:' . $this->relativePath, $id, $evidence);
            if ($double->target !== null) {
                $edges[] = self::edge('doubles', $id, 'php:class:' . $double->target, $evidence);
            }
        }

        return [$nodes, $edges];
    }

    /**
     * The value of an expression when it is a double: a double made, a
     * variable or `$this` property bound to one, or a chain of calls that
     * hands it back, and whether it is a Prophecy double revealed.
     */
    private function valueOf(Expr $value, Site $site, int $depth): ?HeldDouble
    {
        [$double, $revealed, $calls] = $this->start($value, $site, $depth);
        $state = $double === null ? null : ConfigurationChain::walk($double, $calls, false, $revealed);

        return $double === null || $state === null ? null : new HeldDouble($double, $state);
    }

    /**
     * The double a chain of calls starts on, if any, whether it is already
     * revealed, and its calls innermost first. A facade's static
     * `shouldReceive()` is both the double and the chain's first call.
     *
     * @return array{?DoubleRecord, bool, list<Expr\MethodCall|Expr\StaticCall>}
     */
    private function start(Expr $outer, Site $site, int $depth): array
    {
        $calls = [];
        $current = $outer;
        while (!isset($this->doubles[spl_object_id($current)]) && $current instanceof Expr\MethodCall) {
            $calls[] = $current;
            $current = $current->var;
        }
        $double = $this->doubles[spl_object_id($current)] ?? null;
        if ($double !== null && $current instanceof Expr\StaticCall && $double->pattern === 'Facade::shouldReceive') {
            $calls[] = $current;
        }

        $held = $double === null ? $this->bound($current, $site, $depth) : new HeldDouble($double, false);

        return [$held?->double, $held->revealed ?? false, array_reverse($calls)];
    }

    /** The double a variable or `$this` property holds where it is read, following one binding to another. */
    private function bound(Expr $leaf, Site $site, int $depth): ?HeldDouble
    {
        if ($depth > self::MAX_BINDING_DEPTH) {
            return null;
        }
        $at = $leaf->getStartFilePos();
        $value = match (true) {
            $leaf instanceof Expr\Variable && is_string($leaf->name) && $leaf->name !== 'this' => $this->bindings->variable($leaf->name, $site, $at),
            $leaf instanceof Expr\PropertyFetch && self::isThis($leaf->var) && $leaf->name instanceof Identifier => $this->bindings->property($leaf->name->toString(), $site, $at),
            default => null,
        };

        return match (true) {
            is_array($value) => $this->valueOf($value[0], $value[1], $depth + 1),
            $value === null => null,
            default => new HeldDouble($value, false),
        };
    }

    /** The 1-based column a node starts at. */
    private function column(Node $node): int
    {
        $position = max(0, $node->getStartFilePos());
        $lineStart = strrpos(substr($this->source, 0, $position), "\n");

        return $lineStart === false ? $position + 1 : $position - $lineStart;
    }

    /** Whether a call is an assertion, which reads a double rather than handing it to code under test. */
    private static function isAssertion(Expr\CallLike $call): bool
    {
        $name = match (true) {
            $call instanceof Expr\MethodCall, $call instanceof Expr\StaticCall, $call instanceof Expr\NullsafeMethodCall => $call->name instanceof Identifier ? $call->name->toString() : '',
            $call instanceof Expr\FuncCall => $call->name instanceof Name ? $call->name->getLast() : '',
            default => '',
        };
        $name = strtolower($name);

        return str_starts_with($name, 'assert') || $name === 'expect';
    }

    /** Whether an expression is `$this`. */
    private static function isThis(Expr $expression): bool
    {
        return $expression instanceof Expr\Variable && $expression->name === 'this';
    }

    /**
     * One edge fact.
     *
     * @param array{path: string, start_line: int, end_line: int} $evidence
     * @return array<string, mixed>
     */
    public static function edge(string $kind, string $source, string $target, array $evidence): array
    {
        return ['kind' => $kind, 'source' => $source, 'target' => $target, 'origin' => 'ast', 'confidence' => 'certain', 'evidence' => $evidence, 'attributes' => (object) []];
    }
}

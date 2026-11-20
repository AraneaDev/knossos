<?php

declare(strict_types=1);

namespace KnossosPhpScanner\TestFacts;

use KnossosPhpScanner\FrameworkFactStore;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * The tests of a test file, as `test_case` nodes with a `contains` edge from
 * the file's module: each test's name, the calls it makes into production
 * and the modules they land in, and its assertions with where each
 * operand's value comes from, for the core to find the assertions that
 * cannot fail.
 *
 * Ported from Momus's `extractTestFunctions` and `extractAssertions`. A
 * test is a public method of a class whose name ends in `Test`, named
 * `test*`, marked `#[Test]` or documented `@test`; or a closure handed to
 * Pest's `it()` or `test()`. Its setups, `setUp()` and Pest's
 * `beforeEach()`, bind the values its operands are followed to. What makes
 * or configures a double is not read as the test's own code.
 */
final class TestCaseCollector extends NodeVisitorAbstract
{
    /** PHPUnit's attribute that marks a method a test. */
    private const TEST_ATTRIBUTE = 'phpunit\framework\attributes\test';

    private readonly ScopeStack $scopes;

    /** @var list<array{fn: Node\FunctionLike, name: string, line: int, node: Node}> in the order the file declares them */
    private array $tests = [];

    /** @var array<int, int> by function id, the index of the test it is */
    private array $testIndex = [];

    /** @var array<int, list<array{int, array{api: string, line: int, negated: bool, operands: list<Expr>}, Site}>> by test, each assertion with where its matcher is written */
    private array $assertions = [];

    private function __construct(private readonly TestFileIndex $file, private readonly string $relativePath)
    {
        $this->scopes = new ScopeStack();
    }

    /**
     * Read a test file's tests, against the doubles its `TestDoubleCollector` read.
     *
     * @param list<Node> $ast the file's syntax tree, its names resolved
     */
    public static function read(array $ast, string $root, string $relativePath, string $source, TestDoubleCollector $doubles): self
    {
        $file = new TestFileIndex($doubles, ProjectClasses::of($root, $relativePath), $source);
        (new NodeTraverser(new IndirectWrites($file)))->traverse($ast);
        $collector = new self($file, $relativePath);
        (new NodeTraverser($collector))->traverse($ast);

        return $collector;
    }

    /** Record the test, assertion, call, return or change a node is, as the traversal enters it. */
    #[\Override]
    public function enterNode(Node $node): ?int
    {
        $outer = $this->scopes->site();
        $this->scopes->enter($node);
        $site = $this->scopes->site();
        $this->file->declare($node, $site);
        $this->test($node, $site);
        if ($node instanceof Stmt\Return_ && $node->expr !== null) {
            $this->file->returned($node->expr, $site);
        } elseif ($node instanceof Stmt\Foreach_) {
            $this->writes([$node->keyVar, $node->valueVar], $site);
        } elseif ($node instanceof Stmt\Unset_) {
            $this->writes($node->vars, $site);
        } elseif ($node instanceof Stmt\ClassMethod && $site->inSetUp() && $site->classScope() !== null) {
            $this->file->setUp($node, $site->classScope());
        }
        if (ScopeStack::pestRole($node) === true && $node instanceof Expr\FuncCall) {
            foreach ($node->getArgs() as $argument) {
                if ($argument->value instanceof Node\FunctionLike) {
                    $this->file->setUp($argument->value, Site::PEST_SCOPE);
                }
            }
        }
        if (!$node instanceof Expr) {
            return null;
        }
        if ($this->file->doubles->configures($node, $site)) {
            // A spy assertion's `->with(...)` is configuration of the double; the assertion is inside it.
            for ($link = $node; $link instanceof Expr\MethodCall; $link = $link->var) {
                $this->assertion($link, $site);
            }

            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }
        $this->changes($node, $outer, $site);
        $this->assertion($node, $site);
        if ($node instanceof Expr\CallLike) {
            $this->file->call($node, $site);
        }

        return null;
    }

    /** Step out of the scope a node opened, so later sites are placed outside it. */
    #[\Override]
    public function leaveNode(Node $node): ?int
    {
        $this->scopes->leave($node);

        return null;
    }

    /** The canonical name of the test a node sits in, or null outside every test. */
    public function caseAround(Node $node): ?string
    {
        $position = $node->getStartFilePos();
        foreach ($this->tests as $test) {
            if ($test['fn']->getStartFilePos() <= $position && $position <= $test['fn']->getEndFilePos()) {
                return $this->canonical($test);
            }
        }

        return null;
    }

    /**
     * The file's tests as `test_case` nodes, each with a `contains` edge from
     * the module. Two tests that start on one line share a name: the first keeps it.
     *
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    public function facts(): array
    {
        $production = new ProductionCalls($this->file);
        $operands = new OperandSource($this->file, $production);
        $nodes = $edges = $emitted = [];
        foreach ($this->tests as $index => $test) {
            $canonical = $this->canonical($test);
            if (isset($emitted[$canonical])) {
                continue;
            }
            $emitted[$canonical] = true;
            [$count, $subjects] = $production->of($test['fn']);
            $assertions = $this->assertions[$index] ?? [];
            usort($assertions, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
            $id = 'php:test_case:' . $canonical;
            $evidence = FrameworkFactStore::evidenceIn($this->relativePath, $test['node']);
            $nodes[] = ['local_id' => $id, 'kind' => 'test_case', 'canonical_name' => $canonical, 'display_name' => $test['name'] === '' ? $canonical : $test['name'], 'origin' => 'ast', 'confidence' => 'certain', 'evidence' => $evidence, 'attributes' => (object) [
                'name' => $test['name'],
                'production_calls' => $count,
                'subjects' => $subjects,
                'assertions' => array_map(static fn(array $found): array => [
                    'api' => $found[1]['api'],
                    'line' => $found[1]['line'],
                    ...($found[1]['negated'] ? ['negated' => true] : []),
                    'operands' => array_map(static fn(Expr $operand): array => $operands->operand($operand, $found[2], $test['fn']), $found[1]['operands']),
                ], $assertions),
            ]];
            $edges[] = TestDoubleCollector::edge('contains', 'php:module:' . $this->relativePath, $id, $evidence);
        }

        return [$nodes, $edges];
    }

    /** Record a node that is a test: a PHPUnit test method, or Pest's `it()` or `test()` handed a closure. */
    private function test(Node $node, Site $site): void
    {
        if ($node instanceof Stmt\ClassMethod && self::isTestMethod($site->class, $node)) {
            $this->addTest($node, $node->name->toString(), $node);
        } elseif ($node instanceof Expr\FuncCall && ScopeStack::pestRole($node) === false && !$node->isFirstClassCallable()) {
            $arguments = $node->getArgs();
            foreach (array_slice($arguments, 1) as $argument) {
                if ($argument->value instanceof Expr\Closure || $argument->value instanceof Expr\ArrowFunction) {
                    $title = $arguments[0]->value;
                    $this->addTest($argument->value, $title instanceof Scalar\String_ ? $title->value : $this->file->text($title), $node);

                    return;
                }
            }
        }
    }

    /** Add a test, placed at the node that declares it. */
    private function addTest(Node\FunctionLike $function, string $name, Node $node): void
    {
        $this->testIndex[spl_object_id($function)] = count($this->tests);
        $this->tests[] = ['fn' => $function, 'name' => $name, 'line' => $node->getStartLine(), 'node' => $node];
    }

    /** Record an assertion made inside a test. */
    private function assertion(Expr $node, Site $site): void
    {
        $found = AssertionSyntax::of($node);
        if ($found === null) {
            return;
        }
        for ($index = count($site->functions) - 1; $index >= 0; --$index) {
            $test = $this->testIndex[spl_object_id($site->functions[$index])] ?? null;
            if ($test !== null) {
                $written = $node instanceof Expr\MethodCall ? $node->name->getStartFilePos() : $node->getStartFilePos();
                $this->assertions[$test][] = [$written, $found, $site];

                return;
            }
        }
    }

    /**
     * Note the variables and properties an expression changes other than by
     * one plain assignment: `++`, `.=`, a reference, a destructuring, a write
     * to an element or a property of it, an argument a callee may take by
     * reference or change, or a `$this` property a callback assigns, which
     * runs whenever the code it is handed calls it.
     */
    private function changes(Expr $node, Site $outer, Site $site): void
    {
        if ($node instanceof Expr\AssignOp || $node instanceof Expr\PreInc || $node instanceof Expr\PreDec || $node instanceof Expr\PostInc || $node instanceof Expr\PostDec) {
            $this->writes([$node->var], $site);
        } elseif ($node instanceof Expr\AssignRef) {
            $this->writes([$node->var, $node->expr], $site);
        } elseif ($node instanceof Expr\Assign && ($node->var instanceof Expr\List_ || $node->var instanceof Expr\Array_)) {
            $this->writes(array_map(static fn($item) => $item?->value, $node->var->items), $site);
        } elseif ($node instanceof Expr\Assign && (!$node->var instanceof Expr\Variable && !$node->var instanceof Expr\PropertyFetch || self::isDeferred($node, $site))) {
            $this->writes([$node->var], $site);
        } elseif ($node instanceof Expr\Closure) {
            $this->writes(array_map(static fn(Expr\ClosureUse $use): ?Expr => $use->byRef ? $use->var : null, $node->uses), $outer);
        } elseif ($node instanceof Expr\CallLike && !$node->isFirstClassCallable() && !AssertionSyntax::reads($node)) {
            $this->writes(array_map(static fn($argument): Expr => $argument->value, $node->getArgs()), $site);
        }
    }

    /**
     * Whether an assignment to a `$this` property, or through a property of
     * anything, runs at a time the source does not fix: in a closure or arrow
     * function that is not itself a Pest test or setup.
     */
    private static function isDeferred(Expr\Assign $node, Site $site): bool
    {
        if (!$node->var instanceof Expr\PropertyFetch) {
            return false;
        }
        $innermost = $site->functions === [] ? null : $site->functions[array_key_last($site->functions)];

        return !$node->var->var instanceof Expr\Variable || $node->var->var->name !== 'this'
            || (($innermost instanceof Expr\Closure || $innermost instanceof Expr\ArrowFunction) && $innermost !== $site->pestClosure);
    }

    /**
     * Note each target written.
     *
     * @param list<?Expr> $targets
     */
    private function writes(array $targets, Site $site): void
    {
        foreach ($targets as $target) {
            if ($target !== null) {
                $this->file->write($target, $site);
            }
        }
    }

    /**
     * A test's canonical name: its file, then the line it starts on.
     *
     * @param array{fn: Node\FunctionLike, name: string, line: int, node: Node} $test
     */
    private function canonical(array $test): string
    {
        return sprintf('%s#test:%d', $this->relativePath, $test['line']);
    }

    /** Whether a method is a PHPUnit test: public, in a class whose name ends in `Test`, and named, marked or documented as one. */
    private static function isTestMethod(?Stmt\ClassLike $class, Stmt\ClassMethod $method): bool
    {
        if (!$class instanceof Stmt\Class_ || $class->name === null || !str_ends_with($class->name->toString(), 'Test') || !$method->isPublic() || $method->isAbstract()) {
            return false;
        }
        if (str_starts_with($method->name->toString(), 'test')) {
            return true;
        }
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($attribute->name->toLowerString() === self::TEST_ATTRIBUTE) {
                    return true;
                }
            }
        }

        return preg_match('/(?:^|[\s*])@test(?=\s|\*|$)/m', $method->getDocComment()?->getText() ?? '') === 1;
    }
}

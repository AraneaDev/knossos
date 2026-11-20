<?php

declare(strict_types=1);

namespace KnossosPhpScanner\TestFacts;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

/**
 * Where a test file's values may change behind the source's back, read over
 * the whole file, configuring chains included, before any value is
 * followed. The rule is coarse on purpose: a missed tautology is fine, an
 * invented one is not.
 *
 * - A function whose locals may be written indirectly (`global`,
 *   `extract()`, `get_defined_vars()`, a one-argument `parse_str()`, a
 *   variable variable) proves no local's value.
 * - A class whose `$this` escapes proves no property's value. `$this`
 *   escapes when it is anything but the receiver of `->p` or `->m()`
 *   (assigned, passed, returned, captured, handed to an anonymous class),
 *   when it is used in a closure or arrow function, which binds it and runs
 *   whenever it is called, when a property name is computed (`$this->{$n}`),
 *   and when a method is called on it, through `$this->`, `parent::`,
 *   `self::` or `static::`, that the class does not declare and that is not
 *   one of PHPUnit's helpers: its body, in a trait or a parent, may write any
 *   property. A class whose parent is not PHPUnit's own `TestCase` escapes
 *   outright, as inherited code the file does not show may write its
 *   properties at any time. A Pest file is one class, and Pest's `test()`,
 *   which hands a helper the running test case, is an escape of it.
 * - A reference taken to a value (`[&$x]`, `foreach ($x as &$v)`) is a write
 *   of it; a by-reference `foreach` also writes its value variable, which the
 *   test-case pass reads as an assignment.
 */
final class IndirectWrites extends NodeVisitorAbstract
{
    /** The one parent whose code a test class may inherit without unseen writes to its properties. */
    private const PHPUNIT_TEST_CASE = 'phpunit\\framework\\testcase';

    /** Functions that may write any local of the function they are called in. */
    private const WRITES_LOCALS = ['extract', 'get_defined_vars', 'parse_str'];

    private readonly ScopeStack $scopes;

    /** @var array<int, true> the `$this` variables that are only the receiver of a property fetch or a method call */
    private array $receivers = [];

    public function __construct(private readonly TestFileIndex $file)
    {
        $this->scopes = new ScopeStack();
    }

    /** Note what a node says of indirect writes, as the traversal enters it. */
    #[\Override]
    public function enterNode(Node $node): ?int
    {
        $this->scopes->enter($node);
        $site = $this->scopes->site();
        $receiver = $node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch || $node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall;
        if ($receiver && $node->var instanceof Expr\Variable && $node->var->name === 'this') {
            $this->receivers[spl_object_id($node->var)] = true;
            if (!$node->name instanceof Node\Identifier || (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && !self::declares($site, $node->name->toString()))) {
                $this->file->escape($site->classScope());
            }
        }
        if ($node instanceof Expr\Variable && $node->name === 'this') {
            if (!isset($this->receivers[spl_object_id($node)]) || self::inCallback($site)) {
                $this->file->escape($site->classScope());
            }
        } elseif (($node instanceof Expr\Variable && !is_string($node->name)) || $node instanceof Stmt\Global_ || self::writesLocals($node)) {
            $this->file->taint($site);
        }
        if (self::inheritsUnseenCode($node) || self::callsOnSelf($node, $site)) {
            $this->file->escape($site->classScope());
        } elseif ($node instanceof Expr\FuncCall && $node->name instanceof Name && strtolower($node->name->getLast()) === 'test' && !$node->isFirstClassCallable() && $node->getArgs() === []) {
            $this->file->escape(Site::PEST_SCOPE);
        }
        if ($node instanceof Stmt\Foreach_ && $node->byRef) {
            $this->file->write($node->expr, $site);
        } elseif ($node instanceof Node\ArrayItem && $node->byRef) {
            $this->file->write($node->value, $site);
        }

        return null;
    }

    /** Step out of the scope a node opened. */
    #[\Override]
    public function leaveNode(Node $node): ?int
    {
        $this->scopes->leave($node);

        return null;
    }

    /** Whether a class extends anything but PHPUnit's own `TestCase`, whose inherited code the file does not show. */
    private static function inheritsUnseenCode(Node $node): bool
    {
        return $node instanceof Stmt\Class_ && $node->extends !== null && $node->extends->toLowerString() !== self::PHPUNIT_TEST_CASE;
    }

    /**
     * Whether a static-syntax call (`parent::`, `self::`, `static::`) runs a
     * method on `$this` whose body the file does not show: one the class does
     * not declare, as a trait's, and not one of PHPUnit's helpers.
     */
    private static function callsOnSelf(Node $node, Site $site): bool
    {
        if (!$node instanceof Expr\StaticCall || !$node->class instanceof Name || !$node->class->isSpecialClassName()) {
            return false;
        }
        if (!$node->name instanceof Node\Identifier) {
            return true;
        }

        return !self::declares($site, $node->name->toString());
    }

    /** Whether a method called on `$this` at a site has a body the file shows: declared by the class, or one of PHPUnit's helpers. */
    private static function declares(Site $site, string $method): bool
    {
        return ProductionCalls::isTestCaseHelper($method) || $site->class?->getMethod($method) !== null;
    }

    /** Whether a call may write any local of its function: `extract()`, `get_defined_vars()`, `parse_str()` with no result array. */
    private static function writesLocals(Node $node): bool
    {
        if (!$node instanceof Expr\FuncCall || !$node->name instanceof Name) {
            return false;
        }
        $name = strtolower($node->name->getLast());

        return in_array($name, self::WRITES_LOCALS, true) && ($name !== 'parse_str' || $node->isFirstClassCallable() || count($node->getArgs()) < 2);
    }

    /**
     * Whether code at a site runs in a closure or arrow function that binds
     * `$this` inside the method or Pest test it is written in: one that runs
     * whenever the code it is handed calls it.
     */
    private static function inCallback(Site $site): bool
    {
        for ($index = count($site->functions) - 1; $index >= 0; --$index) {
            $function = $site->functions[$index];
            if ($function instanceof Stmt\ClassMethod || $function === $site->pestClosure) {
                return false;
            }
            if ($function instanceof Expr\Closure || $function instanceof Expr\ArrowFunction) {
                return true;
            }
        }

        return false;
    }
}

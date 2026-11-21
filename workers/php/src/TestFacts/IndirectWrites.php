<?php

declare(strict_types=1);

namespace KnossosPhpScanner\TestFacts;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

/**
 * Where a test file's locals may change behind the source's back, read over
 * the whole file, configuring chains included, before any value is
 * followed. The rule is coarse on purpose: a missed tautology is fine, an
 * invented one is not.
 *
 * - A function whose locals may be written indirectly (`global`,
 *   `extract()`, `get_defined_vars()`, a one-argument `parse_str()`, a
 *   variable variable, an `include`/`require` or `eval`, whose code shares
 *   the function's locals) proves no local's value.
 * - A reference taken to a value (`[&$x]`, `foreach ($x as &$v)`, a closure's
 *   `use (&$x)`) is a write of it; a by-reference `foreach` also writes its
 *   value variable, which the test-case pass reads as an assignment. A
 *   closure's import is read here because the closure may sit in a double's
 *   configuration (`willReturnCallback(function () use (&$count) {...})`),
 *   which the test-case pass does not read as the test's own code.
 * - Inside a double's configuration, which the test-case pass skips, a
 *   variable handed to a call that is not a link of the chain is a write of
 *   it (`with(Mockery::capture($sql))`, `willReturn(array_shift($queue))`),
 *   and so is an `++`, `.=` or assignment there. A link's own arguments
 *   (`willReturn($value)`) are the pins it configures, and are left as read.
 *
 * A `$this` property needs no such reading: it is never followed to a
 * literal (`OperandSource::property()`).
 */
final class IndirectWrites extends NodeVisitorAbstract
{
    /** Functions that may write any local of the function they are called in. */
    private const WRITES_LOCALS = ['extract', 'get_defined_vars', 'parse_str'];

    private readonly ScopeStack $scopes;

    /** The outermost expression of the double configuration the traversal is in; null outside one. */
    private ?Expr $configuration = null;

    public function __construct(private readonly TestFileIndex $file)
    {
        $this->scopes = new ScopeStack();
    }

    /** Note what a node says of indirect writes, as the traversal enters it. */
    #[\Override]
    public function enterNode(Node $node): ?int
    {
        // A site is built only for a node that needs one: most nodes write nothing.
        $outer = $node instanceof Expr\Closure ? $this->scopes->site() : null;
        $this->scopes->enter($node);
        if (($node instanceof Expr\Variable && !is_string($node->name)) || $node instanceof Stmt\Global_ || $node instanceof Expr\Include_ || $node instanceof Expr\Eval_ || self::writesLocals($node)) {
            $this->file->taint($this->scopes->site());
        }
        if ($node instanceof Stmt\Foreach_ && $node->byRef) {
            $this->file->write($node->expr, $this->scopes->site());
        } elseif ($node instanceof Node\ArrayItem && $node->byRef) {
            $this->file->write($node->value, $this->scopes->site());
        } elseif ($outer !== null && $node instanceof Expr\Closure) {
            foreach ($node->uses as $use) {
                if ($use->byRef) {
                    $this->file->write($use->var, $outer);
                }
            }
        }
        if ($this->configuration !== null) {
            $this->configurationWrites($node);
        } elseif ($node instanceof Expr\CallLike && $this->file->doubles->configures($node, $this->scopes->site())) {
            $this->configuration = $node;
        }

        return null;
    }

    /**
     * Note what a node inside a double's configuration writes: the variables
     * handed to a call that is not a link of the chain, and the target of an
     * `++`, `.=` or assignment.
     */
    private function configurationWrites(Node $node): void
    {
        if ($node instanceof Expr\AssignOp || $node instanceof Expr\Assign || $node instanceof Expr\AssignRef || $node instanceof Expr\PreInc || $node instanceof Expr\PreDec || $node instanceof Expr\PostInc || $node instanceof Expr\PostDec) {
            $this->file->write($node->var, $this->scopes->site());
        } elseif ($node instanceof Expr\CallLike && !$node->isFirstClassCallable() && !$this->file->doubles->configures($node, $site = $this->scopes->site())) {
            foreach ($node->getArgs() as $argument) {
                $this->file->write($argument->value, $site);
            }
        }
    }

    /** Step out of the scope a node opened. */
    #[\Override]
    public function leaveNode(Node $node): ?int
    {
        $this->scopes->leave($node);
        if ($node === $this->configuration) {
            $this->configuration = null;
        }

        return null;
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
}

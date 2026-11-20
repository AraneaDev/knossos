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
 *   variable variable) proves no local's value.
 * - A reference taken to a value (`[&$x]`, `foreach ($x as &$v)`) is a write
 *   of it; a by-reference `foreach` also writes its value variable, which the
 *   test-case pass reads as an assignment.
 *
 * A `$this` property needs no such reading: it is never followed to a
 * literal (`OperandSource::property()`).
 */
final class IndirectWrites extends NodeVisitorAbstract
{
    /** Functions that may write any local of the function they are called in. */
    private const WRITES_LOCALS = ['extract', 'get_defined_vars', 'parse_str'];

    private readonly ScopeStack $scopes;

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
        if (($node instanceof Expr\Variable && !is_string($node->name)) || $node instanceof Stmt\Global_ || self::writesLocals($node)) {
            $this->file->taint($site);
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

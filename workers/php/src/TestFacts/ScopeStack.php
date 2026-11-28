<?php

declare(strict_types=1);

namespace KnossosPhpScanner\TestFacts;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

/**
 * The functions, class and method a traversal of a test file is in, and the
 * Pest closures among those functions, kept as the traversal enters and
 * leaves nodes, so that every collector reading the file places an
 * expression at the same `Site`.
 */
final class ScopeStack
{
    /** Pest's functions that run a closure as a test, and the one that runs a closure before each test. */
    private const PEST = ['it' => false, 'test' => false, 'beforeeach' => true];

    /** @var list<Node\FunctionLike> */
    private array $functions = [];

    /** @var list<Stmt\ClassLike> */
    private array $classes = [];

    /** @var list<Stmt\ClassMethod> */
    private array $methods = [];

    /** @var array<int, bool> Pest's test and setup closures by id, true for a `beforeEach()` */
    private array $pest = [];

    /** Note the function, class or method the traversal enters, and the closures a Pest call is handed. */
    public function enter(Node $node): void
    {
        $role = self::pestRole($node);
        // A first-class callable (`test(...)`) holds a `VariadicPlaceholder`, not arguments, and is handed no closure.
        if ($role !== null && $node instanceof Expr\FuncCall && !$node->isFirstClassCallable()) {
            foreach ($node->getArgs() as $argument) {
                if ($argument->value instanceof Expr\Closure || $argument->value instanceof Expr\ArrowFunction) {
                    $this->pest[spl_object_id($argument->value)] = $role;
                }
            }
        }
        if ($node instanceof Node\FunctionLike) {
            $this->functions[] = $node;
        }
        if ($node instanceof Stmt\ClassLike) {
            $this->classes[] = $node;
        } elseif ($node instanceof Stmt\ClassMethod) {
            $this->methods[] = $node;
        }
    }

    /** Leave the function, class or method the traversal was in. */
    public function leave(Node $node): void
    {
        if ($node instanceof Node\FunctionLike) {
            array_pop($this->functions);
        }
        if ($node instanceof Stmt\ClassLike) {
            array_pop($this->classes);
        } elseif ($node instanceof Stmt\ClassMethod) {
            array_pop($this->methods);
        }
    }

    /** Where the traversal is now. */
    public function site(): Site
    {
        $pest = null;
        for ($index = count($this->functions) - 1; $index >= 0 && $pest === null; --$index) {
            $pest = isset($this->pest[spl_object_id($this->functions[$index])]) ? $this->functions[$index] : null;
        }

        return new Site(
            $this->functions,
            $this->classes === [] ? null : $this->classes[array_key_last($this->classes)],
            $this->methods === [] ? null : $this->methods[array_key_last($this->methods)],
            $pest,
            $pest !== null && $this->pest[spl_object_id($pest)],
        );
    }

    /** For a call to Pest's `it()` or `test()`, false; for `beforeEach()`, true; null for any other node. */
    public static function pestRole(Node $node): ?bool
    {
        if (!$node instanceof Expr\FuncCall || !$node->name instanceof Name) {
            return null;
        }

        return self::PEST[strtolower($node->name->getLast())] ?? null;
    }
}

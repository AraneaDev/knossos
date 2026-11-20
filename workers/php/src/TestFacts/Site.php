<?php

declare(strict_types=1);

namespace KnossosPhpScanner\TestFacts;

use PhpParser\Node\Expr;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt;

/**
 * Where in a test file an expression sits: the functions around it, innermost
 * last, and the class and method it is in. What a variable or a `$this`
 * property names depends on all three.
 */
final readonly class Site
{
    /**
     * @param list<FunctionLike> $functions
     */
    public function __construct(
        public array $functions,
        public ?Stmt\ClassLike $class,
        public ?Stmt\ClassMethod $method,
    ) {}

    /**
     * The scope a local variable of that name belongs to here, as a key.
     *
     * An arrow function sees its parent's variables, and so does a closure for
     * the ones it imports with `use`; each owns its own parameters. A method,
     * a function or any other closure is a scope of its own, and the file is
     * the scope outside them all, where Pest writes its tests.
     */
    public function variableScope(string $name): string
    {
        for ($index = count($this->functions) - 1; $index >= 0; --$index) {
            $function = $this->functions[$index];
            if (self::declaresParameter($function, $name)) {
                return 'f' . spl_object_id($function);
            }
            if ($function instanceof Expr\ArrowFunction || ($function instanceof Expr\Closure && self::imports($function, $name))) {
                continue;
            }

            return 'f' . spl_object_id($function);
        }

        return 'file';
    }

    /** The class whose `$this` properties are in scope here, as a key; null outside a class. */
    public function classScope(): ?string
    {
        return $this->class === null ? null : 'c' . spl_object_id($this->class);
    }

    /** Whether the code here runs in the class's `setUp()`, which every test of the class runs first. */
    public function inSetUp(): bool
    {
        return $this->method !== null && strtolower($this->method->name->toString()) === 'setup';
    }

    /** Whether a function declares a parameter of that name. */
    private static function declaresParameter(FunctionLike $function, string $name): bool
    {
        foreach ($function->getParams() as $param) {
            if ($param->var instanceof Expr\Variable && $param->var->name === $name) {
                return true;
            }
        }

        return false;
    }

    /** Whether a closure imports a variable of that name from its parent with `use`. */
    private static function imports(Expr\Closure $closure, string $name): bool
    {
        foreach ($closure->uses as $use) {
            if ($use->var->name === $name) {
                return true;
            }
        }

        return false;
    }
}

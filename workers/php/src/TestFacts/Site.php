<?php

declare(strict_types=1);

namespace KnossosPhpScanner\TestFacts;

use PhpParser\Node\Expr;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt;

/**
 * Where in a test file an expression sits: the functions around it, innermost
 * last, the class and method it is in, and the Pest test or `beforeEach()`
 * closure around it. What a variable or a `$this` property names depends on
 * all of them: in a Pest file, `$this` is the test case each closure is
 * bound to, and what `beforeEach()` sets on it every test of the file sees.
 */
final readonly class Site
{
    /** The class scope of a Pest file: the test case every closure of the file is bound to. */
    public const PEST_SCOPE = 'pest';

    /**
     * @param list<FunctionLike> $functions
     */
    public function __construct(
        public array $functions,
        public ?Stmt\ClassLike $class,
        public ?Stmt\ClassMethod $method,
        public ?FunctionLike $pestClosure = null,
        public bool $pestSetUp = false,
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

    /** The class whose `$this` properties are in scope here, as a key, or the Pest file's test case; null outside both. */
    public function classScope(): ?string
    {
        if ($this->class !== null) {
            return 'c' . spl_object_id($this->class);
        }

        return $this->pestClosure === null ? null : self::PEST_SCOPE;
    }

    /** The method, or else the Pest closure, the code here runs in, as an id; 0 outside both. */
    public function ownerId(): int
    {
        $owner = $this->method ?? $this->pestClosure;

        return $owner === null ? 0 : spl_object_id($owner);
    }

    /** Whether the code here runs in the class's `setUp()` or a Pest `beforeEach()`, which every test runs first. */
    public function inSetUp(): bool
    {
        if ($this->method !== null) {
            return strtolower($this->method->name->toString()) === 'setup';
        }

        return $this->pestClosure !== null && $this->pestSetUp;
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

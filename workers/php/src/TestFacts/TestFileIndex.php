<?php

declare(strict_types=1);

namespace KnossosPhpScanner\TestFacts;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

/**
 * What a test file says about its own code, for reading its tests: the
 * calls each function makes and the values it returns, the helper methods
 * and functions it declares, the classes it declares, and the variables and
 * `$this` properties it changes other than by a plain assignment.
 *
 * Its doubles and their bindings come from the file's `TestDoubleCollector`,
 * and where a class it names lives from `ProjectClasses`.
 */
final class TestFileIndex
{
    /** @var array<int, list<array{Expr, Site}>> by function id, the calls made in it or in a function inside it */
    private array $calls = [];

    /** @var array<int, list<array{Expr, Site}>> by function id, the values its own `return` statements hand back */
    private array $returns = [];

    /** @var array<int, array<string, Stmt\ClassMethod>> by class id, its methods by lower-case name */
    private array $methods = [];

    /** @var array<string, Stmt\Function_> by lower-case fully qualified name */
    private array $functions = [];

    /** @var array<string, true> lower-case fully qualified names of the classes the file declares */
    private array $declared = [];

    /** @var array<string, true> variables and properties written other than by one plain assignment, by binding key */
    private array $written = [];

    public function __construct(
        public readonly TestDoubleCollector $doubles,
        public readonly ProjectClasses $classes,
        private readonly string $source,
    ) {}

    /** Note a class, method or function the file declares. */
    public function declare(Node $node, Site $site): void
    {
        if ($node instanceof Stmt\ClassLike && $node->namespacedName !== null) {
            $this->declared[$node->namespacedName->toLowerString()] = true;
        } elseif ($node instanceof Stmt\ClassMethod && $site->class !== null) {
            $this->methods[spl_object_id($site->class)][$node->name->toLowerString()] = $node;
        } elseif ($node instanceof Stmt\Function_ && $node->namespacedName !== null) {
            $this->functions[$node->namespacedName->toLowerString()] = $node;
        }
    }

    /** Note a call made at a site, for every function it is made in. */
    public function call(Expr $call, Site $site): void
    {
        foreach ($site->functions as $function) {
            $this->calls[spl_object_id($function)][] = [$call, $site];
        }
    }

    /** Note a value the innermost function at a site returns. */
    public function returned(Expr $value, Site $site): void
    {
        if ($site->functions !== []) {
            $this->returns[spl_object_id($site->functions[array_key_last($site->functions)])][] = [$value, $site];
        }
    }

    /** Note a variable or `$this` property changed other than by a plain assignment: `++`, `.=`, by reference. */
    public function write(Expr $target, Site $site): void
    {
        $key = self::key($target, $site);
        if ($key !== null) {
            $this->written[$key] = true;
        }
    }

    /**
     * The calls made in a function, and in the functions inside it.
     *
     * @return list<array{Expr, Site}>
     */
    public function callsIn(Node\FunctionLike $function): array
    {
        return $this->calls[spl_object_id($function)] ?? [];
    }

    /**
     * The values a function's own `return` statements hand back.
     *
     * @return list<array{Expr, Site}>
     */
    public function returnsOf(Node\FunctionLike $function): array
    {
        return $this->returns[spl_object_id($function)] ?? [];
    }

    /**
     * The helper a call runs, when the file declares it: a method of the
     * class the call is made in, through `$this`, `self::` or `static::`, or
     * a function of the file.
     */
    public function helper(Expr $call, Site $site): ?Node\FunctionLike
    {
        if ($call instanceof Expr\FuncCall && $call->name instanceof Name) {
            $resolved = $call->name->getAttribute('namespacedName');
            $name = $resolved instanceof Name ? $resolved->toLowerString() : $call->name->toLowerString();

            return $this->functions[$name] ?? $this->functions[$call->name->toLowerString()] ?? null;
        }
        $onThis = ($call instanceof Expr\MethodCall || $call instanceof Expr\NullsafeMethodCall) && $call->var instanceof Expr\Variable && $call->var->name === 'this';
        $onSelf = $call instanceof Expr\StaticCall && $call->class instanceof Name && in_array($call->class->toLowerString(), ['self', 'static'], true);
        if (!($onThis || $onSelf) || $site->class === null || !$call->name instanceof Identifier) {
            return null;
        }

        return $this->methods[spl_object_id($site->class)][$call->name->toLowerString()] ?? null;
    }

    /**
     * What a variable or `$this` property read at a site was assigned, when
     * the source states one value for it: one plain assignment in its scope,
     * and no other change. Null when it holds no value the source can prove.
     *
     * @return array{Expr, Site}|null
     */
    public function stable(Expr $leaf, Site $site): ?array
    {
        $key = self::key($leaf, $site);
        if ($key === null || isset($this->written[$key])) {
            return null;
        }
        $bindings = $this->doubles->bindings();
        if ($leaf instanceof Expr\Variable && is_string($leaf->name)) {
            $value = $bindings->variableWrites($leaf->name, $site) === 1 ? $bindings->variable($leaf->name, $site, $leaf->getStartFilePos()) : null;
        } elseif ($leaf instanceof Expr\PropertyFetch && $leaf->name instanceof Identifier) {
            $value = $bindings->propertyWrites($leaf->name->toString(), $site) === 1 ? $bindings->property($leaf->name->toString(), $site, $leaf->getStartFilePos()) : null;
        } else {
            $value = null;
        }

        return is_array($value) ? $value : null;
    }

    /** Whether the file declares a class of that name itself, so it is a fixture of the test rather than code under test. */
    public function declares(string $class): bool
    {
        return isset($this->declared[strtolower(ltrim($class, '\\'))]);
    }

    /** An expression's source text, as written. */
    public function text(Node $node): string
    {
        return substr($this->source, $node->getStartFilePos(), $node->getEndFilePos() - $node->getStartFilePos() + 1);
    }

    /** The key a variable or `$this` property is bound under at a site; null for anything else. */
    private static function key(Expr $target, Site $site): ?string
    {
        if ($target instanceof Expr\Variable && is_string($target->name) && $target->name !== 'this') {
            return $site->variableScope($target->name) . '$' . $target->name;
        }
        if ($target instanceof Expr\PropertyFetch && $target->var instanceof Expr\Variable && $target->var->name === 'this' && $target->name instanceof Identifier) {
            $class = $site->classScope();

            return $class === null ? null : $class . '->' . $target->name->toString();
        }

        return null;
    }
}

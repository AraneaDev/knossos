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

    /** @var array<string, true> function scopes (as `Site::variableScope()` keys them) where a local may be written indirectly */
    private array $tainted = [];

    /** @var array<string, true> class scopes whose `$this` escapes, so any code may write its properties */
    private array $escaped = [];

    /** @var array<string, list<array{int, int}>> by class scope, the source ranges of the setups every test of it runs first */
    private array $setUps = [];

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

    /**
     * Note that the functions at a site may write their locals indirectly:
     * a by-reference `foreach`, `global`, `extract()`, a variable variable.
     * No local of theirs holds a value the source proves.
     */
    public function taint(Site $site): void
    {
        if ($site->functions === []) {
            $this->tainted['file'] = true;
        }
        foreach ($site->functions as $function) {
            $this->tainted['f' . spl_object_id($function)] = true;
        }
    }

    /** Note that `$this` escapes a class scope (`Site::classScope()`, null for none), so code outside it may write any of its properties. */
    public function escape(?string $scope): void
    {
        if ($scope !== null) {
            $this->escaped[$scope] = true;
        }
    }

    /** Note a setup of a class scope (`Site::classScope()`): `setUp()`, or a Pest `beforeEach()` closure, whose pins every test of its class or file sees. */
    public function setUp(Node\FunctionLike $setUp, string $scope): void
    {
        $this->setUps[$scope][] = [$setUp->getStartFilePos(), $setUp->getEndFilePos()];
    }

    /** Whether code at a position runs before an assertion of a test: it is in the test itself or in a setup of its class or file. */
    public function reaches(int $position, Node\FunctionLike $test, Site $site): bool
    {
        if ($test->getStartFilePos() <= $position && $position <= $test->getEndFilePos()) {
            return true;
        }
        foreach ($this->setUps[$site->classScope() ?? ''] ?? [] as [$start, $end]) {
            if ($start <= $position && $position <= $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Note a variable or `$this` property changed other than by a plain
     * assignment: `++`, `.=`, by reference, through an element or a property
     * of it (`$x[] = 1`, `unset($x['k'])`), or handed to a call.
     */
    public function write(Expr $target, Site $site): void
    {
        while ($target instanceof Expr\ArrayDimFetch || $target instanceof Expr\NullsafePropertyFetch || ($target instanceof Expr\PropertyFetch && !self::isThisProperty($target))) {
            $target = $target->var;
        }
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
        if ($key === null || isset($this->written[$key]) || isset($this->tainted[explode('$', $key)[0]]) || isset($this->escaped[explode('->', $key)[0]])) {
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

    /** Whether a property fetch is a named property of `$this`. */
    private static function isThisProperty(Expr\PropertyFetch $fetch): bool
    {
        return $fetch->var instanceof Expr\Variable && $fetch->var->name === 'this' && $fetch->name instanceof Identifier;
    }

    /** The key a variable or `$this` property is bound under at a site; null for anything else. */
    private static function key(Expr $target, Site $site): ?string
    {
        if ($target instanceof Expr\Variable && is_string($target->name) && $target->name !== 'this') {
            return $site->variableScope($target->name) . '$' . $target->name;
        }
        if ($target instanceof Expr\PropertyFetch && self::isThisProperty($target) && $target->name instanceof Identifier) {
            $class = $site->classScope();

            return $class === null ? null : $class . '->' . $target->name->toString();
        }

        return null;
    }
}

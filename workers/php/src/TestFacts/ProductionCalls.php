<?php

declare(strict_types=1);

namespace KnossosPhpScanner\TestFacts;

use PhpParser\Node\Expr;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

/**
 * What a test runs of the code under test: how many calls it makes into
 * production, and the project modules those calls land in (its subjects).
 * Ported from Momus's production-call count, after the TypeScript worker's
 * `test-case-production.js`.
 *
 * A call counts when it constructs a class or calls a static method of one,
 * when it is made on a variable or `$this` property bound to `new X()`, or
 * when it is a method the test case inherits (Laravel's `$this->get('/')`)
 * or a function that is not PHP's own; a helper the file declares counts
 * what it calls. A class or function of PHP or of a test framework, a class
 * the test file declares, one in a test module, and anything that makes or
 * configures a double runs no code under test.
 *
 * A subject is the file a counted class is declared in, when composer's
 * PSR-4 maps place it in the project's product code.
 */
final class ProductionCalls
{
    /** Methods of PHPUnit's TestCase that assert, expect, build a matcher or skip, rather than run code under test. */
    private const TEST_CASE_HELPERS = '/^(?:assert|expect|mark|fail|getmock|createmock|createstub|createconfiguredmock|createpartialmock|prophesize|atleast|atmost|returnvalue|returncallback|returnself|returnargument|onconsecutivecalls|throwexception|identicalto|equalto|isinstanceof|istype|logical|(?:once|never|any|exactly|callback|anything|setup|teardown)$)/';

    /** Functions of the test frameworks: they define, configure or assert tests. */
    private const FRAMEWORK_FUNCTIONS = ['expect', 'it', 'test', 'describe', 'beforeeach', 'aftereach', 'beforeall', 'afterall', 'uses', 'dataset', 'mock', 'todo', 'pest', 'covers'];

    public function __construct(private readonly TestFileIndex $file) {}

    /**
     * The production calls a test makes and the subjects they land in, sorted.
     *
     * @return array{int, list<string>}
     */
    public function of(FunctionLike $test): array
    {
        $count = 0;
        $subjects = [];
        $seen = [spl_object_id($test) => true];
        $pending = [$test];
        while (($function = array_pop($pending)) !== null) {
            foreach ($this->file->callsIn($function) as [$call, $site]) {
                $landed = $this->landing($call, $site);
                if ($landed instanceof FunctionLike) {
                    if (!isset($seen[spl_object_id($landed)])) {
                        $seen[spl_object_id($landed)] = true;
                        $pending[] = $landed;
                    }
                } elseif ($landed !== null) {
                    ++$count;
                    if ($landed[0] !== null) {
                        $subjects[$landed[0]] = true;
                    }
                }
            }
        }
        $subjects = array_keys($subjects);
        sort($subjects, SORT_STRING);

        return [$count, $subjects];
    }

    /** Whether a call is code under test: it lands in production. */
    public function runsProduction(Expr $call, Site $site): bool
    {
        return is_array($this->landing($call, $site));
    }

    /** Whether a class is code the test exercises: not PHP's, a framework's, the test file's own or a test module's. */
    public function isProductionClass(string $class): bool
    {
        return !ProjectClasses::isOutsideTheProject($class) && !$this->file->declares($class) && !$this->file->classes->isTestClass($class);
    }

    /**
     * Where a call lands: a helper of the file, whose calls count in its
     * place; production, with the module it lands in when the project's;
     * or null when it runs no code under test.
     *
     * @return FunctionLike|array{?string}|null
     */
    private function landing(Expr $call, Site $site): FunctionLike|array|null
    {
        $helper = $this->file->helper($call, $site);
        if ($helper !== null) {
            return $helper;
        }

        return match (true) {
            $call instanceof Expr\New_ => $call->class instanceof Name ? $this->ofClass($call->class) : null,
            $call instanceof Expr\StaticCall => $this->staticCall($call),
            $call instanceof Expr\MethodCall, $call instanceof Expr\NullsafeMethodCall => $this->methodCall($call, $site),
            $call instanceof Expr\FuncCall => $this->functionCall($call),
            default => null,
        };
    }

    /**
     * A static call: on the test case itself, a method it inherits; on a
     * facade the test swapped for a double, none; else the class's.
     *
     * @return array{?string}|null
     */
    private function staticCall(Expr\StaticCall $call): ?array
    {
        if (!$call->class instanceof Name) {
            return null;
        }
        if ($call->class->isSpecialClassName()) {
            return $this->inherited($call->name);
        }

        return $this->file->doubles->facade($call->class->toString()) === null ? $this->ofClass($call->class) : null;
    }

    /**
     * A method call: on `$this`, a method the test case inherits; else one
     * on a variable or property bound to a class the test constructs.
     *
     * @return array{?string}|null
     */
    private function methodCall(Expr\MethodCall|Expr\NullsafeMethodCall $call, Site $site): ?array
    {
        if ($call->var instanceof Expr\Variable && $call->var->name === 'this') {
            return $this->inherited($call->name);
        }
        $root = self::bindingRoot($call->var);
        if ($root === null || $this->file->doubles->held($root, $site) !== null) {
            return null;
        }
        $bindings = $this->file->doubles->bindings();
        $at = $root->getStartFilePos();
        $bound = match (true) {
            $root instanceof Expr\Variable && is_string($root->name) => $bindings->variable($root->name, $site, $at),
            $root instanceof Expr\PropertyFetch && $root->name instanceof Identifier => $bindings->property($root->name->toString(), $site, $at),
            default => null,
        };
        $value = is_array($bound) ? $bound[0] : null;

        return $value instanceof Expr\New_ && $value->class instanceof Name ? $this->ofClass($value->class) : null;
    }

    /**
     * A function call: none for PHP's own or a test framework's.
     *
     * @return array{?string}|null
     */
    private function functionCall(Expr\FuncCall $call): ?array
    {
        if (!$call->name instanceof Name) {
            return null;
        }
        $name = $call->name->getLast();
        if (in_array(strtolower($name), self::FRAMEWORK_FUNCTIONS, true) || str_starts_with(strtolower($name), 'assert') || ProjectClasses::isInternalFunction($call->name->toString()) || ProjectClasses::isInternalFunction($name)) {
            return null;
        }

        return [null];
    }

    /**
     * A method the test case inherits rather than declares: production, in
     * a module no source states, unless it is one of PHPUnit's own helpers.
     *
     * @return array{?string}|null
     */
    private function inherited(Identifier|Expr $name): ?array
    {
        return $name instanceof Identifier && !self::isTestCaseHelper($name->toString()) ? [null] : null;
    }

    /** Whether a method is one of PHPUnit's own TestCase helpers, which asserts, expects, builds a matcher or skips. */
    public static function isTestCaseHelper(string $method): bool
    {
        return preg_match(self::TEST_CASE_HELPERS, strtolower($method)) === 1;
    }

    /**
     * A call into a named class: production, landing in its module, unless the class runs no code under test.
     *
     * @return array{?string}|null
     */
    private function ofClass(Name $class): ?array
    {
        if ($class->isSpecialClassName() || !$this->isProductionClass($class->toString())) {
            return null;
        }

        return [$this->file->classes->moduleOf($class->toString())];
    }

    /**
     * The variable or `$this` property a chain of calls and fetches is made
     * on; null when it starts anywhere else, such as on a call of its own.
     */
    private static function bindingRoot(Expr $chain): Expr\Variable|Expr\PropertyFetch|null
    {
        $current = $chain;
        for (;;) {
            if ($current instanceof Expr\PropertyFetch && $current->var instanceof Expr\Variable && $current->var->name === 'this') {
                return $current;
            }
            if ($current instanceof Expr\Variable) {
                return $current->name === 'this' ? null : $current;
            }
            if (!($current instanceof Expr\PropertyFetch || $current instanceof Expr\NullsafePropertyFetch || $current instanceof Expr\ArrayDimFetch)) {
                return null;
            }
            $current = $current->var;
        }
    }
}

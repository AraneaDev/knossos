<?php

declare(strict_types=1);

namespace KnossosPhpScanner\TestFacts;

use PhpParser\Node\Expr;

/**
 * What the variables and `$this` properties of a test file hold, by where
 * they are assigned.
 *
 * A local variable holds what it was last assigned before the line that reads
 * it, in its own scope. A `$this` property assigned in a test method holds it
 * for the rest of that method; assigned in `setUp()`, it holds it in every
 * method of the class, which is how one double serves every test. A Pest
 * `beforeEach()` is the setUp() of its file.
 *
 * A binding is either a double outright (a closure parameter that Mockery
 * hands the double it is making) or the expression assigned, read later
 * once every double of the file is known.
 *
 * @phpstan-type Value DoubleRecord|array{Expr, Site}
 */
final class Bindings
{
    /** @var array<string, list<array{int, Value}>> by scope and name, each with the position it holds from */
    private array $variables = [];

    /** @var array<string, list<array{int, int, bool, Value}>> by class and property: position, method id, whether in `setUp()`, value */
    private array $properties = [];

    /** @var array<string, true> class scopes whose properties code the file does not show may assign: a class using a trait, a Pest file calling `uses()` or handing out `test()` */
    private array $opaque = [];

    /**
     * Bind a local variable from a position on.
     *
     * @param Value $value
     */
    public function bindVariable(string $scope, string $name, int $from, DoubleRecord|array $value): void
    {
        $this->variables[$scope . '$' . $name][] = [$from, $value];
    }

    /**
     * Bind the `$this` property assigned at a site, from a position on.
     *
     * @param Value $value
     */
    public function bindProperty(Site $site, string $name, int $from, DoubleRecord|array $value): void
    {
        $class = $site->classScope();
        if ($class !== null) {
            $this->properties[$class . '->' . $name][] = [$from, self::methodId($site), $site->inSetUp(), $value];
        }
    }

    /**
     * What a local variable read at a position holds, or null when nothing bound it.
     *
     * @return Value|null
     */
    public function variable(string $name, Site $site, int $at): DoubleRecord|array|null
    {
        $found = null;
        $latest = -1;
        foreach ($this->variables[$site->variableScope($name) . '$' . $name] ?? [] as [$from, $value]) {
            if ($from < $at && $from > $latest) {
                [$found, $latest] = [$value, $from];
            }
        }

        return $found;
    }

    /**
     * What a `$this` property read at a site holds: its latest assignment
     * earlier in the same method, else its latest in `setUp()`. A setup sees
     * what an earlier one assigned, as Pest runs a file's `beforeEach()` in order.
     *
     * @return Value|null
     */
    public function property(string $name, Site $site, int $at): DoubleRecord|array|null
    {
        $class = $site->classScope();
        if ($class === null) {
            return null;
        }
        $method = self::methodId($site);
        $local = $setUp = null;
        $localFrom = $setUpFrom = -1;
        foreach ($this->properties[$class . '->' . $name] ?? [] as [$from, $assignedIn, $inSetUp, $value]) {
            if ($assignedIn === $method && $from < $at && $from > $localFrom) {
                [$local, $localFrom] = [$value, $from];
            } elseif ($inSetUp && (!$site->inSetUp() || $from < $at) && $from > $setUpFrom) {
                [$setUp, $setUpFrom] = [$value, $from];
            }
        }

        return $local ?? $setUp;
    }

    /** How many assignments bind a local variable in the scope a site reads it in. */
    public function variableWrites(string $name, Site $site): int
    {
        return count($this->variables[$site->variableScope($name) . '$' . $name] ?? []);
    }

    /** Note a class scope (`Site::classScope()`, null for none) whose properties code the file does not show may assign. */
    public function opaque(?string $class): void
    {
        if ($class !== null) {
            $this->opaque[$class] = true;
        }
    }

    /**
     * Whether exactly one method (or Pest closure) assigns a `$this`
     * property, in a class whose every assignment the file shows: no other
     * method, helper, trait or `test()` caller may have replaced what it holds.
     */
    public function soleProperty(string $name, Site $site): bool
    {
        $class = $site->classScope();
        if ($class === null || isset($this->opaque[$class])) {
            return false;
        }

        return count(array_unique(array_map(static fn(array $binding): int => $binding[1], $this->properties[$class . '->' . $name] ?? []))) === 1;
    }

    /** How many assignments bind a `$this` property anywhere in the class a site reads it in. */
    public function propertyWrites(string $name, Site $site): int
    {
        $class = $site->classScope();

        return $class === null ? 0 : count($this->properties[$class . '->' . $name] ?? []);
    }

    /** The method or Pest closure a site is in, as an id; 0 outside both. */
    private static function methodId(Site $site): int
    {
        return $site->ownerId();
    }
}

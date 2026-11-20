<?php

declare(strict_types=1);

namespace KnossosPhpScanner\TestFacts;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\NodeFinder;

/**
 * Where an assertion's operands get their values, inside one test: from a
 * stub's pinned value (`mock-config`), a double itself (`mock-call`), the
 * code under test (`production`), a literal, a parameter of the test (a
 * data provider's or a Pest dataset's row), or somewhere the source does not
 * say (`unknown`). Ported from Momus's PHP operand reading, after the
 * TypeScript worker's `test-case-flow.js`.
 *
 * A local variable is followed to the value it was assigned only when the
 * source states one value for it. A `$this` property is never a literal: it
 * is a double it holds, code under test it was assigned in the test or in
 * `setUp()` / `beforeEach()`, or unknown. A helper the file declares hands back the kind of source
 * its one `return` has, never a literal: a literal one branch returns says
 * nothing of the value at the assertion.
 */
final class OperandSource
{
    /** How deep one binding or helper may lead to another before the reading stops. */
    private const MAX_DEPTH = 16;

    public function __construct(
        private readonly TestFileIndex $file,
        private readonly ProductionCalls $production,
    ) {}

    /**
     * An operand on the wire: `{source, text?, value?, double?, unconfigured_spy?}`.
     *
     * It reads as written unless evaluating it again could give another
     * value (a call, a `new`, a closure): then no text can show it equal to
     * itself. A stub's operand also carries the value it was pinned to.
     *
     * @return array<string, mixed>
     */
    public function operand(Expr $operand, Site $site, FunctionLike $test): array
    {
        [$source, $double, $value] = $this->provenance($operand, $site, $test, 0);
        $wire = ['source' => $source];
        if (!self::reevaluates($operand)) {
            $wire['text'] = $this->file->text($operand);
        }
        if ($value !== null) {
            $wire['value'] = $value;
        }
        if ($double !== null) {
            $wire['double'] = $this->file->doubles->canonicalOf($double);
            if ($double->isUnconfiguredSpy()) {
                $wire['unconfigured_spy'] = true;
            }
        }

        return $wire;
    }

    /**
     * The source of an expression's value, the double it comes from, and for
     * a stub's pinned value, its text.
     *
     * @return array{string, ?DoubleRecord, ?string}
     */
    private function provenance(Expr $value, Site $site, FunctionLike $test, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return ['unknown', null, null];
        }
        if (self::isLiteral($value)) {
            return ['literal', null, null];
        }
        $held = $this->file->doubles->held($value, $site);
        if ($held !== null) {
            return ['mock-call', $held->double, null];
        }

        return match (true) {
            $value instanceof Expr\Variable => self::isParameter($value, $site, $test) ? ['parameter', null, null] : $this->bound($value, $site, $test, $depth),
            $value instanceof Expr\PropertyFetch && $value->var instanceof Expr\Variable && $value->var->name === 'this' => $this->property($value, $site, $test, $depth),
            $value instanceof Expr\PropertyFetch, $value instanceof Expr\NullsafePropertyFetch => $this->member($value->var, $site, $test, $depth),
            $value instanceof Expr\MethodCall, $value instanceof Expr\NullsafeMethodCall => $this->methodCall($value, $site, $test, $depth),
            $value instanceof Expr\StaticCall => $this->staticCall($value, $site, $test, $depth),
            $value instanceof Expr\FuncCall => $this->called($value, $site, $test, $depth),
            $value instanceof Expr\New_ => ['production', null, null],
            default => ['unknown', null, null],
        };
    }

    /**
     * A variable or `$this` property, through the one value the source assigns it.
     *
     * @return array{string, ?DoubleRecord, ?string}
     */
    private function bound(Expr $leaf, Site $site, FunctionLike $test, int $depth): array
    {
        $bound = $this->file->stable($leaf, $site);

        return $bound === null ? ['unknown', null, null] : $this->provenance($bound[0], $bound[1], $test, $depth + 1);
    }

    /**
     * A `$this` property that holds no double: never a literal or a stub's
     * pinned value. Code the file does not show (a parent class, a trait's
     * hook, `__set()`, a helper handed Pest's `test()`) may write it at any
     * time, so it is followed only to code under test, a source that can
     * only silence a finding, and is unknown otherwise.
     *
     * @return array{string, ?DoubleRecord, ?string}
     */
    private function property(Expr\PropertyFetch $property, Site $site, FunctionLike $test, int $depth): array
    {
        return $this->bound($property, $site, $test, $depth)[0] === 'production' ? ['production', null, null] : ['unknown', null, null];
    }

    /**
     * A property of another value: its source is that value's, a double's
     * pinned value aside, which is the member's and not this property's.
     *
     * @return array{string, ?DoubleRecord, ?string}
     */
    private function member(Expr $base, Site $site, FunctionLike $test, int $depth): array
    {
        [$source, $double] = $this->provenance($base, $site, $test, $depth + 1);

        return $source === 'literal' ? ['unknown', null, null] : [$source, $double, null];
    }

    /**
     * A method call: a stub's pinned value, a call on a double, a helper of
     * the test case, or a call on production.
     *
     * @return array{string, ?DoubleRecord, ?string}
     */
    private function methodCall(Expr\MethodCall|Expr\NullsafeMethodCall $call, Site $site, FunctionLike $test, int $depth): array
    {
        if ($call->var instanceof Expr\Variable && $call->var->name === 'this') {
            return $this->called($call, $site, $test, $depth);
        }
        $held = $this->file->doubles->held($call->var, $site);
        if ($held !== null) {
            return $this->onDouble($held->double, $call->name, !$held->viaProperty && ($held->double->framework !== 'prophecy' || $held->revealed), $site, $test);
        }
        [$source, $double] = $this->provenance($call->var, $site, $test, $depth + 1);

        return match ($source) {
            'production' => ['production', null, null],
            'mock-call', 'mock-config' => ['mock-call', $double, null],
            default => ['unknown', null, null],
        };
    }

    /**
     * A static call: a helper of the test case, a facade the test swapped
     * for a double, or a class under test.
     *
     * @return array{string, ?DoubleRecord, ?string}
     */
    private function staticCall(Expr\StaticCall $call, Site $site, FunctionLike $test, int $depth): array
    {
        if (!$call->class instanceof Name) {
            return ['unknown', null, null];
        }
        $facade = $call->class->isSpecialClassName() ? null : $this->file->doubles->facade($call->class->toString());

        return $facade === null ? $this->called($call, $site, $test, $depth) : $this->onDouble($facade, $call->name, true, $site, $test);
    }

    /**
     * A call on a double: the value pinned on the member it calls, when the
     * double answers with it (a Prophecy double only once revealed) and the
     * source states that one value for the test: exactly one way the member
     * answers is set in the test or its setup, and it is one plain value for
     * any call. Otherwise the call is on the double, with no value to hold
     * the assertion against: one of several in turn, one for some arguments,
     * a callback, a pin made in another test. A double held in a `$this`
     * property never answers with a pin: code the file does not show (a
     * parent class, a trait's hook, a helper handed the test case) may have
     * swapped it for something else before the call.
     *
     * @return array{string, ?DoubleRecord, ?string}
     */
    private function onDouble(DoubleRecord $double, Identifier|Expr $name, bool $answers, Site $site, FunctionLike $test): array
    {
        $member = $name instanceof Identifier ? $name->toString() : null;
        if ($member === null || !$answers) {
            return ['mock-call', $double, null];
        }
        $reaching = array_values(array_filter($double->answersOf($member), fn(array $answer): bool => $this->file->reaches($answer[1], $test, $site)));
        if (count($reaching) !== 1 || !$reaching[0][2] || $reaching[0][0] === null) {
            return ['mock-call', $double, null];
        }

        return ['mock-config', $double, $this->file->text($reaching[0][0])];
    }

    /**
     * A call that is not on a double: through a helper the file declares,
     * production when the call lands in code under test, else unknown.
     *
     * @return array{string, ?DoubleRecord, ?string}
     */
    private function called(Expr $call, Site $site, FunctionLike $test, int $depth): array
    {
        $helper = $this->file->helper($call, $site);
        if ($helper !== null) {
            return $this->throughHelper($helper, $test, $depth);
        }

        return $this->production->runsProduction($call, $site) ? ['production', null, null] : ['unknown', null, null];
    }

    /**
     * What a helper hands back: the kind of source of its one `return`, a
     * literal or an unknown aside. A helper with several returns has no single source.
     *
     * @return array{string, ?DoubleRecord, ?string}
     */
    private function throughHelper(FunctionLike $helper, FunctionLike $test, int $depth): array
    {
        $returns = $this->file->returnsOf($helper);
        if (count($returns) !== 1) {
            return ['unknown', null, null];
        }
        [$source, $double] = $this->provenance($returns[0][0], $returns[0][1], $test, $depth + 1);

        return in_array($source, ['literal', 'unknown'], true) ? ['unknown', null, null] : [$source, $double, null];
    }

    /** Whether a variable is a parameter of the test's own function: a data provider's or a Pest dataset's row. */
    private static function isParameter(Expr\Variable $variable, Site $site, FunctionLike $test): bool
    {
        if (!is_string($variable->name) || $site->variableScope($variable->name) !== 'f' . spl_object_id($test)) {
            return false;
        }
        foreach ($test->getParams() as $param) {
            if ($param->var instanceof Expr\Variable && $param->var->name === $variable->name) {
                return true;
            }
        }

        return false;
    }

    /** A literal whose value its text states: a number, a string with no interpolation, a boolean, null, `Foo::class`, an array of literals. */
    private static function isLiteral(Expr $value): bool
    {
        return match (true) {
            $value instanceof Scalar\Int_, $value instanceof Scalar\Float_, $value instanceof Scalar\String_, $value instanceof Scalar\MagicConst => true,
            $value instanceof Expr\UnaryMinus, $value instanceof Expr\UnaryPlus => $value->expr instanceof Scalar\Int_ || $value->expr instanceof Scalar\Float_,
            $value instanceof Expr\ConstFetch => in_array($value->name->toLowerString(), ['true', 'false', 'null'], true),
            $value instanceof Expr\ClassConstFetch => $value->class instanceof Name && $value->class->toLowerString() !== 'static' && $value->name instanceof Identifier && $value->name->toLowerString() === 'class',
            $value instanceof Expr\Array_ => self::isLiteralArray($value),
            default => false,
        };
    }

    /** Whether every key and value of an array is a literal, none unpacked or by reference. */
    private static function isLiteralArray(Expr\Array_ $array): bool
    {
        foreach ($array->items as $item) {
            if ($item->unpack || $item->byRef || !self::isLiteral($item->value) || ($item->key !== null && !self::isLiteral($item->key))) {
                return false;
            }
        }

        return true;
    }

    /** Whether evaluating an expression again may give another value: any part of it calls, constructs, clones or makes a closure. */
    private static function reevaluates(Expr $value): bool
    {
        return (new NodeFinder())->findFirst($value, static fn(Node $node): bool => $node instanceof Expr\CallLike || $node instanceof Expr\Clone_
            || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction || $node instanceof Expr\Include_ || $node instanceof Expr\Eval_) !== null;
    }
}

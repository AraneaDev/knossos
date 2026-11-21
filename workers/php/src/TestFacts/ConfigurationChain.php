<?php

declare(strict_types=1);

namespace KnossosPhpScanner\TestFacts;

use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;

/**
 * Reads a chain of calls on a double: the members it configures, the values
 * it pins them to, the arguments it expects them called with, and whether
 * the chain's value is still the double.
 *
 * A chain starts on the double itself. A call that hands the double back
 * (`makePartial()`, `getMock()`, `reveal()`) keeps it there; one that starts
 * an expectation (`method('m')`, `shouldReceive('m')`, Prophecy's `$p->m()`)
 * moves on to the expectation, whose calls configure the members it named.
 * Any other call on the double calls it, and is a site that reaches it.
 *
 * A member whose name is computed (`method($name)`, `$p->{$name}()`) is no
 * member: there is nothing to hold it against, and its configuration is
 * dropped with it.
 */
final class ConfigurationChain
{
    /** Calls on a double that hand the same double back, by framework; their side effects are applied as they pass. */
    private const KEEPS = [
        'phpunit' => [
            'getmock', 'getmockforabstractclass', 'getmockfortrait', 'onlymethods', 'setmethods', 'addmethods',
            'disableoriginalconstructor', 'enableoriginalconstructor', 'setconstructorargs', 'setmockclassname',
            'disableoriginalclone', 'enableoriginalclone', 'disableautoload', 'enableautoload',
            'disableargumentcloning', 'enableargumentcloning', 'enableproxyingtooriginalmethods',
            'disableproxyingtooriginalmethods', 'setproxytarget', 'allowmockingunknowntypes', 'disallowmockingunknowntypes',
            'enableautoreturnvaluegeneration', 'disableautoreturnvaluegeneration',
        ],
        'mockery' => ['makepartial', 'shouldignoremissing', 'shouldallowmockingprotectedmethods', 'shouldallowmockingmethod', 'shoulddefermissing', 'asundefined'],
        'prophecy' => ['reveal', 'willextend', 'willimplement', 'willbeconstructedwith'],
    ];

    /** Calls on a double that start an expectation, by framework; Prophecy's are every other call. */
    private const EXPECTS = [
        'phpunit' => ['method', 'expects'],
        'mockery' => ['shouldreceive', 'shouldnotreceive', 'allows', 'expects', 'shouldhavereceived', 'shouldnothavereceived', 'shouldhavebeencalled', 'shouldnothavebeencalled'],
    ];

    /** Mockery's calls that stub a double, so a spy given one is configured. */
    private const STUBS = ['shouldreceive', 'shouldnotreceive', 'allows', 'expects'];

    /** Calls on an expectation that pin a member's return, by the api each records: every argument is one value. */
    private const PINS = [
        'willreturn' => 'willReturn',
        'willreturnonconsecutivecalls' => 'willReturnOnConsecutiveCalls',
        'andreturn' => 'andReturn',
    ];

    /** Calls on an expectation that make a member return what a callback returns, which the syntax does not show. */
    private const CALLBACKS = ['willreturncallback' => 'willReturnCallback', 'andreturnusing' => 'andReturnUsing'];

    /** Calls on an expectation that make a member return the double itself, by the api each records. */
    private const SELF = ['willreturnself' => 'willReturnSelf', 'andreturnself' => 'andReturnSelf'];

    /** Calls on an expectation that shape what a member returns without pinning one stated value. */
    private const OTHER_ANSWERS = [
        'willreturnmap', 'willreturnargument', 'willreturnreference', 'will', 'willthrowexception',
        'andreturnvalues', 'andreturnnull', 'andreturnfalse', 'andreturntrue', 'andreturnundefined', 'andreturnarg',
        'andthrow', 'andthrows', 'andthrowexceptions', 'passthru',
    ];

    /** Calls on an expectation that limit it to some arguments, so what it answers holds for those calls only. */
    private const GUARDS = ['with', 'withargs', 'withsomeofargs', 'withnoargs'];

    /** Mockery's argument expectations, by the api each records. */
    private const MATCHERS = ['with' => 'with', 'withargs' => 'withArgs', 'withnoargs' => 'withNoArgs'];

    private function __construct() {}

    /**
     * Walk a chain of calls made on a double, innermost first, and say whether
     * the chain's value is still the double: null when it is not, else whether
     * it is the double revealed. With `$configure`, record what the chain
     * configures and the site that calls the double; without, only answer.
     *
     * `$revealed` says the chain starts on a Prophecy double already revealed,
     * as a variable or property assigned `$p->reveal()` holds it: a call on it
     * calls the double rather than configuring it.
     *
     * @param list<Expr\MethodCall|Expr\StaticCall> $calls
     */
    public static function walk(DoubleRecord $double, array $calls, bool $configure, bool $revealed = false): ?bool
    {
        $framework = $double->framework === 'pest' ? 'mockery' : $double->framework;
        $expecting = null;
        $guarded = false;
        foreach ($calls as $index => $call) {
            $name = $call->name instanceof Identifier ? strtolower($call->name->toString()) : null;
            if ($expecting !== null) {
                if ($framework === 'mockery' && $name === 'getmock') {
                    $expecting = null;
                } elseif ($configure) {
                    $expecting = self::configure($double, $framework, $expecting, $call, $name, $guarded);
                }
                continue;
            }
            if ($name !== null && in_array($name, self::KEEPS[$framework] ?? [], true)) {
                $revealed = $revealed || $name === 'reveal';
                if ($configure) {
                    self::kept($double, $name, $call);
                }
                continue;
            }
            if (($framework === 'prophecy' && !$revealed) || in_array($name, self::EXPECTS[$framework] ?? [], true)) {
                $expecting = $configure ? self::expectation($double, $framework, $call, $name) : [];
                $guarded = self::guarded($framework, $call, array_slice($calls, $index + 1));
                continue;
            }
            if ($configure) {
                $double->reach($call->getStartLine());
            }

            return null;
        }

        return $expecting === null ? $revealed : null;
    }

    /**
     * Whether a chain of calls on a double, innermost first, starts an
     * expectation on it, rather than calling it or handing it back.
     *
     * @param list<Expr\MethodCall|Expr\StaticCall> $calls
     */
    public static function expects(DoubleRecord $double, array $calls, bool $revealed): bool
    {
        $framework = $double->framework === 'pest' ? 'mockery' : $double->framework;
        foreach ($calls as $call) {
            $name = $call->name instanceof Identifier ? strtolower($call->name->toString()) : null;
            if ($name !== null && in_array($name, self::KEEPS[$framework] ?? [], true)) {
                $revealed = $revealed || $name === 'reveal';
                continue;
            }

            return ($framework === 'prophecy' && !$revealed) || in_array($name, self::EXPECTS[$framework] ?? [], true);
        }

        return false;
    }

    /**
     * Whether an expectation answers only some calls: a Prophecy call given
     * arguments, or an expectation that a later call limits with `with()`.
     *
     * @param list<Expr\MethodCall|Expr\StaticCall> $later
     */
    private static function guarded(string $framework, Expr\MethodCall|Expr\StaticCall $call, array $later): bool
    {
        if ($framework === 'prophecy') {
            return $call->isFirstClassCallable() || $call->getArgs() !== [];
        }
        foreach ($later as $next) {
            if ($next->name instanceof Identifier && in_array($next->name->toLowerString(), self::GUARDS, true)) {
                return true;
            }
        }

        return false;
    }

    /** Apply what a call that keeps the double does to it: the members `onlyMethods()` lists, Mockery's protected opt-in. */
    private static function kept(DoubleRecord $double, string $name, Expr\MethodCall|Expr\StaticCall $call): void
    {
        $listed = self::argument($call, 0);
        if (($name === 'onlymethods' || $name === 'setmethods') && $listed instanceof Expr\Array_) {
            DoubleFactories::listed($double, $listed, 'partial');
        } elseif ($name === 'shouldallowmockingprotectedmethods') {
            $double->allowsProtected = true;
        }
    }

    /**
     * Start an expectation, and return the members it is about.
     *
     * @return list<string>
     */
    private static function expectation(DoubleRecord $double, string $framework, Expr\MethodCall|Expr\StaticCall $call, ?string $name): array
    {
        if ($framework === 'prophecy') {
            if (!$call->name instanceof Identifier) {
                return [];
            }
            $member = $call->name->toString();
            $double->member($member, $call->name->getStartLine(), 'prophecy');
            [$count, $spread] = self::argumentCount($call, true);
            $double->matcher($member, 'prophecy', $call->getStartLine(), $count, $spread);

            return [$member];
        }
        if ($framework === 'mockery' && in_array($name, self::STUBS, true)) {
            $double->stubbed = true;
        }
        if ($name === 'method' || $name === 'shouldreceive') {
            return self::named($double, $call, $name === 'method' ? 'method' : 'shouldReceive');
        }
        if ($name === 'shouldhavereceived' || $name === 'shouldnothavereceived') {
            return self::spyAssertion($double, $call);
        }

        return [];
    }

    /**
     * Configure the members of an expectation with one call on it, and return
     * the members later calls are about.
     *
     * @param list<string> $members
     * @return list<string>
     */
    private static function configure(DoubleRecord $double, string $framework, array $members, Expr\MethodCall|Expr\StaticCall $call, ?string $name, bool $guarded): array
    {
        if ($framework === 'phpunit' && $name === 'method') {
            return self::named($double, $call, 'method');
        }
        $line = $call->getStartLine();
        $arguments = self::arguments($call);
        // One value for any call: not one of several in turn, nor one for some arguments only. Each argument is an answer of its own.
        $plain = in_array($name, ['willreturn', 'andreturn'], true) && !$guarded && array_filter($arguments, static fn(Arg $argument): bool => $argument->unpack) === [];
        foreach ($members as $member) {
            if (isset(self::PINS[$name ?? ''])) {
                foreach ($arguments as $argument) {
                    $double->pin($member, self::PINS[$name], $argument->value, $argument->value->getStartLine(), $plain);
                }
            } elseif (isset(self::CALLBACKS[$name ?? ''])) {
                $double->unknownPin($member, self::CALLBACKS[$name], $line, $call->getStartFilePos());
                $double->replacement($member, self::argument($call, 0));
            } elseif (isset(self::SELF[$name ?? ''])) {
                $double->selfPin($member, self::SELF[$name], $line, $call->getStartFilePos());
            } elseif (in_array($name, self::OTHER_ANSWERS, true)) {
                $double->answer($member, $call->getStartFilePos());
            } elseif ($framework !== 'prophecy' && isset(self::MATCHERS[$name ?? '']) && ($framework === 'mockery' || $name === 'with')) {
                [$count, $spread] = self::argumentCount($call, $name !== 'withargs');
                $double->matcher($member, self::MATCHERS[$name], $line, $count, $spread);
            }
        }

        return $members;
    }

    /**
     * The members `method('m')` or `shouldReceive('a', 'b')` names: each
     * string argument, or for Mockery each key or string of an array, its
     * value the return it pins. A computed name is no member.
     *
     * @return list<string>
     */
    private static function named(DoubleRecord $double, Expr\MethodCall|Expr\StaticCall $call, string $api): array
    {
        $members = [];
        foreach (self::arguments($call) as $index => $argument) {
            $value = $argument->value;
            if ($value instanceof Scalar\String_ && ($index === 0 || $api === 'shouldReceive')) {
                // A Mockery demeter chain, `'a->b'`, configures `a` on the double.
                $members[] = self::member($double, explode('->', $value->value)[0], $value, $api);
            } elseif ($value instanceof Expr\Array_ && $api === 'shouldReceive') {
                foreach ($value->items as $item) {
                    $key = $item->key instanceof Scalar\String_ ? $item->key : ($item->key === null ? $item->value : null);
                    if ($key instanceof Scalar\String_ && !$item->unpack && ($member = self::member($double, $key->value, $key, $api)) !== null) {
                        $members[] = $member;
                        if ($item->key !== null) {
                            $double->pin($member, 'shouldReceive', $item->value, $item->value->getStartLine());
                        }
                    }
                }
            }
        }

        return array_values(array_filter($members, static fn(?string $member): bool => $member !== null));
    }

    /**
     * The member a Mockery spy assertion, `shouldHaveReceived('m')`, is
     * about. The argument list it may be given second, `['a', 'b']`, is a
     * matcher, as `->with('a', 'b')` after it would be.
     *
     * @return list<string>
     */
    private static function spyAssertion(DoubleRecord $double, Expr\MethodCall|Expr\StaticCall $call): array
    {
        $name = self::argument($call, 0);
        if (!$name instanceof Scalar\String_ || self::member($double, $name->value, $name, 'shouldHaveReceived') === null) {
            return [];
        }
        $listed = self::argument($call, 1);
        if ($listed !== null && !($listed instanceof Expr\ConstFetch && $listed->name->toLowerString() === 'null')) {
            [$count, $spread] = $listed instanceof Expr\Array_ ? self::argumentCount($call, false, 1) : [0, true];
            $double->matcher($name->value, 'with', $call->getStartLine(), $count, $spread);
        }

        return [$name->value];
    }

    /** Record one named member; null for a name that is not a method name. */
    private static function member(DoubleRecord $double, string $name, Scalar\String_ $written, string $api): ?string
    {
        return $double->member($name, $written->getStartLine(), $api) ? $name : null;
    }

    /**
     * How many arguments a matcher lists, and whether the real count is open:
     * a spread argument, Prophecy's `Argument::cetera()`, or a `withArgs()`
     * given a closure or a variable rather than an array. With `$direct` the
     * call's own arguments are the list; without, its array argument at
     * `$position` is.
     *
     * @return array{int, bool}
     */
    private static function argumentCount(Expr\MethodCall|Expr\StaticCall $call, bool $direct, int $position = 0): array
    {
        if ($call->isFirstClassCallable()) {
            return [0, true];
        }
        $arguments = $call->getArgs();
        if (!$direct) {
            $list = $arguments[$position]->value ?? null;
            if (!$list instanceof Expr\Array_) {
                return [0, true];
            }
            $spread = array_filter($list->items, static fn(ArrayItem $item): bool => $item->unpack) !== [];

            return [count($list->items), $spread];
        }
        $spread = array_filter($arguments, static fn(Arg $argument): bool => $argument->unpack || self::isCetera($argument->value)) !== [];

        return [count($arguments), $spread];
    }

    /** Whether an argument is Prophecy's `Argument::cetera()`, which matches any arguments that remain. */
    private static function isCetera(Expr $value): bool
    {
        return $value instanceof Expr\StaticCall && $value->class instanceof Name && strtolower($value->class->getLast()) === 'argument'
            && $value->name instanceof Identifier && strtolower($value->name->toString()) === 'cetera';
    }

    /**
     * A call's arguments, none for a first-class callable.
     *
     * @return list<Arg>
     */
    private static function arguments(Expr\MethodCall|Expr\StaticCall $call): array
    {
        return $call->isFirstClassCallable() ? [] : array_values($call->getArgs());
    }

    /** The value of a call's argument at a position, or null. */
    private static function argument(Expr\MethodCall|Expr\StaticCall $call, int $index): ?Expr
    {
        return self::arguments($call)[$index]->value ?? null;
    }
}

<?php

declare(strict_types=1);

namespace KnossosPhpScanner\TestFacts;

use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * One test double as the collector learns about it: what made it, what it
 * stands in for, and the members the test configures on it, merged by name
 * across every chain that reaches it.
 */
final class DoubleRecord
{
    /** @var array<string, array{name: string, line: int, api: string, called_with: list<array{api: string, line: int, count: int, spread: bool}>, returns: list<array<string, mixed>>}> */
    private array $members = [];

    /** @var array<int, true> the lines that call the double or hand it on */
    private array $sites = [];

    /** @var array<string, ?Expr> by member, the expression its first pin returns; null for a callback's result */
    private array $firstPins = [];

    /** Whether the framework lets the double stub protected members. */
    public bool $allowsProtected = false;

    /** Whether the test stubs the double through Mockery: `shouldReceive()`, `allows()`, `expects()`. */
    public bool $stubbed = false;

    /** The canonical name of the test the double is made in; null for one made outside any test. */
    public ?string $testCase = null;

    /**
     * @param Node $node the expression that makes the double, which places it
     * @param string|null $target the class it replaces, fully qualified; null when the code does not name one
     * @param string $subject what the double's display name adds to its pattern
     */
    public function __construct(
        public readonly Node $node,
        public readonly string $framework,
        public readonly string $pattern,
        public readonly ?string $target,
        public readonly bool $automock,
        public readonly string $subject = '',
    ) {}

    /**
     * Record a member the test names, once: a later mention keeps the first
     * line and api. A name that is not a PHP method name names no member, and
     * says so with false.
     */
    public function member(string $name, int $line, string $api): bool
    {
        if (preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $name) !== 1) {
            return false;
        }
        $this->members[$name] ??= ['name' => $name, 'line' => $line, 'api' => $api, 'called_with' => [], 'returns' => []];

        return true;
    }

    /** Record a value the test pins a member to return. */
    public function pin(string $name, string $api, Expr $value, int $line): void
    {
        if (!isset($this->members[$name])) {
            return;
        }
        $type = PinnedType::of($value);
        $this->firstPins[$name] ??= $value;
        $this->members[$name]['returns'][] = ['api' => $api, 'line' => $line, 'once' => false, 'assignable' => 'unknown', 'typed' => $type !== PinnedType::UNKNOWN, 'type' => $type];
    }

    /** Record a value the test makes a member return without stating it, such as a callback's result. */
    public function unknownPin(string $name, string $api, int $line): void
    {
        if (isset($this->members[$name])) {
            $this->firstPins[$name] ??= null;
            $this->members[$name]['returns'][] = ['api' => $api, 'line' => $line, 'once' => false, 'assignable' => 'unknown', 'typed' => false, 'type' => PinnedType::UNKNOWN];
        }
    }

    /** Whether the test pins what a member returns. */
    public function isPinned(string $name): bool
    {
        return array_key_exists($name, $this->firstPins);
    }

    /** The expression a member's first pin makes it return; null when it is not pinned, or pinned to a callback's result. */
    public function firstPin(string $name): ?Expr
    {
        return $this->firstPins[$name] ?? null;
    }

    /** Whether the double is a Mockery spy the test never stubs, so every call on it returns null. */
    public function isUnconfiguredSpy(): bool
    {
        return in_array($this->pattern, ['Mockery::spy', 'laravel.spy'], true) && !$this->stubbed;
    }

    /** Record an assertion on the arguments a member is called with. */
    public function matcher(string $name, string $api, int $line, int $count, bool $spread): void
    {
        if (isset($this->members[$name])) {
            $this->members[$name]['called_with'][] = ['api' => $api, 'line' => $line, 'count' => $count, 'spread' => $spread];
        }
    }

    /** Record a line that calls the double or hands it to other code. */
    public function reach(int $line): void
    {
        $this->sites[$line] = true;
    }

    /**
     * The double's `test_double` attributes, in the wire shape.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        $members = [];
        foreach ($this->members as $member) {
            if ($member['called_with'] === []) {
                unset($member['called_with']);
            }
            $members[] = $member;
        }

        return [
            'framework' => $this->framework,
            'pattern' => $this->pattern,
            'target' => $this->target === null ? ['kind' => 'unknown'] : ['kind' => 'class', 'export' => $this->target],
            'automock' => $this->automock,
            'invocations' => count($this->sites),
            ...($this->allowsProtected ? ['allows_protected' => true] : []),
            ...($this->testCase === null ? [] : ['test_case' => $this->testCase]),
            'members' => $members,
        ];
    }

    /** The name a reader sees: the pattern, then the class or facade it stands in for. */
    public function displayName(): string
    {
        $subject = $this->target ?? $this->subject;

        return $subject === '' ? $this->pattern : $this->pattern . ' ' . $subject;
    }
}

<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\OnRequest;

use Knossos\TestFidelity\Facts\Assertion;

/**
 * What the on-request rules read of the active graph, per test file: a file
 * holding at least one test double or test case.
 *
 * A module is named by the project file that declares it, or, for one outside
 * the project (a package, a standard library), as `external:` and its name, so
 * an import and a double's target name one module alike.
 */
final readonly class OnRequestInput
{
    /**
     * @param array<string, string> $testFiles every test file => its language (`typescript`, `php`, `python`, `rust`, or a third-party worker's label), in file order
     * @param array<string, list<array{module: string, name: string, local: bool}>> $imports test file => each import of its modules: the module imported, its name, and whether the import sits inside a function or class rather than at module level (read for Python only)
     * @param array<string, list<array{module: ?string, name: ?string}>> $doubles test file => each double's target: the module declaring it and its name; both null when the double has no target in the graph
     * @param array<string, list<Assertion>> $assertions test file => every assertion of its test cases
     * @param bool $partial true when a bound (`timeout_ms`, a row cap) cut the load short; nothing is then loaded, so no rule judges half a file
     * @param array<string, list<array{double: string, line: int, target: string, canonical: string, files: list<string>, inherited: array<string, list<string>>, members: list<string>}>> $targets test file => each double with a target in the graph, in line order: its name and line, its target's display and canonical names, the files the target lives in (its own, then each of its `contains` children's), the files declaring a member of that name on a type it extends or uses (a PHP trait), and the members the double stubs
     * @param ?list<string> $changed the project files changed since the base ref, in scope `git-diff`; null outside it
     * @param ?string $cutBy what cut the load short (`timeout_ms` or `row_cap`); null when nothing did
     */
    public function __construct(
        public array $testFiles,
        public array $imports,
        public array $doubles,
        public array $assertions,
        public bool $partial = false,
        public array $targets = [],
        public ?array $changed = null,
        public ?string $cutBy = null,
    ) {}

    /** The input of a load cut short by `$cause` (`timeout_ms` or `row_cap`): nothing to judge, and said so. */
    public static function cutShort(string $cause): self
    {
        return new self([], [], [], [], true, cutBy: $cause);
    }
}

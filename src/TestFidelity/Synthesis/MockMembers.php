<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Synthesis;

use Knossos\Scanner\Protocol\NodeFact;
use Knossos\TestFidelity\Facts\FieldLists;
use Knossos\TestFidelity\TestFidelityGraph;

/**
 * What a mock of one class, interface or trait must answer (Decision 5):
 * its public instance methods, declared or inherited along `extends`,
 * `implements` and `uses_trait`, nearest first, and an interface's
 * properties; what is left out because it cannot be rendered faithfully
 * (`omitted`, with why); and whether the list is the whole of it
 * (`complete`).
 *
 * Left out without a word: constructors, Python dunders, static members (a
 * Rust associated function of a struct), and private or protected ones, a
 * Python `__x` included. A Python `_x` is kept with a note (preflight F27).
 * A Rust `visibility` is absent for public and set for anything narrower
 * (preflight F6): `private` leaves the member out, while a restricted one
 * (`pub(crate)`) is kept with a note, since the crate's own tests, where a
 * `mock!` of a struct lives, call it. A Rust trait's associated function
 * stays, since an `impl` must give it.
 *
 * The list is not complete when a member is omitted, when an ancestor or a
 * supertrait is outside the scan (but an auto trait, `Send`, `Sync`,
 * `Unpin` or `Sized`, which every mock is or needs no impl of), when a type of the lineage has an open or
 * no complete name list, when Python methods may be rewritten, or when a
 * PHP `__call` answers any name.
 */
final readonly class MockMembers
{
    /**
     * @param list<MockMember> $members
     * @param list<array{name: string, reason: string}> $omitted
     * @param list<string> $notes
     * @param list<array{trait: string, unsafe: bool}> $impls a Rust struct's trait impls the template gives, in source order, method-less ones included
     */
    private function __construct(
        public NodeFact $target,
        public string $language,
        public array $members,
        public array $omitted,
        public bool $complete,
        public array $notes,
        public TestFidelityGraph $graph,
        public array $impls = [],
    ) {}

    /** The auto and marker traits a mock has, or needs no impl of, by name. */
    private const MARKER_TRAITS = ['Send', 'Sync', 'Unpin', 'Sized', 'UnwindSafe', 'RefUnwindSafe'];

    /**
     * The members of `$target` over `$graph`, which holds its lineage.
     *
     * @param bool $cut the lineage was cut at a bound, so it may hold more
     * @param list<array<string, mixed>> $impls a Rust struct's `trait_impls` entries, every impl the scan saw for it
     */
    public static function of(TestFidelityGraph $graph, NodeFact $target, string $language, bool $cut = false, array $impls = []): self
    {
        $implList = $impls;
        $members = $omitted = $notes = [];
        $seen = $impls = $mocked = [];
        $struct = $language === 'rust' && $target->kind !== 'interface';
        [$blocks, $entries, $implNotes, $implOmitted, $external] = $struct ? self::implBlocks($graph, $target, $implList) : [[], [], [], [], false];
        $fields = $target->kind === 'interface' && $language === 'typescript' ? (FieldLists::of($graph, $target)?->fields() ?? []) : [];
        // A Rust struct inherits nothing: its trait impls are its own children, each naming its trait.
        $lineage = $language === 'rust' && $target->kind !== 'interface' ? [$target] : $graph->lineage($target);
        foreach ($lineage as $declarer) {
            if ($language === 'rust' && $declarer->kind === 'interface') {
                $members = [...$members, ...self::associated($declarer)];
            }
            $children = $graph->children($declarer);
            usort($children, static fn(NodeFact $a, NodeFact $b): int => [$a->evidence->startLine, $a->displayName] <=> [$b->evidence->startLine, $b->displayName]);
            foreach ($children as $node) {
                if (!in_array($node->kind, ['method', 'property'], true)) {
                    continue;
                }
                // A Rust struct's trait impls each declare their own names.
                $key = match ($language) {
                    'php' => strtolower($node->displayName),
                    'rust' => (is_string($node->attributes['impl_trait'] ?? null) ? $node->attributes['impl_trait'] : '') . '#' . $node->displayName,
                    default => $node->displayName,
                };
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $member = self::member($graph, $target, $declarer, $node, $language, $fields);
                if ($member instanceof MockMember && $language === 'rust' && $target->kind !== 'interface') {
                    // mockall makes one expect_name() per struct, whichever impl declares the name.
                    $short = (string) preg_replace('/^.*::/', '', $member->name);
                    if (isset($mocked[$short]) && $mocked[$short] !== $member->implTrait) {
                        $member = 'mockall cannot mock one method name from two impls';
                    } else {
                        $mocked[$short] = $member->implTrait;
                    }
                }
                if ($member instanceof MockMember && $member->implTrait !== null && !isset($impls[$member->implTrait])) {
                    // An impl's associated items come once, before its first method.
                    $impls[$member->implTrait] = true;
                    $members = [...$members, ...(isset($entries[$member->implTrait])
                        ? self::implAssociated($entries[$member->implTrait]['types'] ?? [], $entries[$member->implTrait]['consts'] ?? [], $declarer, $member->implTrait)
                        : self::implAssociated($node->attributes['impl_types'] ?? [], $node->attributes['impl_consts'] ?? [], $declarer, $member->implTrait))];
                }
                if (is_string($member)) {
                    $omitted[] = ['name' => $node->displayName, 'reason' => $member];
                } elseif ($member !== null) {
                    $members[] = $member;
                    if ($member->note !== null) {
                        $notes[] = $member->note;
                    }
                }
            }
        }
        // An impl without a method gives its associated items all the same.
        foreach ($entries as $trait => $entry) {
            if (!isset($impls[$trait])) {
                $members = [...$members, ...self::implAssociated($entry['types'] ?? [], $entry['consts'] ?? [], $target, (string) $trait)];
            }
        }
        $omitted = [...$omitted, ...$implOmitted];
        // An impl a method of which was left out is not given at all: a block missing it would not compile.
        $blocks = array_values(array_filter($blocks, static function (array $block) use ($entries, $members): bool {
            $given = array_map(static fn(MockMember $member): string => (string) preg_replace('/^.*::/', '', $member->name), array_filter($members, static fn(MockMember $member): bool => $member->kind === 'method' && $member->implTrait === $block['trait']));
            $listed = $entries[$block['trait']]['methods'] ?? [];

            return array_diff(is_array($listed) ? $listed : [], $given) === [];
        }));
        foreach (self::unseenNames($graph, $target, $language, $seen, $fields) as $name) {
            $omitted[] = ['name' => $name, 'reason' => 'no signature recorded: an accessor, a docblock @method, or a member the scan gives no node'];
        }
        // A struct's impls, when the worker lists them, say what is outside the scan, impl by impl.
        $open = $struct && $implList !== [] ? ($cut ? self::openness($graph, $target, $language, true) : []) : self::openness($graph, $target, $language, $cut);
        $notes = [...$notes, ...$open, ...$implNotes];
        if ($members === [] && $blocks === []) {
            $notes[] = sprintf('%s has no member a mock can stand in for.', $target->displayName);
        }

        return new self($target, $language, $members, $omitted, $open === [] && $omitted === [] && !$external, $notes, $graph, $blocks);
    }

    /**
     * The target's own type parameters as written (`T`, `K extends string`), from its `type_params`.
     *
     * @return list<string>
     */
    public function typeParams(): array
    {
        return self::typeParamsOf($this->target);
    }

    /**
     * A node's `type_params` as written.
     *
     * @return list<string>
     */
    public static function typeParamsOf(NodeFact $node): array
    {
        $params = $node->attributes['type_params'] ?? [];

        return is_array($params) ? array_values(array_filter($params, is_string(...))) : [];
    }

    /** The template's second comment line: how many members, what was left out, and whether it is all. */
    public function summary(): string
    {
        $count = count($this->members);
        $omitted = count($this->omitted);

        return sprintf('%d member%s%s; %s', $count, $count === 1 ? '' : 's', $omitted === 0 ? '' : sprintf(', %d omitted', $omitted), $this->complete ? 'complete' : 'incomplete: see omitted and notes');
    }

    /**
     * One child as a member: null when a mock has no use for it (a
     * constructor, a static or non-public member), the reason when it is
     * left out because it cannot be rendered faithfully.
     *
     * @param array<string, array{type: ?string, required: bool, line: int, visibility?: string}> $fields the target interface's fields, for its properties
     */
    private static function member(TestFidelityGraph $graph, NodeFact $target, NodeFact $declarer, NodeFact $node, string $language, array $fields): MockMember|string|null
    {
        $name = $node->displayName;
        $attributes = $node->attributes;
        if ($node->kind === 'property') {
            if ($language !== 'typescript' || $target->kind !== 'interface' || ($attributes['static'] ?? false) === true) {
                return null;
            }
            $field = $fields[$name] ?? null;

            return new MockMember($name, 'property', $node, $declarer, type: $field['type'] ?? null, optional: $field !== null && !$field['required']);
        }
        if (self::isConstructorOrStatic($name, $attributes, $language) || !self::isVisible($attributes, $language)) {
            return null;
        }
        $signature = $attributes['signature'] ?? null;
        if (!is_array($signature) || !is_array($signature['params'] ?? null)) {
            return 'no signature recorded';
        }
        if (($attributes['overloaded'] ?? false) === true) {
            return 'overloaded: one recorded signature cannot stand for every overload';
        }
        if (($signature['truncated'] ?? false) === true) {
            return 'its signature was cut (more than 12 parameters, or a type over 80 characters)';
        }
        if (($attributes['decorated'] ?? false) === true) {
            return 'decorated: a decorator or a rebinding may change its signature';
        }
        $implTrait = $language === 'rust' && is_string($attributes['impl_trait'] ?? null) ? $attributes['impl_trait'] : null;
        if ($language === 'rust' && $implTrait === null && (($attributes['overrides'] ?? false) === true || str_contains($node->canonicalName, '::<impl '))) {
            return "a trait impl's method, whose trait the scan does not record: mock that trait instead";
        }
        $associated = $language === 'rust' && !isset($signature['receiver']);
        // An inherent associated function (`new`) is the mock's own; a trait impl's is its impl's.
        if ($associated && $declarer->kind !== 'interface' && $implTrait === null) {
            return null;
        }
        if ($language === 'typescript' && is_array($signature['type_params'] ?? null) && $signature['type_params'] !== []) {
            return sprintf("generic: a vitest or jest mock's type cannot keep its own type parameters, so no stub fits it; write it as vi.fn() as unknown as %s['%s']", $target->displayName, $name);
        }
        if ($declarer->localId !== $target->localId && ($inherited = self::inheritedParameter($declarer, $signature)) !== null) {
            return sprintf('uses %s, a type parameter of %s, whose argument for %s the scan does not record', $inherited, $declarer->displayName, $target->displayName);
        }
        $visibility = $attributes['visibility'] ?? null;
        $note = match (true) {
            $language === 'python' && $visibility === 'protected' => sprintf('%s is protected (a leading underscore): it is kept, as create_autospec allows it, though a test should rarely need it.', $name),
            $language === 'rust' && is_string($visibility) => sprintf('%s is %s: it is kept, as the crate\'s own tests call it; a mock outside the crate cannot.', $name, $visibility),
            default => null,
        };

        return new MockMember($name, 'method', $node, $declarer, $signature, associated: $associated, note: $note, implTrait: $implTrait);
    }

    /**
     * A Rust trait's associated types and consts, which every impl must
     * give: as members a template renders with a placeholder.
     *
     * @return list<MockMember>
     */
    private static function associated(NodeFact $trait): array
    {
        $members = [];
        foreach (is_array($trait->attributes['associated_types'] ?? null) ? $trait->attributes['associated_types'] : [] as $written) {
            if (is_string($written)) {
                $members[] = new MockMember(TypeText::parameterName($written), 'type', $trait, $trait, type: $written);
            }
        }
        foreach (is_array($trait->attributes['associated_consts'] ?? null) ? $trait->attributes['associated_consts'] : [] as $const) {
            if (is_array($const) && is_string($const['name'] ?? null) && is_string($const['type'] ?? null)) {
                $members[] = new MockMember($const['name'], 'const', $trait, $trait, type: $const['type']);
            }
        }

        return $members;
    }

    /**
     * The blocks a struct's mock gives, one per trait impl the worker saw,
     * and what is said of the others: an auto or marker trait (`Send`,
     * `Sync`) needs none, `Copy` cannot be mocked, an `impl<T>` cannot be
     * written without its parameters, and a trait outside the scan may have
     * default methods the impl does not write, so the list is not whole.
     *
     * @param list<array<string, mixed>> $implList
     * @return array{list<array{trait: string, unsafe: bool}>, array<string, array<string, mixed>>, list<string>, list<array{name: string, reason: string}>, bool}
     */
    private static function implBlocks(TestFidelityGraph $graph, NodeFact $target, array $implList): array
    {
        $inScan = [];
        foreach ($graph->lineageThrough($target, static fn(): bool => true)[0] as $type) {
            if ($type->kind === 'interface') {
                $inScan[$type->displayName] = true;
            }
        }
        $blocks = $entries = $notes = $omitted = [];
        $external = false;
        foreach ($implList as $entry) {
            $trait = is_string($entry['trait'] ?? null) ? $entry['trait'] : null;
            if ($trait === null || isset($entries[$trait])) {
                continue;
            }
            $name = (string) preg_replace('/^.*::/', '', (string) preg_replace('/<.*$/s', '', $trait));
            if (in_array($name, self::MARKER_TRAITS, true)) {
                continue;
            }
            if ($name === 'Copy') {
                $notes[] = sprintf('%s implements Copy: a mock cannot be Copy, so that impl is left out.', $target->displayName);
                continue;
            }
            if (($entry['generics'] ?? false) === true) {
                $omitted[] = ['name' => 'impl ' . $trait, 'reason' => 'a generic impl (impl<T>): its parameters are not recorded'];
                continue;
            }
            if (!isset($inScan[$name])) {
                $external = true;
                $notes[] = sprintf('%s implements %s, a trait outside the scan: its methods the impl does not write (defaults) are not listed.', $target->displayName, $trait);
            }
            $entries[$trait] = $entry;
            $blocks[] = ['trait' => $trait, 'unsafe' => ($entry['unsafe'] ?? false) === true];
        }

        return [$blocks, $entries, $notes, $omitted, $external];
    }

    /**
     * A trait impl's own associated types and consts, as it writes them:
     * its `trait_impls` entry's `types` and `consts`, or a method's
     * `impl_types` and `impl_consts` from a worker that sends no entry.
     *
     * @param mixed $types
     * @param mixed $consts
     * @return list<MockMember>
     */
    private static function implAssociated(mixed $types, mixed $consts, NodeFact $declarer, string $implTrait): array
    {
        $members = [];
        foreach (is_array($types) ? $types : [] as $type) {
            if (is_array($type) && is_string($type['name'] ?? null) && is_string($type['type'] ?? null)) {
                $members[] = new MockMember($type['name'], 'type', $declarer, $declarer, type: $type['type'], implTrait: $implTrait);
            }
        }
        foreach (is_array($consts) ? $consts : [] as $const) {
            if (is_array($const) && is_string($const['name'] ?? null) && is_string($const['type'] ?? null)) {
                $value = is_string($const['value'] ?? null) ? ['value' => $const['value']] : [];
                $members[] = new MockMember($const['name'], 'const', $declarer, $declarer, $value, type: $const['type'], implTrait: $implTrait);
            }
        }

        return $members;
    }

    /** @param array<string, mixed> $attributes */
    private static function isConstructorOrStatic(string $name, array $attributes, string $language): bool
    {
        $decorators = is_array($attributes['decorators'] ?? null) ? $attributes['decorators'] : [];

        return match ($language) {
            'typescript' => $name === 'constructor' || ($attributes['static'] ?? false) === true,
            // A magic method (`__call`, `__get`, `__toString`) is no stub of a member; `__call` makes the list open.
            'php' => str_starts_with($name, '__') || ($attributes['static'] ?? false) === true,
            'python' => preg_match('/^__\w+__$/', $name) === 1 || array_intersect($decorators, ['staticmethod', 'classmethod']) !== [],
            default => false,
        };
    }

    /**
     * Whether a member is part of what callers see: absent visibility is
     * public from every worker; PHP sends `public` too; a Python `_x`
     * (protected) and a Rust restricted one (`pub(crate)`) are kept.
     *
     * @param array<string, mixed> $attributes
     */
    private static function isVisible(array $attributes, string $language): bool
    {
        $visibility = $attributes['visibility'] ?? null;

        return $visibility === null || $visibility === 'public' || ($language === 'python' && $visibility === 'protected')
            || ($language === 'rust' && is_string($visibility) && str_starts_with($visibility, 'pub('));
    }

    /**
     * The first type parameter of `$declarer` an inherited signature names,
     * whose argument for the target is not recorded; null when it names none.
     *
     * @param array<string, mixed> $signature
     */
    private static function inheritedParameter(NodeFact $declarer, array $signature): ?string
    {
        $own = array_map(TypeText::parameterName(...), is_array($signature['type_params'] ?? null) ? array_filter($signature['type_params'], is_string(...)) : []);
        $names = array_values(array_diff(array_map(TypeText::parameterName(...), self::typeParamsOf($declarer)), $own));
        $texts = [is_string($signature['returns'] ?? null) ? $signature['returns'] : ''];
        foreach ($signature['params'] as $param) {
            $texts[] = is_array($param) && is_string($param['type'] ?? null) ? $param['type'] : '';
        }
        foreach ($names as $name) {
            foreach ($texts as $text) {
                if (TypeText::mentions($text, [$name])) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * The names a lineage's `member_names` lists that no child node, field
     * or left-out member answers: an accessor, a PHP `@method`, a member the
     * type checker reads without a node. A Python list also names data
     * attributes, which a mock built from the class has, so it is not read.
     *
     * @param array<string, true> $seen
     * @param array<string, mixed> $fields
     * @return list<string>
     */
    private static function unseenNames(TestFidelityGraph $graph, NodeFact $target, string $language, array $seen, array $fields): array
    {
        if (!in_array($language, ['typescript', 'php'], true)) {
            return [];
        }
        $names = [];
        foreach ($graph->lineage($target) as $type) {
            $own = FieldLists::of($graph, $type)?->fields() ?? [];
            foreach (is_array($type->attributes['member_names'] ?? null) ? $type->attributes['member_names'] : [] as $name) {
                $key = $language === 'php' ? strtolower((string) $name) : (string) $name;
                if (is_string($name) && !isset($seen[$key]) && !isset($fields[$name]) && !isset($own[$name]) && !self::isBuiltIn($name, $language, $type)) {
                    $names[$name] = true;
                }
            }
        }
        $names = array_map(strval(...), array_keys($names));
        sort($names, SORT_STRING);

        return $names;
    }

    /** Whether a listed name is one a mock needs nothing for: a constructor, an enum's built-ins. */
    private static function isBuiltIn(string $name, string $language, NodeFact $type): bool
    {
        return $language === 'php' ? strcasecmp($name, '__construct') === 0 || $type->kind === 'enum' : $name === 'constructor';
    }

    /**
     * Why the member list may not be the whole of it; empty when it is.
     *
     * @return list<string>
     */
    private static function openness(TestFidelityGraph $graph, NodeFact $target, string $language, bool $cut): array
    {
        $why = [];
        if ($cut) {
            $why[] = sprintf('%s inherits from more types than one read takes; members past them are not listed.', $target->displayName);
        }
        // A Rust auto trait needs no impl: every mock is Send, Sync, Unpin and Sized as its fields are.
        $unseen = $language === 'rust'
            ? $graph->lineageThrough($target, static fn(string $parent): bool => preg_match('/(?:^|:)(?:Send|Sync|Unpin|Sized)$/', $parent) !== 1)[1]
            : $graph->hasUnseenAncestor($target);
        if ($unseen) {
            $why[] = $language === 'rust'
                ? ($target->kind === 'interface'
                    ? sprintf('%s has a supertrait outside the scan: mock! needs its impl too, and its methods are not listed.', $target->displayName)
                    : sprintf('%s implements a trait outside the scan: its methods the impl does not write (defaults) are not listed.', $target->displayName))
                : sprintf('%s inherits from a type outside the scan, whose members are not listed.', $target->displayName);
        }
        if ($language !== 'rust') {
            foreach ($graph->lineage($target) as $type) {
                $names = $type->attributes['member_names'] ?? null;
                if (!is_array($names) || ($type->attributes['member_names_open'] ?? false) !== false) {
                    $why[] = sprintf('%s has no closed member list (a name may come from elsewhere).', $type->displayName);
                }
            }
        }
        if ($language === 'python' && $graph->hasRewritableLineage($target)) {
            $why[] = sprintf('%s or a base may rewrite its methods (a metaclass, a class decorator or __init_subclass__).', $target->displayName);
        }
        if ($language === 'php' && $graph->member($target, '__call') !== null) {
            $why[] = sprintf('%s answers any method name through __call.', $target->displayName);
        }

        return $why;
    }
}

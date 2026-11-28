<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Synthesis;

use Closure;
use InvalidArgumentException;
use Knossos\Scanner\Protocol\NodeFact;

/**
 * A mockall mock of a Rust trait or struct (`mock! { pub Repo {} impl Repo
 * for Repo { … } }` and a test setting each expectation), or a hand-written
 * fake of a trait (`pub struct FakeRepo; impl Repo for FakeRepo { … }`).
 *
 * Each method keeps its real receiver (`&self`, `&mut self`, `self`,
 * `self: Box<Self>`), its `async` and `unsafe`, and its type parameters and
 * where clause as written; a trait's associated function is declared and
 * set through its context, a borrowed return through `return_const`, and an
 * associated type or const gets a placeholder (`type Output = ();`), listed
 * in `unknown`. A supertrait in the project gets an `impl` of its own, and
 * so does `std::fmt::Display`, whose one method is known; any other outside
 * the scan leaves the template incomplete. A struct's inherent
 * methods go in its own block, and each trait it implements gets the `impl`
 * the source writes (`impl Repo<String> for Store`). A generic trait keeps
 * its parameters on the mock, its bounds as written, and each method naming
 * one lists it in `unknown`.
 *
 * Each declaring item's module is glob-imported beside its own `use`, so a
 * type or trait a signature or impl header names resolves as it does there;
 * a trait's own associated type is written `<Self as Trait>::X` in mock!; a
 * generic mock's `new()` binds each type parameter to a marked `()`; and a
 * trait method whose type parameter is not `'static`, which mockall cannot
 * mock in a trait impl, makes the template say it is incomplete (G6-03).
 *
 * A hand-written fake of a struct, or of a generic trait, is refused: a
 * struct has no trait to implement, and a fake of a generic trait needs a
 * type to bind its parameters, which only its author can choose.
 *
 * @phpstan-import-type Record from ExampleValue
 * @phpstan-import-type EnumCase from ExampleValue
 */
final readonly class RustTemplate implements MockTemplate
{
    /** @param Closure(string, string): (Record|EnumCase|null) $lookup */
    public function __construct(private Closure $lookup) {}

    /** A `mock!` block and its expectations, or a hand-written fake; {@see MockTemplate::render()}. */
    public function render(MockMembers $members, string $framework, bool $values): string
    {
        $target = $members->target;
        $name = $target->displayName;
        $trait = $target->kind === 'interface';
        $params = $members->typeParams();
        if ($framework === 'hand-written' && (!$trait || $params !== [])) {
            throw new InvalidArgumentException($trait
                ? sprintf('%s is a generic trait: a hand-written fake needs a type to bind %s, which only its author can choose; use mockall.', $target->canonicalName, implode(', ', array_map(TypeText::parameterName(...), $params)))
                : sprintf('%s is a struct: a hand-written fake implements a trait, and a struct has none to implement; use mockall, or fake a trait it implements.', $target->canonicalName));
        }
        // The target's own impl comes first, even with nothing to give: mock! and a fake need it.
        $declarers = [$target->localId => $target];
        $groups = [];
        $imports = [];
        // Each member rendered once: its imports and its expectation come from the one rendering (G6-08g).
        $parts = [];
        foreach ($members->members as $member) {
            $declarers[$member->declarer->localId] = $member->declarer;
            $groups[self::group($member)][] = $member;
            $parts[spl_object_id($member)] = $this->member($members, $member, $framework, $values);
            $imports = [...$imports, ...$parts[spl_object_id($member)]['imports']];
        }
        $uses = [...($framework === 'mockall' ? ['use mockall::mock;'] : []), ...array_map(static fn(NodeFact $declarer): string => sprintf('use %s;', $declarer->canonicalName), $declarers), ...$imports, ...self::moduleGlobs($declarers)];
        $uses = array_values(array_unique($uses));
        $unmockable = $framework === 'mockall' && $trait ? self::unmockable($members) : [];
        $lines = [
            sprintf('// knossos synthesize_mock: %s (%s), from %s:%d', $target->canonicalName, $framework, $target->evidence->relativePath, $target->evidence->startLine),
            '// ' . ($unmockable === [] ? $members->summary() : str_replace('; complete', '; incomplete: see omitted and notes', $members->summary())),
            ...$uses,
            '',
        ];
        if ($framework === 'hand-written') {
            $lines[] = sprintf('pub struct Fake%s;', $name);
            foreach ($members->spelledSupertraits() as $_) {
                $lines = [...$lines, '', sprintf('impl std::fmt::Display for Fake%s {', $name), "    fn fmt(&self, _f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {", '        Ok(())', '    }', '}'];
            }
            foreach ($declarers as $id => $declarer) {
                $lines[] = '';
                $lines[] = sprintf('impl %s for Fake%s {', $declarer->displayName, $name);
                // A method's fake is its rendering; an associated item sits one level shallower in a fake's impl than in mock!.
                $bodies = array_map(fn(MockMember $member): string => $member->kind === 'method' ? $parts[spl_object_id($member)]['rendered'] : $this->fake($member, $values)['rendered'], $groups[$id] ?? []);
                $lines[] = implode("\n\n", $bodies);
                $lines[] = '}';
            }

            return implode("\n", $lines) . "\n";
        }
        $generics = $params === [] ? '' : '<' . implode(', ', $params) . '>';
        $arguments = $params === [] ? '' : '<' . implode(', ', array_map(TypeText::parameterName(...), $params)) . '>';
        $where = is_string($target->attributes['where'] ?? null) ? ' ' . $target->attributes['where'] : '';
        $lines[] = 'mock! {';
        if ($trait) {
            $lines[] = sprintf('    pub %s%s {}', $name, $generics);
            foreach ($declarers as $id => $declarer) {
                $own = $declarer->localId === $target->localId;
                $lines[] = sprintf('    impl%s %s%s for %s%s%s {', $own ? $generics : '', $declarer->displayName, $own ? $arguments : '', $name, $arguments, $own ? $where : '');
                $lines = [...$lines, ...self::declarations($groups[$id] ?? [])];
                $lines[] = '    }';
            }
            // A std supertrait outside the scan whose method is known: mock! needs its impl too.
            foreach ($members->spelledSupertraits() as $_) {
                $lines = [...$lines, sprintf('    impl%s std::fmt::Display for %s%s {', $generics, $name, $arguments), "        fn fmt<'a>(&self, f: &mut std::fmt::Formatter<'a>) -> std::fmt::Result;", '    }'];
            }
        } else {
            // A struct's inherent methods go in its own block; each trait it implements gets the impl it writes.
            $inherent = self::declarations($groups[''] ?? []);
            $lines = [...$lines, ...($inherent === [] ? [sprintf('    pub %s%s {}', $name, $generics)] : [sprintf('    pub %s%s {', $name, $generics), ...$inherent, '    }'])];
            // Every impl the worker saw is given, a method-less one as an empty block; then any a worker without the list implies.
            $blocks = $members->impls;
            foreach (array_keys($groups) as $key) {
                if ($key !== '' && !in_array(substr((string) $key, 1), array_column($blocks, 'trait'), true)) {
                    $blocks[] = ['trait' => substr((string) $key, 1), 'unsafe' => false];
                }
            }
            foreach ($blocks as $block) {
                $body = self::declarations($groups['#' . $block['trait']] ?? []);
                $header = sprintf('    %simpl %s for %s%s {', $block['unsafe'] ? 'unsafe ' : '', $block['trait'], $name, $arguments);
                $lines = [...$lines, ...($body === [] ? [$header . '}'] : [$header, ...$body, '    }'])];
            }
        }
        $lines = [...$lines, '}', '', '#[test]', sprintf('fn %s_mock() {', TypeText::snake($name)), sprintf('    let mut mock = Mock%s%s::new();', $name, self::boundArguments($params))];
        foreach ($members->members as $member) {
            if ($member->kind === 'method') {
                $lines[] = $parts[spl_object_id($member)]['rendered'];
            }
        }
        if ($trait && $members->spelledSupertraits() !== []) {
            $lines[] = '    mock.expect_fmt().returning(|_f| Ok(()));';
        }
        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    /** {@inheritDoc} */
    public function member(MockMembers $members, MockMember $member, string $framework, bool $values): array
    {
        if ($member->kind !== 'method') {
            return ['rendered' => '        ' . self::associatedItem($member), 'unknown' => self::associatedUnknown($member), 'imports' => []];
        }
        if ($framework === 'hand-written') {
            return $this->fake($member, $values);
        }
        $unknown = [...(self::isUnmockable($members, $member) ? [self::unmockableUnknown($members, $member)] : self::typeParameterUnknowns($member)), ...self::traitParameterUnknowns($members, $member)];
        $returns = (string) $member->returns();
        if (!$member->associated && str_starts_with($returns, '&')) {
            // mockall gives a borrowed return through return_const, from the owned value it lends.
            $owned = self::ownedZero($returns) ?? (new ExampleValue('rust', $this->lookup))->placeholder('the value ' . $returns . ' lends')['value'];

            return [
                'rendered' => sprintf('    mock.expect_%s().return_const(%s);', $member->name, $values ? $owned : 'todo!()'),
                'unknown' => [...$unknown, 'returns a reference: return_const takes the owned value it lends'],
                'imports' => [],
            ];
        }
        $example = $this->example($member, $values);
        $unknown = [...$unknown, ...$example['unknown']];
        $closure = sprintf('|%s| %s', implode(', ', self::closureParams($member)), $example['value']);
        if ($member->associated) {
            $context = sprintf('%s_context', $member->name);

            return [
                'rendered' => sprintf("    let %s = Mock%s::%s();\n    %s.expect().returning(%s);", $context, $members->target->displayName, $context, $context, $closure),
                'unknown' => $unknown,
                'imports' => $example['imports'],
            ];
        }

        return ['rendered' => sprintf('    mock.expect_%s().returning(%s);', $member->name, $closure), 'unknown' => $unknown, 'imports' => $example['imports']];
    }

    /**
     * The owned zero a borrowed return lends from: `String::new()` for `&str`
     * or `&String`, `Vec::new()` for `&[T]` or `&Vec<T>`; null for any other.
     * mockall 0.13.1 compiles `return_const(Vec::new())` for a `&[u8]` return
     * and lends the slice (checked offline, G6-08g).
     */
    private static function ownedZero(string $returns): ?string
    {
        $lent = trim((string) preg_replace("/^&(?:'\\w+\\s+)?(?:mut\\s+)?/", '', $returns));

        return match (true) {
            $lent === 'str' || $lent === 'String' => 'String::new()',
            str_starts_with($lent, '[') || str_starts_with($lent, 'Vec<') => 'Vec::new()',
            default => null,
        };
    }

    /** The block a member belongs in: its declaring trait, or for a struct `''` (inherent) or `#` and the trait it implements. */
    private static function group(MockMember $member): string
    {
        return $member->declarer->kind === 'interface' ? $member->declarer->localId : ($member->implTrait === null ? '' : '#' . $member->implTrait);
    }

    /**
     * Each member's line in a `mock!` block.
     *
     * @param list<MockMember> $members
     * @return list<string>
     */
    private static function declarations(array $members): array
    {
        return array_map(static fn(MockMember $member): string => '        ' . ($member->kind === 'method' ? self::qualified(self::declaration($member), $member) . ';' : self::associatedItem($member)), $members);
    }

    /**
     * A trait method's declaration with each `Self::X` written `<Self as
     * Trait>::X`, which mock! needs to tell the trait's associated type from
     * one of the mock struct's own (E0223 otherwise, G6-03).
     */
    private static function qualified(string $declaration, MockMember $member): string
    {
        if ($member->declarer->kind !== 'interface') {
            return $declaration;
        }

        return (string) preg_replace('/(?<![\w:>])Self::(?=[A-Za-z_])/', '<Self as ' . $member->declarer->displayName . '>::', $declaration);
    }

    /**
     * A glob import of each declaring item's module, so the project types
     * and traits the template's signatures and impl headers name resolve as
     * they do beside the declaration (`Record`, `Into2<Aa>`, G6-03).
     *
     * @param array<string, NodeFact> $declarers
     * @return list<string>
     */
    private static function moduleGlobs(array $declarers): array
    {
        $globs = [];
        foreach ($declarers as $declarer) {
            $cut = strrpos($declarer->canonicalName, '::');
            if ($cut !== false) {
                $globs[] = sprintf('use %s::*;', substr($declarer->canonicalName, 0, $cut));
            }
        }

        return $globs;
    }

    /**
     * The turbofish a generic mock's `new()` needs to bind its type
     * parameters, each a `()` placeholder marked with a `knossos: bind T` comment;
     * empty for a mock with none, or with lifetimes only.
     *
     * @param list<string> $params
     */
    private static function boundArguments(array $params): string
    {
        $bound = array_map(static fn(string $param): string => str_starts_with($param, "'") ? "'_" : sprintf('() /* knossos: bind %s */', TypeText::parameterName($param)), $params);

        return array_filter($params, static fn(string $param): bool => !str_starts_with($param, "'")) === [] ? '' : '::<' . implode(', ', $bound) . '>';
    }

    /**
     * The trait's methods mockall cannot mock: a type parameter not bound
     * `'static`, which mockall needs while the trait's impl may not add it
     * (E0310, and E0276 when added, G6-03).
     *
     * @return list<MockMember>
     */
    private static function unmockable(MockMembers $members): array
    {
        return array_values(array_filter($members->members, static fn(MockMember $member): bool => self::isUnmockable($members, $member)));
    }

    /** Whether a trait method has a type parameter without a `'static` bound, which mockall cannot mock (G6-03). */
    private static function isUnmockable(MockMembers $members, MockMember $member): bool
    {
        if ($member->kind !== 'method' || $members->target->kind !== 'interface') {
            return false;
        }
        foreach ($member->typeParams() as $param) {
            if (!str_starts_with($param, "'") && preg_match("/:\s*(?:.*\+\s*)?'static\b/", $param) !== 1) {
                return true;
            }
        }

        return false;
    }

    /** The member's unknown note: why mockall cannot mock it, and that the trait needs a hand-written fake (G6-03). */
    private static function unmockableUnknown(MockMembers $members, MockMember $member): string
    {
        return sprintf("%s: mockall cannot mock a generic method whose type parameter is not 'static in an impl of %s; this mock does not compile until it is removed, so fake the trait by hand (framework hand-written)", $member->name, $members->target->displayName);
    }

    /** An associated type or const as an impl gives it, a placeholder: `type Output = ();`, `const LIMIT: usize = 0;`. */
    private static function associatedItem(MockMember $member): string
    {
        // A struct's impl gives them as it writes them; a trait's mock needs a placeholder.
        if ($member->kind === 'type') {
            return sprintf('type %s = %s;', $member->name, $member->implTrait === null ? '()' : (string) $member->type);
        }
        if (is_string($member->signature['value'] ?? null)) {
            return sprintf('const %s: %s = %s;', $member->name, (string) $member->type, $member->signature['value']);
        }
        $zero = (new ExampleValue('rust', static fn(): ?array => null))->of($member->type, '');

        return sprintf('const %s: %s = %s;', $member->name, (string) $member->type, $zero['value'] ?? 'todo!()');
    }

    /**
     * What in an associated type or constant the template leaves as a placeholder.
     *
     * @return list<string>
     */
    private static function associatedUnknown(MockMember $member): array
    {
        if (($member->kind === 'type' && $member->implTrait !== null) || is_string($member->signature['value'] ?? null)) {
            return [];
        }

        return [$member->kind === 'type'
            ? sprintf('type %s: () is a placeholder%s; choose the type a test needs', $member->name, $member->type === $member->name ? '' : ', and may not meet ' . (string) $member->type)
            : sprintf('const %s: a placeholder value', $member->name)];
    }

    /**
     * The generic trait's parameters a method names, which the mock's `new()` cannot bind.
     *
     * @return list<string>
     */
    private static function traitParameterUnknowns(MockMembers $members, MockMember $member): array
    {
        $texts = [(string) $member->returns(), ...array_map(static fn(array $param): string => (string) ($param['type'] ?? ''), $member->params())];
        $names = array_map(TypeText::parameterName(...), array_values(array_filter($members->typeParams(), static fn(string $param): bool => !str_starts_with($param, "'"))));

        return array_map(
            static fn(string $name): string => sprintf('type parameter %s of %s: bind it, Mock%s::<…>::new()', $name, $members->target->displayName, $members->target->displayName),
            array_values(array_filter($names, static fn(string $name): bool => TypeText::mentions(implode(' ', $texts), [$name]))),
        );
    }

    /**
     * A hand-written fake's method, its body the example value.
     *
     * @return array{rendered: string, unknown: list<string>, imports: list<string>}
     */
    private function fake(MockMember $member, bool $values): array
    {
        if ($member->kind !== 'method') {
            return ['rendered' => '    ' . self::associatedItem($member), 'unknown' => self::associatedUnknown($member), 'imports' => []];
        }
        $example = $this->example($member, $values);
        // A method returning nothing has an empty body when values are stubbed.
        $body = $values && $member->returns() === null ? ' {}' : sprintf(" {\n        %s\n    }", $example['value']);

        return [
            'rendered' => '    ' . self::declaration($member, underscored: true) . $body,
            'unknown' => [...self::typeParameterUnknowns($member), ...$example['unknown']],
            'imports' => $example['imports'],
        ];
    }

    /**
     * The value a method's stub returns: its example, `todo!()` when values are not stubbed.
     *
     * @return array{value: string, unknown: list<string>, imports: list<string>}
     */
    private function example(MockMember $member, bool $values): array
    {
        if (!$values) {
            return ['value' => 'todo!()', 'unknown' => [], 'imports' => []];
        }
        $returns = $member->returns();
        $own = array_map(TypeText::parameterName(...), $member->typeParams());
        $example = $returns !== null && TypeText::mentions($returns, $own)
            ? (new ExampleValue('rust', $this->lookup))->placeholder($returns)
            : (new ExampleValue('rust', $this->lookup))->of($returns ?? '()', $member->declarer->evidence->relativePath);

        return ['value' => $example['value'] ?? 'panic!()', 'unknown' => $example['unknown'], 'imports' => $example['imports']];
    }

    /**
     * What a generic method's expectation needs and the template cannot give.
     *
     * @return list<string>
     */
    private static function typeParameterUnknowns(MockMember $member): array
    {
        return array_map(static fn(string $param): string => sprintf('type parameter %s: name it with expect_%s::<…>()', TypeText::parameterName($param), $member->name), array_values(array_filter($member->typeParams(), static fn(string $param): bool => !str_starts_with($param, "'"))));
    }

    /**
     * The closure's parameters: one per passed parameter, each unused, so prefixed `_`.
     *
     * @return list<string>
     */
    private static function closureParams(MockMember $member): array
    {
        return array_map(static fn(array $param): string => self::unused((string) ($param['name'] ?? '_')), $member->params());
    }

    /** A closure parameter's name, prefixed with `_` so an unused one raises no warning. */
    private static function unused(string $name): string
    {
        return $name === '_' || str_starts_with($name, '_') ? $name : '_' . $name;
    }

    /** A method's declaration as its source writes it: `async fn get<T>(&self, key: &str) -> T where T: Clone`. */
    private static function declaration(MockMember $member, bool $underscored = false): string
    {
        $parts = [];
        $receiver = $member->signature['receiver'] ?? null;
        if (is_string($receiver)) {
            $parts[] = $receiver;
        }
        foreach ($member->params() as $param) {
            $name = (string) ($param['name'] ?? '_');
            $parts[] = ($underscored ? self::unused($name) : $name) . ': ' . (string) ($param['type'] ?? '_');
        }
        $where = is_string($member->signature['where'] ?? null) ? ' ' . $member->signature['where'] : '';
        $returns = $member->returns();

        return sprintf(
            '%s%sfn %s%s(%s)%s%s',
            $member->async() ? 'async ' : '',
            ($member->node->attributes['unsafe'] ?? false) === true ? 'unsafe ' : '',
            $member->name,
            $member->typeParams() === [] ? '' : '<' . implode(', ', $member->typeParams()) . '>',
            implode(', ', $parts),
            $returns === null ? '' : ' -> ' . $returns,
            $where,
        );
    }
}

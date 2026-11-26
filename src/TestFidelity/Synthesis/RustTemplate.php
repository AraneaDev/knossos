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
 * in `unknown`. A supertrait in the project gets an `impl` of its own; one
 * outside the scan leaves the template incomplete. A struct's inherent
 * methods go in its own block, and each trait it implements gets the `impl`
 * the source writes (`impl Repo<String> for Store`). A generic trait keeps
 * its parameters on the mock, its bounds as written, and each method naming
 * one lists it in `unknown`.
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
        foreach ($members->members as $member) {
            $declarers[$member->declarer->localId] = $member->declarer;
            $groups[self::group($member)][] = $member;
            $imports = [...$imports, ...$this->member($members, $member, $framework, $values)['imports']];
        }
        $uses = [...($framework === 'mockall' ? ['use mockall::mock;'] : []), ...array_map(static fn(NodeFact $declarer): string => sprintf('use %s;', $declarer->canonicalName), $declarers), ...$imports];
        $uses = array_values(array_unique($uses));
        $lines = [
            sprintf('// knossos synthesize_mock: %s (%s), from %s:%d', $target->canonicalName, $framework, $target->evidence->relativePath, $target->evidence->startLine),
            '// ' . $members->summary(),
            ...$uses,
            '',
        ];
        if ($framework === 'hand-written') {
            $lines[] = sprintf('pub struct Fake%s;', $name);
            foreach ($declarers as $id => $declarer) {
                $lines[] = '';
                $lines[] = sprintf('impl %s for Fake%s {', $declarer->displayName, $name);
                $bodies = array_map(fn(MockMember $member): string => $this->fake($member, $values)['rendered'], $groups[$id] ?? []);
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
        } else {
            // A struct's inherent methods go in its own block; each trait it implements gets the impl it writes.
            $inherent = self::declarations($groups[''] ?? []);
            $lines = [...$lines, ...($inherent === [] ? [sprintf('    pub %s%s {}', $name, $generics)] : [sprintf('    pub %s%s {', $name, $generics), ...$inherent, '    }'])];
            foreach ($groups as $key => $group) {
                if ($key !== '') {
                    $lines = [...$lines, sprintf('    impl %s for %s%s {', substr((string) $key, 1), $name, $arguments), ...self::declarations($group), '    }'];
                }
            }
        }
        $lines = [...$lines, '}', '', '#[test]', sprintf('fn %s_mock() {', TypeText::snake($name)), sprintf('    let mut mock = Mock%s::new();', $name)];
        foreach ($members->members as $member) {
            if ($member->kind === 'method') {
                $lines[] = $this->member($members, $member, $framework, $values)['rendered'];
            }
        }
        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    public function member(MockMembers $members, MockMember $member, string $framework, bool $values): array
    {
        if ($member->kind !== 'method') {
            return ['rendered' => '        ' . self::associatedItem($member), 'unknown' => [self::associatedUnknown($member)], 'imports' => []];
        }
        if ($framework === 'hand-written') {
            return $this->fake($member, $values);
        }
        $unknown = [...self::typeParameterUnknowns($member), ...self::traitParameterUnknowns($members, $member)];
        $returns = (string) $member->returns();
        if (!$member->associated && str_starts_with($returns, '&')) {
            // mockall gives a borrowed return through return_const, from the owned value it lends.
            $placeholder = (new ExampleValue('rust', $this->lookup))->placeholder('the value ' . $returns . ' lends');

            return [
                'rendered' => sprintf('    mock.expect_%s().return_const(%s);', $member->name, $values ? $placeholder['value'] : 'todo!()'),
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
        return array_map(static fn(MockMember $member): string => '        ' . ($member->kind === 'method' ? self::declaration($member) . ';' : self::associatedItem($member)), $members);
    }

    /** An associated type or const as an impl gives it, a placeholder: `type Output = ();`, `const LIMIT: usize = 0;`. */
    private static function associatedItem(MockMember $member): string
    {
        if ($member->kind === 'type') {
            return sprintf('type %s = ();', $member->name);
        }
        $zero = (new ExampleValue('rust', static fn(): ?array => null))->of($member->type, '');

        return sprintf('const %s: %s = %s;', $member->name, (string) $member->type, $zero['value'] ?? 'todo!()');
    }

    private static function associatedUnknown(MockMember $member): string
    {
        return $member->kind === 'type'
            ? sprintf('type %s: () is a placeholder%s; choose the type a test needs', $member->name, $member->type === $member->name ? '' : ', and may not meet ' . (string) $member->type)
            : sprintf('const %s: a placeholder value', $member->name);
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
            return ['rendered' => '    ' . self::associatedItem($member), 'unknown' => [self::associatedUnknown($member)], 'imports' => []];
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

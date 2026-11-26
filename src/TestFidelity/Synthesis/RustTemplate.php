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
 * set through its context. A supertrait in the project gets an `impl` of
 * its own; one outside the scan leaves the template incomplete. A generic
 * trait keeps its parameters on the mock, its bounds as written.
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
            $groups[$member->declarer->localId][] = $member;
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
                foreach ($groups[$id] ?? [] as $member) {
                    $lines[] = '        ' . self::declaration($member) . ';';
                }
                $lines[] = '    }';
            }
        } else {
            $methods = array_map(static fn(MockMember $member): string => '        ' . self::declaration($member) . ';', $members->members);
            $lines = [...$lines, ...($methods === [] ? [sprintf('    pub %s%s {}', $name, $generics)] : [sprintf('    pub %s%s {', $name, $generics), ...$methods, '    }'])];
        }
        $lines = [...$lines, '}', '', '#[test]', sprintf('fn %s_mock() {', TypeText::snake($name)), sprintf('    let mut mock = Mock%s::new();', $name)];
        foreach ($members->members as $member) {
            $lines[] = $this->member($members, $member, $framework, $values)['rendered'];
        }
        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    public function member(MockMembers $members, MockMember $member, string $framework, bool $values): array
    {
        if ($framework === 'hand-written') {
            return $this->fake($member, $values);
        }
        $example = $this->example($member, $values);
        $closure = sprintf('|%s| %s', implode(', ', self::closureParams($member)), $example['value']);
        $unknown = [...self::typeParameterUnknowns($member), ...$example['unknown']];
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
     * A hand-written fake's method, its body the example value.
     *
     * @return array{rendered: string, unknown: list<string>, imports: list<string>}
     */
    private function fake(MockMember $member, bool $values): array
    {
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

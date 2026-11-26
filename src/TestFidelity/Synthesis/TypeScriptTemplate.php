<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Synthesis;

use Closure;

/**
 * A vitest or jest mock of a TypeScript class or interface: an object
 * literal of `vi.fn<(id: string) => number>()` stubs (the current
 * function-type generic, not Momus's removed `vi.fn<[id: string], number>()`),
 * an interface's properties as values, checked with `satisfies`.
 *
 * A complete interface without type parameters is checked against itself;
 * anything else against `Partial<…>`, so a member left out does not fail
 * to compile while a member present must still fit. The type's own type
 * parameters are out of scope where the mock is written and become
 * `unknown`, in the members and in the checked type. A generic method is
 * omitted ({@see MockMembers}): `Mock<T>` loses its type parameters, so no
 * stub fits it. An untyped parameter is `unknown`, which any argument
 * fits; an untyped return is the checker's own
 * (`ReturnType<Svc['load']>`, an `async` one's Promise included), left
 * unstubbed. Each is listed in the member's `unknown`.
 *
 * @phpstan-import-type Record from ExampleValue
 * @phpstan-import-type EnumCase from ExampleValue
 * @phpstan-import-type Rendered from MockTemplate
 */
final readonly class TypeScriptTemplate implements MockTemplate
{
    /** @param Closure(string, string): (Record|EnumCase|null) $lookup */
    public function __construct(private Closure $lookup) {}

    public function render(MockMembers $members, string $framework, bool $values): string
    {
        $target = $members->target;
        $name = $target->displayName;
        $lines = [
            sprintf('// knossos synthesize_mock: %s (%s), from %s:%d', $target->canonicalName, $framework, $target->evidence->relativePath, $target->evidence->startLine),
            '// ' . $members->summary(),
            $framework === 'jest' ? "import { jest } from '@jest/globals';" : "import { vi } from 'vitest';",
            '',
            sprintf('const %sMock = {', lcfirst($name)),
        ];
        foreach ($members->members as $member) {
            $lines[] = $this->member($members, $member, $framework, $values)['rendered'];
        }
        $strict = $members->complete && $target->kind === 'interface' && $members->typeParams() === [];
        $lines[] = sprintf('} satisfies %s;', $strict ? self::checked($members) : 'Partial<' . self::checked($members) . '>');

        return implode("\n", $lines) . "\n";
    }

    public function member(MockMembers $members, MockMember $member, string $framework, bool $values): array
    {
        $examples = new ExampleValue('typescript', $this->lookup, $framework === 'jest' ? 'jest.fn()' : 'vi.fn()');
        $classParams = array_map(TypeText::parameterName(...), $members->typeParams());
        $file = $member->declarer->evidence->relativePath;
        $key = preg_match('/^[A-Za-z_$][\w$]*$/', $member->name) === 1 ? $member->name : (string) json_encode($member->name);
        if ($member->kind === 'property') {
            $unknown = $member->type === null ? ['type: untyped'] : [];
            $type = TypeText::substitute($member->type ?? 'unknown', $classParams, 'unknown');
            $unknown = [...$unknown, ...self::parametersNamed($member->type ?? '', $classParams)];
            $example = $values ? $examples->of($type, $file) : ['value' => sprintf('undefined as unknown as %s', $type), 'unknown' => [], 'imports' => []];

            return [
                'rendered' => sprintf("  // %s\n  %s: %s,", self::propertyLine($member), $key, $example['value'] ?? 'undefined'),
                'unknown' => [...$unknown, ...$example['unknown']],
                'imports' => $example['imports'],
            ];
        }
        $outer = $classParams;
        $unknown = [];
        $params = [];
        foreach ($member->params() as $i => $param) {
            $variadic = ($param['variadic'] ?? false) === true;
            $name = is_string($param['name'] ?? null) && preg_match('/^[A-Za-z_$][\w$]*$/', $param['name']) === 1 ? $param['name'] : 'arg' . $i;
            $written = is_string($param['type'] ?? null) ? $param['type'] : null;
            if ($written === null) {
                $unknown[] = sprintf('param %s: untyped', $name);
            }
            $unknown = [...$unknown, ...self::parametersNamed($written ?? '', $outer)];
            $type = self::thisless(TypeText::substitute($written ?? ($variadic ? 'unknown[]' : 'unknown'), $outer, 'unknown'), $members);
            $params[] = ($variadic ? '...' : '') . $name . (($param['optional'] ?? false) === true && !$variadic ? '?' : '') . ': ' . $type;
        }
        $written = $member->returns();
        $unknown = [...$unknown, ...self::parametersNamed($written ?? '', $outer)];
        // An untyped return is the one the type checker infers, which `unknown` would not fit: name it so.
        $returns = $written === null ? sprintf("ReturnType<%s['%s']>", self::checked($members), $member->name) : self::thisless(TypeText::substitute($written, $outer, 'unknown'), $members);
        $stub = sprintf('%s.fn<(%s) => %s>()', $framework === 'jest' ? 'jest' : 'vi', implode(', ', $params), $returns);
        $imports = [];
        if ($written === null) {
            // Nor can a value be chosen for it.
            $unknown[] = 'returns: untyped (the inferred type, unstubbed)';
        } elseif ($values) {
            $promised = TypeText::generic($returns);
            $resolved = ($promised[0] ?? null) === 'Promise';
            $example = $examples->of($resolved ? ($promised[1][0] ?? 'unknown') : $returns, $file);
            $unknown = [...$unknown, ...$example['unknown']];
            $imports = $example['imports'];
            if ($example['value'] !== null) {
                $stub .= sprintf('.%s(%s)', $resolved ? 'mockResolvedValue' : 'mockReturnValue', $example['value']);
            }
        }

        return ['rendered' => sprintf("  // %s\n  %s: %s,", self::methodLine($member), $key, $stub), 'unknown' => $unknown, 'imports' => $imports];
    }

    /** `$type` with `this`, which has no meaning in an object literal's stub (TS2526), as the checked type. */
    private static function thisless(string $type, MockMembers $members): string
    {
        return TypeText::substitute($type, ['this'], self::checked($members));
    }

    /** The type the mock is checked against: the target, each of its type parameters `unknown` (`Box<unknown>`). */
    private static function checked(MockMembers $members): string
    {
        $params = $members->typeParams();

        return $members->target->displayName . ($params === [] ? '' : '<' . implode(', ', array_fill(0, count($params), 'unknown')) . '>');
    }

    /**
     * `type parameter T` for each of `$names` that `$text` names.
     *
     * @param list<string> $names
     * @return list<string>
     */
    private static function parametersNamed(string $text, array $names): array
    {
        return array_values(array_map(static fn(string $name): string => 'type parameter ' . $name, array_filter($names, static fn(string $name): bool => TypeText::mentions($text, [$name]))));
    }

    /** A method as its source declares it: `totalFor(id: string, opts?: number): number`, an untyped part left bare. */
    private static function methodLine(MockMember $member): string
    {
        $params = array_map(static fn(array $param): string => (($param['variadic'] ?? false) === true ? '...' : '') . (string) ($param['name'] ?? '_')
            . (($param['optional'] ?? false) === true ? '?' : '') . (is_string($param['type'] ?? null) ? ': ' . $param['type'] : ''), $member->params());
        $generics = $member->typeParams() === [] ? '' : '<' . implode(', ', $member->typeParams()) . '>';

        return sprintf('%s%s%s(%s)%s', $member->async() ? 'async ' : '', $member->name, $generics, implode(', ', $params), $member->returns() === null ? '' : ': ' . $member->returns());
    }

    private static function propertyLine(MockMember $member): string
    {
        return $member->name . ($member->optional ? '?' : '') . ($member->type === null ? '' : ': ' . $member->type);
    }
}

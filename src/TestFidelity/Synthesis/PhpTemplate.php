<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Synthesis;

use Closure;

/**
 * A PHPUnit, Mockery or Prophecy double of a PHP class or interface, as the
 * statements of a test method: `$this->createMock(X::class)` and
 * `->method('m')->willReturn(…)`, `\Mockery::mock(X::class)` and
 * `->shouldReceive('m')->andReturn(…)` (also what Pest uses), or
 * `$this->prophesize(X::class)` and `->m(Argument::cetera())->willReturn(…)`
 * before `->reveal()`.
 *
 * Every class name is written fully qualified with a leading `\`, the
 * workers' resolved names, so the template means the same in any test
 * namespace. A `void` method is stubbed without a value and a `never` one
 * throws. An untyped parameter or return is `mixed` in the comment, and
 * listed in the member's `unknown`; docblock types are not read.
 *
 * @phpstan-import-type Record from ExampleValue
 * @phpstan-import-type EnumCase from ExampleValue
 */
final readonly class PhpTemplate implements MockTemplate
{
    /** The type names PHP reserves, which a leading `\` would break. */
    private const BUILTIN = ['int', 'float', 'string', 'bool', 'array', 'callable', 'iterable', 'object', 'mixed', 'void', 'never', 'null', 'false', 'true', 'self', 'static', 'parent'];

    /** @param Closure(string, string): (Record|EnumCase|null) $lookup */
    public function __construct(private Closure $lookup) {}

    public function render(MockMembers $members, string $framework, bool $values): string
    {
        $target = $members->target;
        $class = self::qualified($target->canonicalName);
        $lines = [
            sprintf('// knossos synthesize_mock: %s (%s), from %s:%d', $target->canonicalName, $framework, $target->evidence->relativePath, $target->evidence->startLine),
            '// ' . $members->summary(),
            match ($framework) {
                'mockery' => sprintf('$mock = \Mockery::mock(%s::class);', $class),
                'prophecy' => sprintf('$prophecy = $this->prophesize(%s::class);', $class),
                default => sprintf('$mock = $this->createMock(%s::class);', $class),
            },
        ];
        foreach ($members->members as $member) {
            $lines[] = $this->member($members, $member, $framework, $values)['rendered'];
        }
        if ($framework === 'prophecy') {
            $lines[] = '$mock = $prophecy->reveal();';
        }

        return implode("\n", $lines) . "\n";
    }

    public function member(MockMembers $members, MockMember $member, string $framework, bool $values): array
    {
        $unknown = [];
        $params = [];
        foreach ($member->params() as $param) {
            $name = (string) ($param['name'] ?? '_');
            $written = is_string($param['type'] ?? null) ? $param['type'] : null;
            if ($written === null) {
                $unknown[] = sprintf('param $%s: untyped', $name);
            }
            $params[] = self::type($written ?? 'mixed') . ' ' . (($param['variadic'] ?? false) === true ? '...' : '') . '$' . $name . (($param['optional'] ?? false) === true ? ' = …' : '');
        }
        $returns = $member->returns();
        if ($returns === null) {
            $unknown[] = 'returns: untyped';
        }
        $comment = sprintf('// %s(%s): %s', $member->name, implode(', ', $params), self::type($returns ?? 'mixed'));
        $void = in_array(strtolower(ltrim((string) $returns, '?')), ['void'], true);
        // A method returning its own object (`self`, `static`) returns the double; Prophecy's cannot say so.
        $self = in_array(strtolower((string) $returns), ['self', 'static'], true) && $framework !== 'prophecy';
        $example = ['value' => 'null', 'unknown' => [], 'imports' => []];
        if ($values && !$void && !$self) {
            $example = (new ExampleValue('php', $this->lookup))->of($returns ?? 'mixed', $member->declarer->evidence->relativePath);
        }
        $value = $example['value'];
        $quoted = var_export($member->name, true);
        $call = match ($framework) {
            'mockery' => sprintf('$mock->shouldReceive(%s)', $quoted),
            'prophecy' => sprintf('$prophecy->%s(%s)', $member->name, $member->params() === [] ? '' : '\Prophecy\Argument::cetera()'),
            default => sprintf('$mock->method(%s)', $quoted),
        };
        $throw = "new \\RuntimeException('never returns')";
        $stub = match (true) {
            !$values || $void => $call,
            $self => $call . ($framework === 'mockery' ? '->andReturnSelf()' : '->willReturnSelf()'),
            $value === null => $call . match ($framework) {
                'mockery' => sprintf('->andThrow(%s)', $throw),
                'prophecy' => sprintf('->willThrow(%s)', $throw),
                default => sprintf('->willThrowException(%s)', $throw),
            },
            default => $call . sprintf($framework === 'mockery' ? '->andReturn(%s)' : '->willReturn(%s)', $value),
        };

        return ['rendered' => $comment . "\n" . $stub . ';', 'unknown' => [...$unknown, ...$example['unknown']], 'imports' => []];
    }

    /** A class name written fully qualified: `\App\Svc`. */
    public static function qualified(string $name): string
    {
        return '\\' . ltrim($name, '\\');
    }

    /** A written type with each class name fully qualified: `?\App\Item`, `int|\App\Lane`. */
    public static function type(string $type): string
    {
        return (string) preg_replace_callback('/\\\\?[A-Za-z_][\w\\\\]*/', static fn(array $match): string => in_array(strtolower($match[0]), self::BUILTIN, true) ? $match[0] : self::qualified($match[0]), $type);
    }
}

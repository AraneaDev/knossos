<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Synthesis;

use Closure;

/**
 * A `unittest.mock` or pytest-mock double of a Python class:
 * `create_autospec(C, spec_set=True, instance=True)` (pytest-mock's
 * `mocker.create_autospec` in a fixture), each method's `return_value`
 * stubbed. The spec checks every call's arguments against the real
 * signature and refuses an attribute the class lacks; Momus's
 * `patch.object` per method checked neither.
 *
 * An `async def` is an `AsyncMock` under the spec, whose awaited result is
 * the `return_value` set. An untyped parameter or return is `Any` in the
 * comment, and listed in the member's `unknown`.
 *
 * @phpstan-import-type Record from ExampleValue
 * @phpstan-import-type EnumCase from ExampleValue
 */
final readonly class PythonTemplate implements MockTemplate
{
    /**
     * @param Closure(string, string): (Record|EnumCase|null) $lookup
     * @param string $import the import that brings the target into scope (`from app.repo import Repo`), the name it binds after the space
     */
    public function __construct(private Closure $lookup, private string $import, private string $bound) {}

    /** An autospecced unittest.mock or pytest-mock double and its return values; {@see MockTemplate::render()}. */
    public function render(MockMembers $members, string $framework, bool $values): string
    {
        $target = $members->target;
        $pytest = $framework === 'pytest-mock';
        $indent = $pytest ? '    ' : '';
        $mock = $pytest ? 'mock' : TypeText::snake($target->displayName) . '_mock';
        $body = [];
        $imports = [$this->import];
        foreach ($members->members as $member) {
            $rendered = $this->member($members, $member, $framework, $values);
            $body[] = $rendered['rendered'];
            $imports = [...$imports, ...$rendered['imports']];
        }
        $imports = array_values(array_unique($imports));
        sort($imports, SORT_STRING);
        $lines = [
            sprintf('# knossos synthesize_mock: %s (%s), from %s:%d', $target->canonicalName, $framework, $target->evidence->relativePath, $target->evidence->startLine),
            '# ' . $members->summary(),
            $pytest ? 'import pytest' : 'from unittest.mock import create_autospec',
            '',
            ...$imports,
            '',
        ];
        if ($pytest) {
            $lines = [...$lines, '', '@pytest.fixture', sprintf('def %s_mock(mocker):', TypeText::snake($target->displayName))];
        }
        $lines[] = sprintf('%s%s = %screate_autospec(%s, spec_set=True, instance=True)', $indent, $mock, $pytest ? 'mocker.' : '', $this->bound);
        $lines = [...$lines, ...$body];
        if ($pytest) {
            $lines[] = $indent . 'return mock';
        }

        return implode("\n", $lines) . "\n";
    }

    /** {@inheritDoc} */
    public function member(MockMembers $members, MockMember $member, string $framework, bool $values): array
    {
        $pytest = $framework === 'pytest-mock';
        $indent = $pytest ? '    ' : '';
        $mock = $pytest ? 'mock' : TypeText::snake($members->target->displayName) . '_mock';
        $unknown = [];
        $params = [];
        $starred = false;
        $positionalOnly = false;
        foreach ($member->params() as $param) {
            $name = (string) ($param['name'] ?? '_');
            $kind = $param['kind'] ?? null;
            if ($positionalOnly && $kind !== 'positional_only') {
                $params[] = '/';
                $positionalOnly = false;
            }
            if ($kind === 'keyword_only' && !$starred) {
                $params[] = '*';
            }
            $starred = $starred || str_starts_with($name, '*') || $kind === 'keyword_only';
            $positionalOnly = $kind === 'positional_only';
            $written = is_string($param['type'] ?? null) ? $param['type'] : null;
            if ($written === null) {
                $unknown[] = sprintf('param %s: untyped', ltrim($name, '*'));
            }
            $params[] = $name . ': ' . ($written ?? 'Any') . (($param['optional'] ?? false) === true ? ' = …' : '');
        }
        if ($positionalOnly) {
            $params[] = '/';
        }
        $returns = $member->returns();
        if ($returns === null) {
            $unknown[] = 'returns: untyped';
        }
        $remarks = [];
        if ($member->async()) {
            $remarks[] = 'async: create_autospec makes it an AsyncMock, and awaiting it gives return_value';
        }
        if ($member->note !== null) {
            $remarks[] = 'protected: kept, as create_autospec allows it';
        }
        $lines = [sprintf('%s# %s(%s) -> %s%s', $indent, $member->name, implode(', ', $params), $returns ?? 'Any', $remarks === [] ? '' : '  (' . implode('; ', $remarks) . ')')];
        $imports = [];
        if ($values) {
            $example = (new ExampleValue('python', $this->lookup))->of($returns, $member->declarer->evidence->relativePath);
            $unknown = [...$unknown, ...$example['unknown']];
            $imports = $example['imports'];
            $placeholders = array_map(static fn(string $entry): string => substr($entry, strlen('value of ')), array_values(array_filter($example['unknown'], static fn(string $entry): bool => str_starts_with($entry, 'value of '))));
            $lines[] = ($example['value'] === null
                ? sprintf('%s%s.%s.side_effect = RuntimeException("never returns")', $indent, $mock, $member->name)
                : sprintf('%s%s.%s.return_value = %s', $indent, $mock, $member->name, $example['value']))
                . ($placeholders === [] ? '' : '  # knossos: fill in ' . implode(', ', $placeholders));
        }

        return ['rendered' => implode("\n", $lines), 'unknown' => $unknown, 'imports' => $imports];
    }
}

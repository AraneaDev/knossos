<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Rules;

/**
 * A signature as the wire states it, `{params: [{name, type?, optional?, variadic?}], returns?, truncated?}`,
 * read into a form the rules can count and compare.
 */
final readonly class SignatureShape
{
    /** @param list<array{type: ?string, variadic: bool}> $params */
    private function __construct(public array $params, public ?string $returns) {}

    /**
     * A production member's `signature` attribute; null when absent, malformed, cut short,
     * or the node is marked overloaded (it carries only the first of several signatures).
     *
     * @param array<string, mixed> $attributes the member node's attributes
     */
    public static function ofMember(array $attributes): ?self
    {
        $raw = $attributes['signature'] ?? null;
        if (!is_array($raw) || ($raw['truncated'] ?? false) !== false || ($attributes['overloaded'] ?? false) === true) {
            return null;
        }
        $params = self::params($raw['params'] ?? null);
        $returns = $raw['returns'] ?? null;
        if ($params === null || ($returns !== null && !is_string($returns))) {
            return null;
        }

        return new self($params, $returns === null || $returns === '' ? null : $returns);
    }

    /**
     * A double's parameter list; null when absent or malformed.
     *
     * @param list<mixed>|null $raw
     */
    public static function ofParams(?array $raw): ?self
    {
        $params = self::params($raw);

        return $params === null ? null : new self($params, null);
    }

    /** Whether a rest parameter accepts any number of arguments. */
    public function isVariadic(): bool
    {
        foreach ($this->params as $param) {
            if ($param['variadic']) {
                return true;
            }
        }

        return false;
    }

    /** Whether every parameter carries a written type. */
    public function isTyped(): bool
    {
        foreach ($this->params as $param) {
            if ($param['type'] === null) {
                return false;
            }
        }

        return true;
    }

    /** The parameters before a rest parameter: the ones that take one argument each. */
    public function fixedCount(): int
    {
        return count(array_filter($this->params, static fn(array $param): bool => !$param['variadic']));
    }

    /**
     * The parameters of a list as the wire states them; null when it is not a list of named parameters.
     *
     * @return list<array{type: ?string, variadic: bool}>|null
     */
    private static function params(mixed $raw): ?array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return null;
        }
        $params = [];
        foreach ($raw as $param) {
            if (!is_array($param) || !is_string($param['name'] ?? null)) {
                return null;
            }
            $type = $param['type'] ?? null;
            if ($type !== null && !is_string($type)) {
                return null;
            }
            $params[] = ['type' => $type === '' ? null : $type, 'variadic' => ($param['variadic'] ?? false) === true];
        }

        return $params;
    }
}

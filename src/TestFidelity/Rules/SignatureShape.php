<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Rules;

/**
 * A signature as the wire states it, `{params: [{name, type?, optional?, variadic?}], returns?, variadic?, truncated?}`,
 * read into a form the rules can count and compare. A signature's own
 * `variadic` says the function reads arguments past its parameters
 * (PHP's `func_get_args()`), as a rest parameter would take them.
 */
final readonly class SignatureShape
{
    /** @param list<array{type: ?string, optional: bool, variadic: bool}> $params */
    private function __construct(public array $params, public ?string $returns, private bool $takesMore = false) {}

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

        return new self($params, $returns === null || $returns === '' ? null : $returns, ($raw['variadic'] ?? false) === true);
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

    /** Whether a rest parameter, or a body that reads its arguments as a list, accepts any number of arguments. */
    public function isVariadic(): bool
    {
        if ($this->takesMore) {
            return true;
        }
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

    /** The parameters that take one argument each, a rest parameter apart. */
    public function fixedCount(): int
    {
        return count(array_filter($this->params, static fn(array $param): bool => !$param['variadic']));
    }

    /** The parameters a caller must supply: not optional, not defaulted, not a rest parameter. */
    public function requiredCount(): int
    {
        return count(array_filter($this->params, static fn(array $param): bool => !$param['variadic'] && !$param['optional']));
    }

    /**
     * The parameters of a list as the wire states them; null when it is not a list of named parameters.
     *
     * @return list<array{type: ?string, optional: bool, variadic: bool}>|null
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
            $params[] = ['type' => $type === '' ? null : $type, 'optional' => ($param['optional'] ?? false) === true, 'variadic' => ($param['variadic'] ?? false) === true];
        }

        return $params;
    }
}

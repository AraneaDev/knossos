<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Synthesis;

use Knossos\Scanner\Protocol\NodeFact;

/**
 * One member a mock template renders: a method from its stored signature,
 * or an interface's property from its field entry.
 */
final readonly class MockMember
{
    /**
     * @param 'method'|'property'|'type'|'const' $kind a Rust trait's associated type or const is `type` or `const`, its bounds or type in `$type`
     * @param array<string, mixed> $signature a method's stored `signature`; empty for a property
     * @param ?string $type a property's type as written, null when it has none
     * @param bool $associated a Rust function without a receiver, which a trait still declares
     * @param ?string $note what a reader should know of it (a Python `_x`)
     * @param ?string $implTrait for a Rust struct's method, the trait whose impl declares it, as written (`Repo<String>`); null for an inherent one
     */
    public function __construct(
        public string $name,
        public string $kind,
        public NodeFact $node,
        public NodeFact $declarer,
        public array $signature = [],
        public ?string $type = null,
        public bool $optional = false,
        public bool $associated = false,
        public ?string $note = null,
        public ?string $implTrait = null,
    ) {}

    /** Whether the method is declared `async`. */
    public function async(): bool
    {
        return ($this->node->attributes['async'] ?? false) === true;
    }

    /**
     * The method's parameters as stored, each `name`, and `type`, `optional`, `variadic` and `kind` when sent.
     *
     * @return list<array<string, mixed>>
     */
    public function params(): array
    {
        $params = $this->signature['params'] ?? [];

        return is_array($params) ? array_values(array_filter($params, is_array(...))) : [];
    }

    /** The declared return type as written; null when none is written. */
    public function returns(): ?string
    {
        $returns = $this->signature['returns'] ?? null;

        return is_string($returns) ? $returns : null;
    }

    /**
     * The method's own type parameters as written.
     *
     * @return list<string>
     */
    public function typeParams(): array
    {
        $params = $this->signature['type_params'] ?? [];

        return is_array($params) ? array_values(array_filter($params, is_string(...))) : [];
    }
}

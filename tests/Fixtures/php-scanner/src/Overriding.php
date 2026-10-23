<?php

declare(strict_types=1);

namespace Fixture;

use Vendor\Parser\NodeVisitorAbstract;

/** Fulfils a dependency's members, which nothing in this project names. */
final class Collector extends NodeVisitorAbstract implements \JsonSerializable, \Countable
{
    #[\Override]
    public function enterNode(object $node): void {}

    public function leaveNode(object $node): void {}

    public function jsonSerialize(): mixed
    {
        return [];
    }

    public function count(): int
    {
        return 0;
    }

    public function helper(): void {}
}

/** Implements the built-in through an enum. */
enum Size: int implements \JsonSerializable
{
    case Small = 1;

    public function jsonSerialize(): mixed
    {
        return $this->value;
    }

    public function label(): string
    {
        return 'small';
    }
}

/** An `Override` attribute of the namespace's own is not the language's. */
final class Lookalike extends NodeVisitorAbstract
{
    #[Override]
    public function enterNode(object $node): void {}
}

/** Defers to the dependency's member of its own name, which proves it has one. */
final class Deferring extends NodeVisitorAbstract
{
    public function leaveNode(object $node): void
    {
        parent::leaveNode($node);
    }

    public function afterTraverse(array $nodes): void
    {
        parent::leaveNode($nodes[0]);
    }

    public function beforeTraverse(array $nodes): void
    {
        $later = static fn() => parent::beforeTraverse($nodes);
    }
}

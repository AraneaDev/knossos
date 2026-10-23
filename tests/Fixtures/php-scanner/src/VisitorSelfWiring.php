<?php

declare(strict_types=1);

namespace Fixture\SelfWiring;

class SelfWalker extends \PhpParser\NodeVisitorAbstract
{
    public function walk(array $nodes): void
    {
        $traverser = new \PhpParser\NodeTraverser();
        $traverser->addVisitor($this);
        $traverser->traverse($nodes);
    }

    public function enterNode(\PhpParser\Node $node)
    {
        return null;
    }
}

class HeldCollector extends \PhpParser\NodeVisitorAbstract
{
    public function leaveNode(\PhpParser\Node $node)
    {
        return null;
    }
}

class PropertyWalker
{
    private HeldCollector $collector;

    public function __construct()
    {
        $this->collector = new HeldCollector();
    }

    public function walk(array $nodes): void
    {
        $traverser = new \PhpParser\NodeTraverser();
        $traverser->addVisitor($this->collector);
        $traverser->traverse($nodes);
    }
}

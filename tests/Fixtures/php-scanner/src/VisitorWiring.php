<?php

declare(strict_types=1);

namespace Fixture;

class NameCollector extends \PhpParser\NodeVisitorAbstract
{
    public function enterNode(\PhpParser\Node $node)
    {
        return null;
    }

    public function leaveNode(\PhpParser\Node $node)
    {
        return null;
    }
}

class OnlyEnter extends \PhpParser\NodeVisitorAbstract
{
    public function enterNode(\PhpParser\Node $node)
    {
        return null;
    }
}

class SubCollector extends NameCollector
{
}

class Walk
{
    public function run(): void
    {
        $v = new NameCollector();
        $t = new \PhpParser\NodeTraverser();
        $t->addVisitor($v);
        $t2 = new \PhpParser\NodeTraverser(new OnlyEnter());
    }

    public function inherited(): void
    {
        $t = new \PhpParser\NodeTraverser();
        $t->addVisitor(new SubCollector());
    }
}

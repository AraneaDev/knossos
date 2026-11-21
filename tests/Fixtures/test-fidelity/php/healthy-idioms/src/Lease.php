<?php

namespace App;

interface Conn
{
    public function prepare(string $sql): Stmt;
}

interface Stmt
{
    public function execute(): bool;
}

final class Lease
{
    public function __construct(private Conn $c)
    {
    }

    public function release(): void
    {
        $this->c->prepare('x')->execute();
    }
}

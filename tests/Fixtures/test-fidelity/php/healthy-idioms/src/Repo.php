<?php

namespace App;

interface Repo
{
    public function find(int $id): ?string;
    public function save(string $name, int $flags): bool;
    public function self(): static;
    public function maybe(): string|false;
}

final class Service
{
    public function __construct(private Repo $a, private Repo $b) {}

    public function run(): string
    {
        return ($this->a->find(1) ?? '') . ($this->b->find(2) ?? '');
    }

    public function each(array $xs): void
    {
        array_walk($xs, [$this->a, 'save'], 7);
    }
}

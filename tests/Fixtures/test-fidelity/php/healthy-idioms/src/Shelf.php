<?php

namespace App;

interface Store
{
    public function save(string $name, int $flags): bool;
}

final class Shelf
{
    public function __construct(private Store $store) {}

    public function put(string $name): bool
    {
        return $this->store->save($name, 0);
    }
}

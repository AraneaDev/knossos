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
        if (!$this->store->save($name, 0)) {
            throw new \LogicException('App\Store::save');
        }

        return true;
    }
}

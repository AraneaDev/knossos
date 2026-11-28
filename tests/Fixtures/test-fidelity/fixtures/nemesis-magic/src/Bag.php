<?php

namespace Magic;

/** Answers to any method or property name, so nothing about it is missing. */
class Bag
{
    public string $id = '';

    public function __call(string $name, array $arguments)
    {
        return null;
    }

    public function __get(string $name)
    {
        return null;
    }
}

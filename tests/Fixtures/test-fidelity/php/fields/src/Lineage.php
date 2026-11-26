<?php

namespace Fields;

class Entity
{
    public string $id;
}

class Account extends Entity implements \JsonSerializable
{
    use Stamped;

    public string $owner;

    public function jsonSerialize(): mixed
    {
        return [];
    }
}

class Imported extends \Vendor\Package\Model
{
    public string $name;
}

class Grandchild extends Imported
{
    public string $extra;
}

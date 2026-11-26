<?php

namespace Fields;

class Order
{
    public static string $table = 'orders';

    public string $id;
    public ?string $note;
    public int $count = 0;
    public $loose;
    public ?string $label = null, $other;

    public function __construct(
        public readonly string $owner,
        protected int $priority = 1,
        string $notAProperty = '',
    ) {
    }

    public function total(): int
    {
        return $this->count;
    }
}

interface HasId
{
    public function id(): string;
}

trait Stamped
{
    public \DateTimeImmutable $createdAt;
}

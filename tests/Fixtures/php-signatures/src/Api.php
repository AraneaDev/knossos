<?php

declare(strict_types=1);

namespace App;

use App\Model\User;
use Countable;
use Traversable;

interface Finder
{
    public function find(int $id): ?User;
}

final class Api
{
    public function __construct(private readonly Finder $finder, private int $limit = 10) {}

    public function scan(string $root, ?int $limit = null, string ...$rest): void {}

    public function untyped($a, &$b) {}

    public static function union(int|string|null $key, (Countable&Traversable)|null $items, Countable&Traversable $both): static {}

    public function wide(\App\Some\Very\Long\Namespace\Name\That\Goes\On\AndOn|\App\Another\Equally\Long\Namespace\Name\ForGoodMeasure $value): void {}

    public function many($a, $b, $c, $d, $e, $f, $g, $h, $i, $j, $k, $l, $m): void {}

    public function log($message)
    {
        if ($message !== '') {
            return \func_get_args();
        }
    }

    public function count()
    {
        return func_num_args() + strlen((string) func_get_arg(0));
    }

    public function nested()
    {
        return [fn () => func_get_args(), function () {
            return func_num_args();
        }, new class {
            public function inner()
            {
                return func_get_args();
            }
        }];
    }
}

function helper(array $rows, callable $each = null): iterable {}

<?php

namespace Experiments\Fluent;

enum Order: string
{
    case Ascending = 'asc';
    case Descending = 'desc';
}

class Query
{
    /** This used to return $this. The stub below still chains on it. */
    public function where(string $column): void
    {
    }

    public function direction(): Order
    {
        return Order::Ascending;
    }
}

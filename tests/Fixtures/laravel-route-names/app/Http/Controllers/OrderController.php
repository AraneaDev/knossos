<?php

namespace App\Http\Controllers;

final class OrderController
{
    public function store()
    {
        return redirect()->route('orders.show', 1);
    }

    public function show(int $order)
    {
        return $order;
    }
}

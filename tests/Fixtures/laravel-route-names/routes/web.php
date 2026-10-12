<?php

use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');

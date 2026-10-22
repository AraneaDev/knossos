<?php

use App\Http\Controllers\HomeController;
use App\Livewire\Counter;
use App\Livewire\Dashboard;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index']);
Route::livewire('/dashboard', Dashboard::class)->middleware('active');
Route::livewire('/posts/create', 'pages::post.create');
// The Livewire 3 form, which still works.
Route::get('/counter', Counter::class);

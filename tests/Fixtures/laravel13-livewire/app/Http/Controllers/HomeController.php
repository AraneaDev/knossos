<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Routing\Attributes\Controllers\Middleware;

#[Middleware('auth')]
class HomeController extends Controller
{
    public function index()
    {
        return view('welcome', [
            'posts' => Post::published()->latest()->get(),
            'drafts' => Post::query()->draft()->get(),
        ]);
    }
}

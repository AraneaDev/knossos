<?php

namespace App\Observers;

use App\Events\PostPublished;
use App\Models\Post;

class PostObserver
{
    public function created(Post $post): void
    {
        PostPublished::dispatch($post);
    }
}

<?php

namespace App\Actions;

use App\Models\Post;

// Called only from the single-file component pages::post.create.
class CreatePost
{
    public function handle(string $title): Post
    {
        return Post::create(['title' => $title]);
    }
}

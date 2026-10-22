<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

// Found by Laravel's naming convention for the Post model.
class PostPolicy
{
    public function update(User $user, Post $post): bool
    {
        return $user->id === $post->user_id;
    }
}

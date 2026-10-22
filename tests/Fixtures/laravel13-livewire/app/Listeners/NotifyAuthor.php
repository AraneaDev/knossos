<?php

namespace App\Listeners;

use App\Events\PostPublished;

class NotifyAuthor
{
    public function handle(PostPublished $event): void
    {
        logger()->info('published', ['post' => $event->post->id]);
    }
}

<?php

namespace App\Livewire\Forms;

use App\Models\Post;
use Livewire\Attributes\Validate;
use Livewire\Form;

class PostForm extends Form
{
    public ?Post $post = null;

    #[Validate('required|min:3')]
    public string $title = '';

    public function setPost(Post $post): void
    {
        $this->post = $post;
        $this->title = $post->title;
    }

    public function update(): void
    {
        $this->validate();
        $this->post?->update(['title' => $this->title]);
    }
}

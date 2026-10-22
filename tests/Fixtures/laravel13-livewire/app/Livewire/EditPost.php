<?php

namespace App\Livewire;

use App\Livewire\Forms\PostForm;
use App\Models\Post;
use Livewire\Component;

class EditPost extends Component
{
    public PostForm $form;

    public function mount(Post $post): void
    {
        $this->form->setPost($post);
    }

    public function save(): void
    {
        $this->form->update();
    }

    public function render()
    {
        return view('livewire.edit-post');
    }
}

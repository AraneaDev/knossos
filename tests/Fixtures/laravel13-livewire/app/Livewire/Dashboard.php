<?php

namespace App\Livewire;

use App\Models\Post;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class Dashboard extends Component
{
    public int $limit = 10;

    public function mount(int $limit = 10): void
    {
        $this->limit = $limit;
    }

    #[Computed]
    public function posts()
    {
        return Post::query()->latest()->limit($this->limit)->get();
    }

    #[On('post-created')]
    public function reload(): void
    {
        unset($this->posts);
    }

    public function loadMore(): void
    {
        $this->limit += 10;
    }

    public function render()
    {
        return view('livewire.dashboard');
    }
}

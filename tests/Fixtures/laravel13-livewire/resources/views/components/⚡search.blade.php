<?php

use App\Models\Post;
use Livewire\Component;

new class extends Component {
    public string $query = '';

    public function results()
    {
        return Post::where('title', 'like', "%{$this->query}%")->get();
    }
};
?>

<div>
    <input wire:model.live="query">
    @foreach ($this->results() as $post)
        <p>{{ $post->title }}</p>
    @endforeach
</div>

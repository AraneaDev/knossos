<?php

use App\Actions\CreatePost;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component {
    #[Validate('required')]
    public string $title = '';

    public function save(CreatePost $create): void
    {
        $create->handle($this->title);
        $this->dispatch('post-created');
    }
};
?>

<form wire:submit="save">
    <input wire:model="title">
</form>

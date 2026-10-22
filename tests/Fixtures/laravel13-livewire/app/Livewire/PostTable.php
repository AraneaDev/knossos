<?php

namespace App\Livewire;

use App\Livewire\Concerns\HasSorting;

class PostTable extends BaseComponent
{
    use HasSorting;

    public string $title = '';

    public function updatedTitle(): void {}

    public function unusedAction(): void {}

    public function render()
    {
        return view('livewire.post-table');
    }
}

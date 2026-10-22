<?php

namespace App\Livewire\Concerns;

trait HasSorting
{
    public string $sortColumn = 'id';

    public function mountHasSorting(): void
    {
        $this->sortColumn = 'id';
    }

    public function sortBy(string $column): void
    {
        $this->sortColumn = $column;
    }
}

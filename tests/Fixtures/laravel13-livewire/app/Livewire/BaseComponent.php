<?php

namespace App\Livewire;

use Livewire\Component;

abstract class BaseComponent extends Component
{
    public function notify(string $message): void
    {
        session()->flash('status', $message);
    }
}

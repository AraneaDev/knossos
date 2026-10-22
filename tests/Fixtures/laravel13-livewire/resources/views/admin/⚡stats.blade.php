<?php

use Livewire\Component;

new class extends Component {
    public function refresh(): void {}
};
?>

<div>
    <button wire:click="refresh">Refresh</button>
</div>

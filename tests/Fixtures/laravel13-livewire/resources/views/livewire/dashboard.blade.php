<div>
    <livewire:counter />
    <livewire:search />
    <livewire:post-table />
    <livewire:admin::stats />

    @foreach ($this->posts as $post)
        <livewire:edit-post :post="$post" :key="$post->id" />
    @endforeach

    <button wire:click="loadMore">More</button>
</div>

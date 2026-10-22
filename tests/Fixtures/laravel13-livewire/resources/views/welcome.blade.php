<ul>
    @foreach ($posts as $post)
        <li>{{ $post->shouted_title }}: {{ $post->excerpt }}</li>
    @endforeach
</ul>

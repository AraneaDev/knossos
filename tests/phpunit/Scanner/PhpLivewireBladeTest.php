<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Protocol\Diagnostic;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\ScanLimits;
use PHPUnit\Framework\Attributes\Group;

/**
 * The PHP worker reads what a Blade template asks of its component: the
 * members it names in `wire:` directives, `$wire` calls and `$this->x`
 * expressions, and the partials it includes. Nothing here names a component;
 * the core joins these facts to one later.
 */
final class PhpLivewireBladeTest extends KnossosTestCase
{
    private const FIXTURE = '/tests/Fixtures/laravel13-livewire';

    #[Group('php-scanner')]
    public function testAClassComponentViewRecordsActionsAndExpressions(): void
    {
        $nodes = $this->scan(self::repositoryRoot() . self::FIXTURE, ['resources/views/livewire/dashboard.blade.php']);
        $view = $nodes['resources/views/livewire/dashboard.blade.php']->attributes['livewire_view'];

        assertSame([['name' => 'posts', 'line' => 7, 'via' => 'expression'], ['name' => 'loadMore', 'line' => 11, 'via' => 'action']], $view['used']);
        assertSame([], $view['includes']);
    }

    #[Group('php-scanner')]
    public function testWireDirectivesNameTheirMethodOnly(): void
    {
        $view = $this->scanInline(<<<'BLADE'
            <form wire:submit="save">
                <button wire:click.prevent="save({{ $post->id }})">a</button>
                <input wire:keydown.enter="search" wire:model="form.title" wire:target="ignoredTarget">
                <div wire:poll.5s="refresh" wire:loading wire:click="$refresh"></div>
                <i wire:click="$set('a', 1)" wire:click="$toggle('b')" wire:click="$dispatch('c')"></i>
                <b x-on:click="$wire.archive(1)" @click="$wire.$call('publish')"></b>
                <u x-on:click="$wire.$refresh()" wire:key="k" wire:navigate="n"></u>
            </form>
            BLADE);

        $names = array_map(fn(array $use): string => $use['name'], $view['used']);
        $names = array_values(array_unique($names));
        sort($names);
        assertSame(['archive', 'publish', 'refresh', 'save', 'search'], $names);
        foreach ($view['used'] as $use) {
            assertSame('action', $use['via']);
        }
    }

    #[Group('php-scanner')]
    public function testExpressionsComeFromEchoesAndDirectives(): void
    {
        $view = $this->scanInline(<<<'BLADE'
            {{ $this->title }}
            {!! $this->render_it($x) !!}
            @if ($this->canEdit())
                {{ $other->thing }} {{ $this->title }}
            @endif
            @foreach ($this->rows as $row) {{ $row }} @endforeach
            {{ this is not php ( }}
            BLADE);

        assertSame([
            ['name' => 'title', 'line' => 1, 'via' => 'expression'],
            ['name' => 'render_it', 'line' => 2, 'via' => 'expression'],
            ['name' => 'canEdit', 'line' => 3, 'via' => 'expression'],
            ['name' => 'title', 'line' => 4, 'via' => 'expression'],
            ['name' => 'rows', 'line' => 6, 'via' => 'expression'],
        ], $view['used']);
    }

    /** A loop directive's header is read as the loop it opens: `@for` and `@forelse` like `@foreach`. */
    #[Group('php-scanner')]
    public function testForAndForelseHeadersAreRead(): void
    {
        $view = $this->scanInline(<<<'BLADE'
            @for ($i = 0; $i < $this->count; $i++) x @endfor
            @forelse ($this->items as $key => $item) y @empty z @endforelse
            @for ($this->broken { @endfor
            BLADE);

        assertSame([
            ['name' => 'count', 'line' => 1, 'via' => 'expression'],
            ['name' => 'items', 'line' => 2, 'via' => 'expression'],
        ], $view['used']);
    }

    /** The same member used the same way on one line is one use; on another line, or another way, it is its own. */
    #[Group('php-scanner')]
    public function testRepeatedUsesAreRecordedOnce(): void
    {
        $view = $this->scanInline(<<<'BLADE'
            {{ $this->title }} {{ $this->title }} {!! $this->title !!}
            <b wire:click="save">a</b> <b wire:click.prevent="save">b</b> {{ $this->save }}
            {{ $this->title }}
            BLADE);

        assertSame([
            ['name' => 'title', 'line' => 1, 'via' => 'expression'],
            ['name' => 'save', 'line' => 2, 'via' => 'action'],
            ['name' => 'save', 'line' => 2, 'via' => 'expression'],
            ['name' => 'title', 'line' => 3, 'via' => 'expression'],
        ], $view['used']);
    }

    #[Group('php-scanner')]
    public function testMultiArgumentDirectivesAndPhpBlocksAreRead(): void
    {
        $view = $this->scanInline(<<<'BLADE'
            @can('update', $this->post) a @endcan
            @livewire('stats', ['p' => $this->filters])
            <option @selected($this->a === 1, true)>x</option>
            @php
                $this->prepare();
                $x = $this->total;
            @endphp
            @php($this->inline())
            <button wire:confirm="delete this?" wire:click="remove">x</button>
            BLADE);

        $names = array_map(fn(array $use): string => $use['name'], $view['used']);
        sort($names);
        assertSame(['a', 'filters', 'inline', 'post', 'prepare', 'remove', 'total'], $names);
        $lines = array_column($view['used'], 'line', 'name');
        assertSame(5, $lines['prepare']);
        assertSame(6, $lines['total']);
    }

    #[Group('php-scanner')]
    public function testCommentedVerbatimAndEscapedBladeIsIgnored(): void
    {
        $view = $this->scanInline(<<<'BLADE'
            {{-- wire:click="ghost" --}}
            @verbatim
                <b wire:click="ghost2">x</b>
            @endverbatim
            @{{ $this->ghost3() }}
            <i wire:click="real">y</i>
            BLADE);

        assertSame([['name' => 'real', 'line' => 6, 'via' => 'action']], $view['used']);
    }

    /**
     * A Blade expression is inline HTML to the file's own parse, so its depth
     * is measured again before anything recursive walks it: past the cap the
     * expression is skipped, and the rest of the template is still read.
     */
    #[Group('php-scanner')]
    public function testAnExpressionNestedPastTheSafeDepthIsSkipped(): void
    {
        $nesting = 700;
        $view = $this->scanInline('{{ ' . str_repeat('[', $nesting) . '$this->deep' . str_repeat(']', $nesting) . " }}\n<i wire:click=\"real\">y</i>");

        assertSame([['name' => 'real', 'line' => 2, 'via' => 'action']], $view['used']);
    }

    #[Group('php-scanner')]
    public function testIncludesAreRecorded(): void
    {
        $view = $this->scanInline(<<<'BLADE'
            @include('a.b')
            @includeIf('c')
            @includeWhen($x, 'd')
            @each('e', $items, 'item')
            @include($name)
            BLADE);

        assertSame([
            ['view' => 'a.b', 'line' => 1],
            ['view' => 'c', 'line' => 2],
            ['view' => 'd', 'line' => 3],
            ['view' => 'e', 'line' => 4],
        ], $view['includes']);
    }

    #[Group('php-scanner')]
    public function testAViewWithoutLivewireIsHarmless(): void
    {
        $nodes = $this->scan(self::repositoryRoot() . self::FIXTURE, ['resources/views/welcome.blade.php'], diagnostics: $diagnostics);
        $view = $nodes['resources/views/welcome.blade.php']->attributes['livewire_view'] ?? ['used' => [], 'includes' => []];

        assertSame([], $diagnostics);
        assertSame([], $view['used']);
        assertSame([], $view['includes']);
    }

    #[Group('php-scanner')]
    public function testRenderViewAndConfigAreRecorded(): void
    {
        $nodes = $this->scan(self::repositoryRoot() . self::FIXTURE, ['app/Livewire/PostTable.php', 'config/livewire.php']);

        assertSame('livewire.post-table', $nodes['App\\Livewire\\PostTable']->attributes['livewire_render_view']);
        assertSame([
            'class_namespace' => null,
            'view_path' => null,
            'component_locations' => null,
            'component_namespaces' => ['admin' => 'resources/views/admin'],
        ], $nodes['config/livewire.php']->attributes['livewire_config']);
    }

    #[Group('php-scanner')]
    public function testRenderViewNeedsOneLiteralAndConfigReadsEachLiteralForm(): void
    {
        $root = $this->tempRoot([
            'app/Livewire/Chained.php' => "<?php\nnamespace App\\Livewire;\nclass Chained { public function render() { return view('livewire.chained')->title('T'); } }\n",
            'app/Livewire/Branchy.php' => "<?php\nnamespace App\\Livewire;\nclass Branchy { public function render() { if (\$this->x) { return view('a'); } return view('b'); } }\n",
            'app/Livewire/Dynamic.php' => "<?php\nnamespace App\\Livewire;\nclass Dynamic { public function render() { return view(\$this->name); } }\n",
            'config/livewire.php' => "<?php\nreturn [\n 'class_namespace' => 'App\\\\Livewire',\n 'view_path' => resource_path('views/livewire'),\n"
                . " 'component_locations' => [resource_path('views/components'), base_path('modules/ui'), env('X')],\n 'component_namespaces' => compute(),\n];\n",
        ]);
        try {
            $nodes = $this->scan($root, ['app/Livewire/Chained.php', 'app/Livewire/Branchy.php', 'app/Livewire/Dynamic.php', 'config/livewire.php']);
        } finally {
            $this->removeRoot($root);
        }

        assertSame('livewire.chained', $nodes['App\\Livewire\\Chained']->attributes['livewire_render_view']);
        assertSame(false, isset($nodes['App\\Livewire\\Branchy']->attributes['livewire_render_view']));
        assertSame(false, isset($nodes['App\\Livewire\\Dynamic']->attributes['livewire_render_view']));
        assertSame([
            'class_namespace' => 'App\\Livewire',
            'view_path' => 'resources/views/livewire',
            'component_locations' => ['resources/views/components', 'modules/ui'],
            'component_namespaces' => null,
        ], $nodes['config/livewire.php']->attributes['livewire_config']);
    }

    /**
     * `component_namespaces` is a map, so an empty one travels as a JSON
     * object, the shape a filled one has, never as a list.
     */
    #[Group('php-scanner')]
    public function testAnEmptyComponentNamespacesMapTravelsAsAnObject(): void
    {
        $root = $this->tempRoot(['config/livewire.php' => "<?php\nreturn ['component_namespaces' => []];\n"]);
        try {
            $request = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'scan', 'params' => ['limits' => ScanLimits::DEFAULT, 'root' => $root, 'files' => ['config/livewire.php'], 'frameworks' => ['livewire']]];
            $process = proc_open([PHP_BINARY, self::repositoryRoot() . '/workers/php/bin/worker'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            fwrite($pipes[0], json_encode($request, JSON_THROW_ON_ERROR) . "\n" . '{"jsonrpc":"2.0","id":2,"method":"shutdown","params":{}}' . "\n");
            fclose($pipes[0]);
            $stdout = (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            proc_close($process);
        } finally {
            $this->removeRoot($root);
        }

        assertSame(1, substr_count($stdout, '"component_namespaces":{}'), $stdout);
    }

    #[Group('php-scanner')]
    public function testANonLiteralRenderIsFlaggedAndANestedAnonymousClassIsNotTheOuterOne(): void
    {
        $root = $this->tempRoot([
            'app/Livewire/Dyn.php' => "<?php\nnamespace App\\Livewire;\nclass Dyn { public function render() { return view('livewire.dyn-' . \$this->mode); } }\n",
            'app/Livewire/Inline.php' => "<?php\nnamespace App\\Livewire;\nclass Inline { public function render() { return <<<'blade'\n<div wire:click=\"go\"></div>\nblade; } }\n",
            'app/Livewire/Outer.php' => "<?php\nnamespace App\\Livewire;\nclass Outer { public function render() { \$helper = new class { public function render() { return view('inner'); } }; return view('livewire.outer'); } }\n",
            'app/Livewire/NoRender.php' => "<?php\nnamespace App\\Livewire;\nclass NoRender { public function save() {} }\n",
        ]);
        try {
            $nodes = $this->scan($root, ['app/Livewire/Dyn.php', 'app/Livewire/Inline.php', 'app/Livewire/Outer.php', 'app/Livewire/NoRender.php']);
        } finally {
            $this->removeRoot($root);
        }

        assertSame(true, $nodes['App\\Livewire\\Dyn']->attributes['livewire_render_view_dynamic']);
        assertSame(false, isset($nodes['App\\Livewire\\Dyn']->attributes['livewire_render_view']));
        assertSame(true, $nodes['App\\Livewire\\Inline']->attributes['livewire_render_view_dynamic']);
        assertSame('livewire.outer', $nodes['App\\Livewire\\Outer']->attributes['livewire_render_view']);
        assertSame(false, isset($nodes['App\\Livewire\\Outer']->attributes['livewire_render_view_dynamic']));
        assertSame(false, isset($nodes['App\\Livewire\\NoRender']->attributes['livewire_render_view_dynamic']));
    }

    #[Group('php-scanner')]
    public function testNothingIsRecordedWithoutTheLivewireFlag(): void
    {
        $nodes = $this->scan(
            self::repositoryRoot() . self::FIXTURE,
            ['resources/views/livewire/dashboard.blade.php', 'app/Livewire/PostTable.php', 'config/livewire.php'],
            frameworks: ['laravel'],
        );

        foreach ($nodes as $node) {
            foreach (['livewire_view', 'livewire_render_view', 'livewire_render_view_dynamic', 'livewire_config'] as $key) {
                assertSame(false, isset($node->attributes[$key]), $node->canonicalName . ' carries ' . $key);
            }
        }
    }

    /**
     * @param list<string> $files
     * @param list<string> $frameworks
     * @param list<Diagnostic>|null $diagnostics
     *
     * @param-out list<Diagnostic> $diagnostics
     *
     * @return array<string, NodeFact>
     */
    private function scan(string $root, array $files, array $frameworks = ['laravel', 'livewire'], ?array &$diagnostics = null): array
    {
        $client = $this->phpWorkerClient();
        $contributions = iterator_to_array($client->scan(['limits' => ScanLimits::DEFAULT,
            'root' => $root,
            'files' => $files,
            'frameworks' => $frameworks,
        ]));
        $client->shutdown();
        $nodes = [];
        $diagnostics = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->nodes as $node) {
                $nodes[$node->canonicalName] = $node;
            }
            foreach ($contribution->diagnostics as $diagnostic) {
                $diagnostics[] = $diagnostic;
            }
        }

        return $nodes;
    }

    /**
     * The `livewire_view` of a template written inline.
     *
     * @return array{used: list<array<string, mixed>>, includes: list<array<string, mixed>>}
     */
    private function scanInline(string $blade): array
    {
        $root = $this->tempRoot(['resources/views/livewire/inline.blade.php' => $blade . "\n"]);
        try {
            $nodes = $this->scan($root, ['resources/views/livewire/inline.blade.php']);
        } finally {
            $this->removeRoot($root);
        }

        return $nodes['resources/views/livewire/inline.blade.php']->attributes['livewire_view'];
    }

    /** @param array<string, string> $files */
    private function tempRoot(array $files): string
    {
        $root = sys_get_temp_dir() . '/knossos-livewire-blade-' . bin2hex(random_bytes(6));
        foreach ($files as $path => $contents) {
            is_dir(dirname($root . '/' . $path)) || mkdir(dirname($root . '/' . $path), 0o777, true);
            file_put_contents($root . '/' . $path, $contents);
        }

        return $root;
    }

    private function removeRoot(string $root): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($root);
    }
}

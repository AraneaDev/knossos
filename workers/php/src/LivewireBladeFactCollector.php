<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Reads what a Livewire component's template asks of it, without naming the
 * component: the members a Blade file uses (`wire:click="save"`,
 * `$wire.archive()`, `{{ $this->posts }}`), the partials it includes, the view
 * a class's `render()` returns, and the literal values in `config/livewire.php`.
 *
 * A Blade file reaches the worker as PHP whose template text is `InlineHTML`,
 * so the directives and echoes are read out of that text. The core later joins
 * a view's names to the components whose view graph contains it; nothing here
 * decides which class owns which template.
 */
final class LivewireBladeFactCollector extends NodeVisitorAbstract
{
    /**
     * `wire:` directives whose value is not a method of the component: model
     * paths, keys, selectors and display toggles.
     */
    private const NON_ACTIONS = [
        'model', 'key', 'loading', 'target', 'navigate', 'ignore', 'transition', 'dirty', 'offline',
        'stream', 'replace', 'show', 'text', 'cloak', 'current', 'ref', 'confirm',
    ];

    /** Directives that name a view, and the position of that argument. */
    private const INCLUDES = ['include' => 0, 'includeif' => 0, 'includewhen' => 1, 'includeunless' => 1, 'each' => 0];

    private const CONFIG_PATH = 'config/livewire.php';

    private readonly Parser $parser;

    /** @var list<array{name: string, line: int, via: string}> */
    private array $used = [];

    /** @var list<array{view: string, line: int}> */
    private array $includes = [];

    /** @var array<string, mixed>|null */
    private ?array $config = null;

    /** @var array<string, string> class node id => the literal view its render() returns */
    private array $renderViews = [];

    /** @var array<string, true> class node id => its render() returns something other than one literal view */
    private array $dynamicRenders = [];

    /** @var list<?string> ids of the classes being visited, innermost last; null for an anonymous class */
    private array $classes = [];

    public function __construct(private readonly string $relativePath)
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /** Reads the top-level `return [...]` of `config/livewire.php`. */
    public function beforeTraverse(array $nodes): ?array
    {
        if ($this->relativePath !== self::CONFIG_PATH) {
            return null;
        }
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Return_ && $node->expr instanceof Expr\Array_) {
                $this->config = $this->readConfig($node->expr);
            }
        }

        return null;
    }

    /** Reads a Blade file's template text, and notes each class and the view its `render()` returns. */
    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Stmt\InlineHTML && str_ends_with($this->relativePath, '.blade.php')) {
            $this->readTemplate($node->value, $node->getStartLine());
        } elseif ($node instanceof Stmt\Class_) {
            $this->classes[] = $node->namespacedName !== null ? 'php:class:' . ltrim($node->namespacedName->toString(), '\\') : null;
        } elseif ($node instanceof Stmt\ClassMethod && strtolower($node->name->toString()) === 'render' && $this->classes !== []) {
            $this->recordRender($node, $this->classes[array_key_last($this->classes)]);
        }

        return null;
    }

    /** Leaves the class whose methods were being read. */
    public function leaveNode(Node $node): null
    {
        if ($node instanceof Stmt\Class_) {
            array_pop($this->classes);
        }

        return null;
    }

    /**
     * The attributes this file adds to its module node.
     *
     * @return array<string, mixed>
     */
    public function moduleAttributes(): array
    {
        $attributes = [];
        $used = self::unique($this->used, ['line', 'name', 'via']);
        $includes = self::unique($this->includes, ['line', 'view']);
        if ($used !== [] || $includes !== []) {
            $attributes['livewire_view'] = ['used' => $used, 'includes' => $includes];
        }
        if ($this->config !== null) {
            $attributes['livewire_config'] = $this->config;
        }

        return $attributes;
    }

    /**
     * The attributes this file adds to its class nodes, by node id.
     *
     * @return array<string, array<string, bool|string>>
     */
    public function classAttributes(): array
    {
        $attributes = array_map(static fn(string $view): array => ['livewire_render_view' => $view], $this->renderViews);
        foreach (array_keys($this->dynamicRenders) as $id) {
            $attributes[$id] = ['livewire_render_view_dynamic' => true];
        }

        return $attributes;
    }

    /**
     * Notes the view a class's `render()` returns, or that it returns no single literal one.
     *
     * A `render()` inside an anonymous class belongs to that class, which has
     * no node of its own here, so it is not attributed to the class around it.
     */
    private function recordRender(Stmt\ClassMethod $method, ?string $class): void
    {
        if ($class === null) {
            return;
        }
        $view = $this->renderedView($method);
        if ($view !== null) {
            $this->renderViews[$class] = $view;
            unset($this->dynamicRenders[$class]);
        } else {
            $this->dynamicRenders[$class] = true;
            unset($this->renderViews[$class]);
        }
    }

    /** Collects the methods one template chunk calls and the views it includes. */
    private function readTemplate(string $text, int $firstLine): void
    {
        $text = self::blankInert($text);
        $line = static fn(int $offset): int => $firstLine + substr_count($text, "\n", 0, $offset);

        if (preg_match_all('/\bwire:([a-z][\w-]*)((?:\.[\w-]+)*)\s*=\s*(["\'])(.*?)\3/s', $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[1] as $index => [$directive, $offset]) {
                $value = $matches[4][$index][0];
                if (!in_array($directive, self::NON_ACTIONS, true) && preg_match('/^\s*([A-Za-z_]\w*)/', $value, $name) === 1) {
                    $this->used[] = ['name' => $name[1], 'line' => $line($offset), 'via' => 'action'];
                }
            }
        }
        if (preg_match_all('/\$wire\.(?:([A-Za-z_]\w*)\s*\(|\$call\(\s*([\'"])([A-Za-z_]\w*)\2)/', $text, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) > 0) {
            foreach ($matches[0] as $index => [, $offset]) {
                $name = $matches[1][$index][0] ?? $matches[3][$index][0];
                $this->used[] = ['name' => $name, 'line' => $line($offset), 'via' => 'action'];
            }
        }

        $expressions = [];
        if (preg_match_all('/\{\{(.*?)\}\}|\{!!(.*?)!!\}/s', $text, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) > 0) {
            foreach ($matches[0] as $index => [, $offset]) {
                $body = $matches[1][$index][0] !== null ? $matches[1][$index] : $matches[2][$index];
                $expressions[] = ['code' => '<?php (' . $body[0] . ');', 'offset' => $body[1], 'directive' => null];
            }
        }
        if (preg_match_all('/(?<![\w@])@(\w+)[ \t]*\(/', $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[1] as $index => [$directive]) {
                $open = $matches[0][$index][1] + strlen($matches[0][$index][0]) - 1;
                $arguments = self::balanced($text, $open);
                if ($arguments !== null) {
                    $expressions[] = ['code' => self::wrapDirective(strtolower($directive), $arguments), 'offset' => $open + 1, 'directive' => strtolower($directive)];
                }
            }
        }

        if (preg_match_all('/@php(?!\s*\()\b(.*?)@endphp/s', $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[1] as [$body, $offset]) {
                $expressions[] = ['code' => '<?php ' . $body . "\n;", 'offset' => $offset, 'directive' => null];
            }
        }

        foreach ($expressions as $expression) {
            $this->readExpression($expression['code'], $line($expression['offset']), $expression['directive']);
        }
    }

    /** Collects `$this->x` uses, and the view an include directive names, from one Blade expression. */
    private function readExpression(string $code, int $line, ?string $directive): void
    {
        try {
            $ast = $this->parser->parse($code);
        } catch (Error) {
            return;
        }
        if ($ast === null) {
            return;
        }

        foreach ((new NodeFinder())->find($ast, static fn(Node $node): bool => self::thisMember($node) !== null) as $node) {
            $this->used[] = ['name' => (string) self::thisMember($node), 'line' => $line + $node->getStartLine() - 1, 'via' => 'expression'];
        }

        if ($directive !== null && isset(self::INCLUDES[$directive])) {
            $call = (new NodeFinder())->findFirstInstanceOf($ast, Expr\FuncCall::class);
            $view = $call?->args[self::INCLUDES[$directive]]->value ?? null;
            if ($view instanceof String_) {
                $this->includes[] = ['view' => $view->value, 'line' => $line];
            }
        }
    }

    /** The member name when the node is `$this->name` or `$this->name(...)`. */
    private static function thisMember(Node $node): ?string
    {
        if (!$node instanceof Expr\MethodCall && !$node instanceof Expr\NullsafeMethodCall && !$node instanceof Expr\PropertyFetch && !$node instanceof Expr\NullsafePropertyFetch) {
            return null;
        }

        return $node->var instanceof Expr\Variable && $node->var->name === 'this' && $node->name instanceof Identifier
            ? $node->name->toString()
            : null;
    }

    /** Turns a directive's argument text into a statement php-parser accepts. */
    private static function wrapDirective(string $directive, string $arguments): string
    {
        return match (true) {
            in_array($directive, ['foreach', 'forelse', 'for'], true) => '<?php ' . ($directive === 'for' ? 'for' : 'foreach') . ' (' . $arguments . ') {}',
            // Any other directive takes arguments a call can hold, `@can('update', $post)` included.
            default => '<?php __knossos(' . $arguments . ');',
        };
    }

    /**
     * Replaces what Blade does not render as a template with spaces, keeping
     * newlines so line numbers stay right: `{{-- --}}` comments, `@verbatim`
     * blocks and `@{{ }}` escapes.
     */
    private static function blankInert(string $text): string
    {
        $blank = static fn(array $match): string => (string) preg_replace('/[^\n]/', ' ', $match[0]);

        return (string) preg_replace_callback(
            ['/\{\{--.*?--\}\}/s', '/@verbatim.*?@endverbatim/s', '/@\{\{.*?\}\}/s'],
            $blank,
            $text,
        );
    }

    /** The text between the parenthesis opening at `$open` and its match, honouring string literals. */
    private static function balanced(string $text, int $open): ?string
    {
        $depth = 0;
        $quote = null;
        $length = strlen($text);
        for ($index = $open; $index < $length; ++$index) {
            $char = $text[$index];
            if ($quote !== null) {
                if ($char === '\\') {
                    ++$index;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                ++$depth;
            } elseif ($char === ')' && --$depth === 0) {
                return substr($text, $open + 1, $index - $open - 1);
            }
        }

        return null;
    }

    /**
     * The one literal view every `return` of a `render()` method names, looking
     * through chained calls such as `view('x')->title('T')`.
     */
    private function renderedView(Stmt\ClassMethod $method): ?string
    {
        $collector = new class extends NodeVisitorAbstract {
            /** @var list<Stmt\Return_> */
            public array $returns = [];

            /** Gathers the method's own `return` statements, not those of closures or nested classes. */
            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction || $node instanceof Stmt\Function_ || $node instanceof Stmt\Class_) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }
                if ($node instanceof Stmt\Return_) {
                    $this->returns[] = $node;
                }

                return null;
            }
        };
        $traverser = new NodeTraverser($collector);
        $traverser->traverse($method->stmts ?? []);

        $views = [];
        foreach ($collector->returns as $return) {
            $call = $return->expr;
            while ($call instanceof Expr\MethodCall || $call instanceof Expr\NullsafeMethodCall) {
                $call = $call->var;
            }
            $view = $call instanceof Expr\FuncCall
                && $call->name instanceof Name
                && strtolower($call->name->toString()) === 'view'
                && ($call->args[0]->value ?? null) instanceof String_
                    ? $call->args[0]->value->value
                    : null;
            if ($view === null) {
                return null;
            }
            $views[$view] = true;
        }

        return count($views) === 1 ? (string) array_key_first($views) : null;
    }

    /**
     * The literal values `config/livewire.php` gives for the keys that decide where components live.
     *
     * @return array<string, mixed>
     */
    private function readConfig(Expr\Array_ $array): array
    {
        $config = ['class_namespace' => null, 'view_path' => null, 'component_locations' => null, 'component_namespaces' => null];
        foreach ($array->items as $item) {
            if (!$item->key instanceof String_ || !array_key_exists($item->key->value, $config)) {
                continue;
            }
            $key = $item->key->value;
            $config[$key] = match ($key) {
                'class_namespace' => self::literal($item->value, false),
                'view_path' => self::literal($item->value, true),
                'component_locations' => $this->literalList($item->value),
                default => $this->literalMap($item->value),
            };
        }

        return $config;
    }

    /**
     * A string literal, or `resource_path('x')` / `base_path('x')` as the path
     * relative to the project root; anything computed is unknown.
     */
    private static function literal(Node $value, bool $path): ?string
    {
        if ($value instanceof String_) {
            return $value->value;
        }
        if (!$path || !$value instanceof Expr\FuncCall || !$value->name instanceof Name || !($value->args[0]->value ?? null) instanceof String_) {
            return null;
        }
        $literal = ltrim($value->args[0]->value->value, '/');

        return match (strtolower($value->name->toString())) {
            'resource_path' => 'resources/' . $literal,
            'base_path' => $literal,
            default => null,
        };
    }

    /**
     * An array of string literals; null when any entry is not one.
     *
     * @return list<string>|null
     */
    private function literalList(Node $value): ?array
    {
        if (!$value instanceof Expr\Array_) {
            return null;
        }
        $list = [];
        foreach ($value->items as $item) {
            $path = self::literal($item->value, true);
            if ($path !== null) {
                $list[] = $path;
            }
        }

        return $list === [] && $value->items !== [] ? null : $list;
    }

    /**
     * An array of string keys to string literals; null when any entry is not one.
     *
     * @return array<string, string>|null
     */
    private function literalMap(Node $value): ?array
    {
        if (!$value instanceof Expr\Array_) {
            return null;
        }
        $map = [];
        foreach ($value->items as $item) {
            $path = self::literal($item->value, true);
            if ($item->key instanceof String_ && $path !== null) {
                $map[$item->key->value] = $path;
            }
        }

        return $map === [] && $value->items !== [] ? null : $map;
    }

    /**
     * Sorted by line then by the remaining keys, without repeats.
     *
     * @template T of array<string, mixed>
     *
     * @param list<T> $rows
     * @param list<string> $keys
     *
     * @return list<T>
     */
    private static function unique(array $rows, array $keys): array
    {
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[implode("\0", array_map(static fn(string $key): string => (string) $row[$key], $keys))] = $row;
        }
        uasort($byKey, static function (array $a, array $b): int {
            return [$a['line'], $a['name'] ?? $a['view']] <=> [$b['line'], $b['name'] ?? $b['view']];
        });

        return array_values($byKey);
    }
}

<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * The PHP language scanner: parses files and emits contributions.
 *
 * Parses only — it never includes or executes the analysed code, which is what
 * makes scanning an untrusted repository safe. A file it cannot parse becomes a
 * diagnostic rather than failing the scan.
 */
final readonly class PhpScanner
{
    /**
     * Maximum AST nesting the recursive traversal will descend into. PHP-Parser's
     * parser is table-driven (bounded native stack), but NameResolver and the
     * fact collectors recurse with depth equal to AST depth. A ~2 MB file of
     * deeply nested expressions (`$x = ((((…))))`) would exhaust the native stack
     * — an uncatchable fatal — so anything past this cap is reported and skipped.
     * Real source nests far below this; only adversarial/generated input hits it.
     */
    private const MAX_AST_DEPTH = 1000;

    private Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * Parse the requested files and return their contributions and diagnostics.
     *
     * `$livewire` says the project requires Livewire; the Livewire-specific
     * collectors are gated on it.
     *
     * @return array<string, mixed>
     */
    public function scan(string $root, string $absolutePath, string $relativePath, bool $laravel = false, bool $symfony = false, bool $livewire = false): array
    {
        $source = file_get_contents($absolutePath);
        if ($source === false) {
            throw new UnreadableFileException(sprintf('Unable to read PHP file: %s', $relativePath));
        }

        // Of the exact buffer handed to the parser, so the core can tell facts
        // parsed from these bytes apart from facts parsed from a file that
        // changed after discovery hashed it.
        $contentHash = hash('sha256', $source);

        $errors = new Collecting();
        $ast = $this->parser->parse($source, $errors) ?? [];
        $collector = new FactCollector($relativePath);
        $laravelCollector = $laravel ? new LaravelFactCollector($relativePath) : null;
        $livewireCollector = $livewire ? new LivewireBladeFactCollector($relativePath) : null;
        $symfonyCollector = $symfony ? new SymfonyFactCollector(new FrameworkFactStore($relativePath)) : null;

        if ($ast !== [] && self::exceedsDepth($ast, self::MAX_AST_DEPTH)) {
            return [
                'owner_key' => 'knossos.php:file:' . $relativePath,
                'nodes' => [],
                'edges' => [],
                'content_hash' => $contentHash,
                'diagnostics' => [[
                    'severity' => 'warning',
                    'code' => 'PHP_AST_TOO_DEEP',
                    'message' => sprintf(
                        'AST nesting exceeds the safe depth of %d; the file was skipped to avoid a fatal.',
                        self::MAX_AST_DEPTH,
                    ),
                    'evidence' => [
                        'path' => $relativePath,
                        'start_line' => 1,
                        'end_line' => 1,
                    ],
                ]],
            ];
        }

        if ($ast !== []) {
            $resolver = new NodeTraverser();
            $resolver->addVisitor(new NameResolver($errors));
            $ast = $resolver->traverse($ast);

            $traverser = new NodeTraverser();
            $traverser->addVisitor($collector);
            if ($laravelCollector !== null) {
                $traverser->addVisitor($laravelCollector);
            }
            if ($symfonyCollector !== null) {
                $traverser->addVisitor($symfonyCollector);
            }
            if ($livewireCollector !== null) {
                $traverser->addVisitor($livewireCollector);
            }
            $traverser->traverse($ast);
        }

        $diagnostics = $collector->diagnostics();
        foreach ($errors->getErrors() as $error) {
            $start = max(1, $error->getStartLine());
            $end = max($start, $error->getEndLine());
            $diagnostics[] = [
                'severity' => 'error',
                'code' => 'PHP_PARSE_ERROR',
                'message' => $error->getRawMessage(),
                'evidence' => [
                    'path' => $relativePath,
                    'start_line' => $start,
                    'end_line' => $end,
                ],
            ];
        }

        $nodes = [...$collector->nodes(), ...($laravelCollector?->nodes() ?? []), ...($symfonyCollector?->nodes() ?? [])];
        if ($livewireCollector !== null) {
            $nodes = self::withLivewireFacts($nodes, $livewireCollector, $relativePath);
        }

        return [
            'owner_key' => 'knossos.php:file:' . $relativePath,
            'nodes' => $nodes,
            'edges' => [...$collector->edges(), ...($laravelCollector?->edges() ?? []), ...($symfonyCollector?->edges() ?? [])],
            'content_hash' => $contentHash,
            'diagnostics' => [...$diagnostics, ...($laravelCollector?->diagnostics() ?? []), ...($symfonyCollector?->diagnostics() ?? [])],
        ];
    }

    /**
     * Merge the Livewire collector's attributes into the file's module node and
     * class nodes, declaring the module as the fact collector would when the
     * file's own collector did not need one.
     *
     * @param list<array<string, mixed>> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private static function withLivewireFacts(array $nodes, LivewireBladeFactCollector $livewire, string $relativePath): array
    {
        $moduleId = 'php:module:' . $relativePath;
        $moduleAttributes = $livewire->moduleAttributes();
        $classAttributes = $livewire->classAttributes();

        $hasModule = false;
        foreach ($nodes as $index => $node) {
            $extra = $node['local_id'] === $moduleId ? $moduleAttributes : ($classAttributes[$node['local_id']] ?? []);
            $hasModule = $hasModule || $node['local_id'] === $moduleId;
            if ($extra !== []) {
                $nodes[$index]['attributes'] = (object) [...(array) $node['attributes'], ...$extra];
            }
        }
        if (!$hasModule && $moduleAttributes !== []) {
            $nodes[] = [
                'local_id' => $moduleId,
                'kind' => 'module',
                'canonical_name' => $relativePath,
                'display_name' => basename($relativePath),
                'origin' => 'ast',
                'confidence' => 'certain',
                'evidence' => ['path' => $relativePath, 'start_line' => 1, 'end_line' => 1],
                'attributes' => (object) ['executable' => true, ...$moduleAttributes],
            ];
        }

        return $nodes;
    }

    /**
     * Measure the maximum AST nesting with an explicit stack so the check itself
     * cannot recurse into the pathological tree it is guarding against.
     *
     * @param array<Node> $ast
     */
    private static function exceedsDepth(array $ast, int $cap): bool
    {
        /** @var list<array{0: Node, 1: int}> $stack */
        $stack = [];
        foreach ($ast as $node) {
            $stack[] = [$node, 1];
        }
        while ($stack !== []) {
            [$node, $depth] = array_pop($stack);
            if ($depth > $cap) {
                return true;
            }
            foreach ($node->getSubNodeNames() as $name) {
                $child = $node->$name;
                if ($child instanceof Node) {
                    $stack[] = [$child, $depth + 1];
                } elseif (is_array($child)) {
                    foreach ($child as $item) {
                        if ($item instanceof Node) {
                            $stack[] = [$item, $depth + 1];
                        }
                    }
                }
            }
        }

        return false;
    }
}

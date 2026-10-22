<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;

/** Hand-built PHP worker facts: the classes, traits, methods and edges a rule test needs, without a scan. */
final class PhpFacts
{
    /** @param array<string, mixed> $attributes */
    public static function classNode(string $name, string $path, ?string $extends = null, array $attributes = [], string $kind = 'class'): NodeFact
    {
        return self::node("php:$kind:$name", $kind, $name, $path, ['extends' => $extends] + $attributes);
    }

    /** @param array<string, mixed> $attributes */
    public static function method(string $class, string $name, string $path, array $attributes = []): NodeFact
    {
        return self::node("php:method:$class::$name", 'method', "$class::$name", $path, $attributes, $name);
    }

    public static function edge(string $kind, string $source, string $target, string $path = 'src/X.php'): EdgeFact
    {
        return new EdgeFact($kind, $source, $target, Origin::Ast, Confidence::Certain, new Evidence($path, 1, 1));
    }

    /**
     * A class with its methods and the contains edges to them, as one contribution.
     *
     * @param list<string> $methods
     * @param list<string> $traits trait names the class uses
     * @param list<NodeFact> $more
     * @param list<EdgeFact> $moreEdges
     */
    public static function contribution(string $name, string $path, ?string $extends = null, array $methods = [], array $traits = [], string $kind = 'class', array $more = [], array $moreEdges = []): ScanContribution
    {
        $nodes = [self::classNode($name, $path, $extends, kind: $kind)];
        $edges = [];
        foreach ($methods as $method) {
            $nodes[] = self::method($name, $method, $path);
            $edges[] = self::edge('contains', "php:$kind:$name", "php:method:$name::$method", $path);
        }
        foreach ($traits as $trait) {
            $edges[] = self::edge('uses_trait', "php:$kind:$name", "php:trait:$trait", $path);
        }

        return new ScanContribution($path, [...$nodes, ...$more], [...$edges, ...$moreEdges]);
    }

    /** @param array<string, mixed> $attributes */
    private static function node(string $id, string $kind, string $canonical, string $path, array $attributes, ?string $display = null): NodeFact
    {
        return new NodeFact($id, $kind, $canonical, $display ?? $canonical, Origin::Ast, Confidence::Certain, new Evidence($path, 1, 1), $attributes);
    }
}

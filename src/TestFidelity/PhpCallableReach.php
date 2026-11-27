<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

use Knossos\Classification\TestModuleRule;
use Knossos\Scanner\Protocol\ScanContribution;

/**
 * The PHP methods production hands on as callable values (G3-31): the
 * targets of `calls` edges the PHP worker marks `callable`, by method name.
 *
 * Only production counts. An edge whose source node is test code, as the
 * core's test-module convention or the worker's own mark says, is left out:
 * a test that lists `[Repo::class, 'save']` (a data provider's row, an
 * `assertIsCallable()`) hands nothing to production, and must not silence
 * the drift a double of that method shows. So is an edge whose source node
 * the contribution does not hold, as its file is then unknown.
 */
final readonly class PhpCallableReach
{
    /** A PHP method reference: `php:method:<FQCN>::<name>`, the class and the name captured. */
    private const PHP_METHOD_REFERENCE = '/^php:method:\\\\?(.+)::([^:]+)$/sD';

    /** @param array<string, list<string>> $classes lower-case method name => the lower-case classes production reaches it through */
    private function __construct(private array $classes) {}

    /**
     * Index the callable `calls` edges of a scan's contributions whose source is production code.
     *
     * @param list<ScanContribution> $contributions
     */
    public static function of(array $contributions): self
    {
        $tests = new TestModuleRule();
        $classes = [];
        foreach ($contributions as $contribution) {
            $sources = [];
            foreach ($contribution->nodes as $node) {
                $sources[$node->localId] ??= $node;
            }
            foreach ($contribution->edges as $edge) {
                $source = $sources[$edge->sourceReference] ?? null;
                if ($edge->kind !== 'calls' || ($edge->attributes['callable'] ?? false) !== true || $source === null || $tests->classify($source) !== []) {
                    continue;
                }
                if (preg_match(self::PHP_METHOD_REFERENCE, strtolower($edge->targetReference), $method) === 1) {
                    $classes[$method[2]][] = $method[1];
                }
            }
        }

        return new self($classes);
    }

    /**
     * The lower-case classes through which production hands a method of this name, in any case, on as a callable value.
     *
     * @return list<string>
     */
    public function classes(string $method): array
    {
        return $this->classes[strtolower($method)] ?? [];
    }
}

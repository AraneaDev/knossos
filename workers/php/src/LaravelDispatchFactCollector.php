<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;

/**
 * Records dispatch edges: facade static calls and the global dispatch helpers.
 *
 * Dispatching a job or event is an indirection the caller never names directly, so
 * without these edges the handler looks unreferenced and dead-code detection would
 * report it as a candidate for deletion.
 */
final readonly class LaravelDispatchFactCollector
{
    private const OBSERVED_BY = 'Illuminate\\Database\\Eloquent\\Attributes\\ObservedBy';

    public function __construct(
        private FrameworkFactStore $facts,
        private LaravelTraversalContext $context,
    ) {}

    /** Route static calls and function calls to the matching dispatch detector. */
    public function enterNode(Node $node): void
    {
        if ($node instanceof Class_) {
            $this->observedBy($node);
        } elseif ($node instanceof Expr\StaticCall) {
            $this->staticFrameworkCall($node);
        } elseif ($node instanceof Expr\FuncCall) {
            $this->functionDispatch($node);
        }
    }

    /**
     * `#[ObservedBy(PostObserver::class)]` on a model registers the observer
     * the way `Post::observe(...)` does, so it records the same edge.
     */
    private function observedBy(Class_ $node): void
    {
        if ($node->namespacedName === null) {
            return;
        }
        $model = $node->namespacedName->toString();
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (ltrim($attribute->name->toString(), '\\') !== self::OBSERVED_BY) {
                    continue;
                }
                foreach (FrameworkFactStore::classArguments($attribute->args[0]->value ?? null) as $observer) {
                    $this->facts->addEdge('observes', FrameworkFactStore::classReference($model), FrameworkFactStore::classReference($observer), $attribute, ['model' => $model, 'via' => 'ObservedBy']);
                }
            }
        }
    }

    /** Record a dispatch made through a framework facade's static call. */
    private function staticFrameworkCall(Expr\StaticCall $node): void
    {
        $source = $this->context->currentSource();
        if ($source === null || !$node->class instanceof Name || !$node->name instanceof Identifier) {
            return;
        }
        $method = strtolower($node->name->toString());
        if ($method === 'dispatch') {
            $event = FrameworkFactStore::name($node->class);
            if (!str_ends_with($event, '\\Bus') && !str_ends_with($event, '\\Event')) {
                $this->facts->addEdge('dispatches', $source, FrameworkFactStore::classReference($event), $node);
            }
        } elseif ($method === 'observe') {
            $observer = FrameworkFactStore::classArgument($node->args[0]->value ?? null);
            if ($observer !== null) {
                $this->facts->addEdge('observes', $source, FrameworkFactStore::classReference($observer), $node, ['model' => FrameworkFactStore::name($node->class)]);
            }
        }
    }
    /** Record a dispatch made through a global helper such as `dispatch()` or `event()`. */

    private function functionDispatch(Expr\FuncCall $node): void
    {
        $source = $this->context->currentSource();
        if ($source === null || !$node->name instanceof Name
            || !in_array(strtolower($node->name->toString()), ['event', 'dispatch'], true)) {
            return;
        }
        $argument = $node->args[0]->value ?? null;
        if ($argument instanceof Expr\New_ && $argument->class instanceof Name) {
            $this->facts->addEdge('dispatches', $source, FrameworkFactStore::classReference(FrameworkFactStore::name($argument->class)), $node);
        }
    }
}

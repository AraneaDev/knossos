<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

/**
 * The methods of a trait whose visibility a `use` of it changes in place
 * (`use T { load as public; }`), for the test-fidelity rules: the method's
 * own node keeps the trait's visibility, which no longer holds in the class.
 * An alias under a new name (`load as public open;`) leaves the method itself
 * as it was.
 */
final class TraitUseVisibility
{
    private function __construct() {}

    /**
     * The changed method names, as the source writes them.
     *
     * @param string $trait the used trait's fully qualified name
     * @param \Closure(Name): string $resolve how the collector names a class
     * @return list<string>
     */
    public static function changed(Stmt\TraitUse $node, string $trait, \Closure $resolve): array
    {
        $names = [];
        foreach ($node->adaptations as $adaptation) {
            if ($adaptation instanceof Stmt\TraitUseAdaptation\Alias && $adaptation->newName === null && $adaptation->newModifier !== null
                && ($adaptation->trait === null || strcasecmp($resolve($adaptation->trait), $trait) === 0)) {
                $names[] = $adaptation->method->toString();
            }
        }

        return $names;
    }
}

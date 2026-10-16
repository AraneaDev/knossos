<?php

declare(strict_types=1);

/**
 * How many dependencies a source file declares, the count the
 * max_dependency_fanout budget gates on: PHP `use` statements, Python import
 * lines and JavaScript import and re-export declarations.
 */
function dependencyFanout(string $extension, string $source): int
{
    $pattern = match ($extension) {
        'php' => '/^use\s+[^;]+;/m',
        // The bindings may span lines; a side-effect import has none at all,
        // and a re-export (`export ... from`) depends on its module the same way.
        'js' => '/^(?:import\s(?:[^;"\']*?\bfrom\s*)?|export\s[^;"\'=]*?\bfrom\s*)["\'][^"\']+["\']/m',
        'py' => '/^(?:from\s+\S+\s+import|import\s+)\s*.+$/m',
        default => null,
    };

    return $pattern === null ? 0 : (int) preg_match_all($pattern, $source);
}

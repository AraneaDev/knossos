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

    if ($extension === 'js') {
        $source = withoutJavaScriptCommentsAndTemplates($source);
    }

    return $pattern === null ? 0 : (int) preg_match_all($pattern, $source);
}

/**
 * JavaScript source with comment and template literal bodies blanked, newlines
 * kept, so an import written inside one is not counted and the `^` anchors
 * still fall on real lines. String literals are kept: an import names its
 * module in one.
 */
function withoutJavaScriptCommentsAndTemplates(string $source): string
{
    $out = '';
    $length = strlen($source);
    $state = 'code';
    for ($at = 0; $at < $length; ++$at) {
        $char = $source[$at];
        $next = $source[$at + 1] ?? '';
        if ($state === 'code') {
            if ($char === '/' && $next === '*') {
                $state = 'block';
                $out .= '  ';
                ++$at;
            } elseif ($char === '/' && $next === '/') {
                $state = 'line';
                $out .= '  ';
                ++$at;
            } elseif ($char === '`') {
                $state = 'template';
                $out .= ' ';
            } else {
                if ($char === '"' || $char === "'") {
                    $state = $char;
                }
                $out .= $char;
            }
            continue;
        }
        if ($char === "\n") {
            $out .= "\n";
            if ($state === 'line') {
                $state = 'code';
            }
            continue;
        }
        if ($state === '"' || $state === "'") {
            $out .= $char;
            if ($char === '\\') {
                $out .= $next;
                ++$at;
            } elseif ($char === $state) {
                $state = 'code';
            }
            continue;
        }
        if ($state === 'template' && $char === '\\') {
            $out .= '  ';
            ++$at;
            continue;
        }
        if (($state === 'block' && $char === '*' && $next === '/')) {
            $state = 'code';
            $out .= '  ';
            ++$at;
            continue;
        }
        if ($state === 'template' && $char === '`') {
            $state = 'code';
        }
        $out .= ' ';
    }

    return $out;
}

<?php

declare(strict_types=1);

// Every PHP file git tracks, as tools/lint-files.php derived them into
// tools/lint-files.txt, so a new tool or test is formatted from the commit that
// adds it. The static lane checks that list against `git ls-files`.
$files = [];
foreach (file(__DIR__ . '/tools/lint-files.txt', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (str_starts_with($line, "php\t")) {
        $files[] = __DIR__ . '/' . substr($line, 4);
    }
}
if ($files === []) {
    throw new RuntimeException('tools/lint-files.txt lists no PHP files; run `php tools/lint-files.php --write`.');
}

$finder = PhpCsFixer\Finder::create()->append($files);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        'array_syntax' => ['syntax' => 'short'],
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
    ])
    ->setFinder($finder);
